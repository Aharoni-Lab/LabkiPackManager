<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use MediaWiki\Title\Title;
use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\PackStateStore;

/**
 * Base class for all PackCommandHandlers.
 *
 * Provides common utilities such as dependency propagation, conflict detection,
 * and normalized response building. All handlers should extend this.
 */
abstract class BasePackHandler implements PackCommandHandler {

	protected PackStateStore $stateStore;

	public function __construct( ?PackStateStore $stateStore = null ) {
		$this->stateStore = $stateStore ?? new PackStateStore();
	}

	/**
	 * Build fresh pack state from manifest and installed packs.
	 * Common logic for InitHandler and ClearHandler.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param int $userId User ID
	 * @param array $manifest Manifest data
	 * @return PackSessionState New state with installed packs loaded
	 */
	protected function buildFreshState( $refId, int $userId, array $manifest ): PackSessionState {
		$packRegistry = new \LabkiPackManager\Services\LabkiPackRegistry();
		$pageRegistry = new \LabkiPackManager\Services\LabkiPageRegistry();
		
		// Get installed packs for this ref
		$installed = $packRegistry->listPacksByRef( $refId );
		$installedMap = [];
		$installedPagesMap = [];
		
		foreach ( $installed as $p ) {
			$installedMap[$p->name()] = $p;
			
			// Load installed pages for this pack with their final titles
			$pages = $pageRegistry->listPagesByPack( $p->id() );
			$pageDataMap = [];
			foreach ( $pages as $page ) {
				$pageDataMap[$page->name()] = $page->finalTitle();
			}
			$installedPagesMap[$p->name()] = $pageDataMap;
		}

		wfDebugLog( 'labkipack', "buildFreshState: Installed packs: " . json_encode( array_keys( $installedMap ) ) );

		// Build pack states from manifest
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];
		wfDebugLog( 'labkipack', "buildFreshState: Manifest packs: " . json_encode( array_keys( $manifestPacks ) ) );
		
		$packs = [];
		foreach ( $manifestPacks as $packName => $packDef ) {
			$currentVersion = isset( $installedMap[$packName] )
				? $installedMap[$packName]->version()
				: null;
			$installedPages = $installedPagesMap[$packName] ?? [];

			$packs[$packName] = PackSessionState::createPackState(
				$packName,
				$packDef,
				$currentVersion,
				$installedPages
			);
		}

		wfDebugLog( 'labkipack', "buildFreshState: Built " . count( $packs ) . " packs" );

		// Create new session state
		return new PackSessionState( $refId, $userId, $packs );
	}

	/**
	 * Detect page title conflicts with existing wiki pages.
	 *
	 * @param PackSessionState $state
	 * @return array Array of warning strings
	 */
	protected function detectPageConflicts( PackSessionState $state ): array {
		$warnings = [];
		$allTitles = [];

		foreach ( $state->packs() as $packName => $packState ) {
			// Only check conflicts for packs with install/update actions
			$action = $packState['action'] ?? 'unchanged';
			if ( $action !== 'install' && $action !== 'update' ) {
				continue;
			}

			foreach ( $packState['pages'] ?? [] as $pageName => $pageState ) {
				$titleText = $pageState['final_title'];
				if ( $titleText === '' ) {
					continue;
				}

				// Skip collision check for pages that are already installed
				// They will always "collide" with themselves since they exist in the wiki
				$isInstalled = $pageState['installed'] ?? false;
				if ( $isInstalled ) {
					continue;
				}

				// Check for collisions with existing MediaWiki pages
				$title = Title::newFromText( $titleText );
				if ( $title && $title->exists() ) {
					$warnings[] = "Page '{$titleText}' already exists (pack: {$packName}, page: {$pageName})";
				}

				// Check for collisions within the selected packs themselves
				if ( isset( $allTitles[$titleText] ) ) {
					$warnings[] = "Page title collision: '{$titleText}' is used by both " .
						"{$allTitles[$titleText]} and {$packName}/{$pageName}";
				} else {
					$allTitles[$titleText] = "{$packName}/{$pageName}";
				}
			}
		}

		return $warnings;
	}

	/**
	 * Propagate action to dependencies (install/update).
	 * Recursively sets action on all dependencies of a pack.
	 *
	 * @param PackSessionState $state
	 * @param array $manifest
	 * @param string $packName Pack that was actioned
	 * @param string $action Action to propagate (install or update)
	 */
	protected function propagateActionToDependencies( 
		PackSessionState $state, 
		array $manifest, 
		string $packName, 
		string $action 
	): void {
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];

		// Get dependencies for this pack
		$dependencies = $manifestPacks[$packName]['depends_on'] ?? [];
		
		foreach ( $dependencies as $depName ) {
			if ( !isset( $manifestPacks[$depName] ) ) {
				continue;
			}

			$depPack = $state->getPack( $depName );
			if ( !$depPack ) {
				continue;
			}

			// Check if dependency already has a manual action set
			$depAutoReason = $depPack['auto_selected_reason'] ?? null;
			$depAction = $depPack['action'] ?? 'unchanged';

			// Don't override manual actions
			if ( $depAction !== 'unchanged' && $depAutoReason === null ) {
				continue;
			}

			// Determine appropriate action for dependency
			$depCurrentVersion = $depPack['current_version'] ?? null;
			$depTargetVersion = $depPack['target_version'] ?? null;
			
			$depActionToSet = 'unchanged';
			if ( $depCurrentVersion === null ) {
				$depActionToSet = 'install';
			} elseif ( $depCurrentVersion !== $depTargetVersion ) {
				$depActionToSet = 'update';
			}

			// Set the action with auto reason
			if ( $depActionToSet !== 'unchanged' ) {
				$state->setPackAction( $depName, $depActionToSet, "Required by {$packName}" );
				
				// Recursively propagate to nested dependencies
				$this->propagateActionToDependencies( $state, $manifest, $depName, $depActionToSet );
			}
		}
	}

	/**
	 * Find packs that depend on the given pack and have actions set.
	 *
	 * @param PackSessionState $state
	 * @param array $manifest
	 * @param string $packName Pack to check dependents for
	 * @return array Array of dependent pack names
	 */
	protected function findPacksDependingOn( PackSessionState $state, array $manifest, string $packName ): array {
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];
		
		$dependents = [];
		foreach ( $state->getPacksWithActions() as $actionedPack ) {
			if ( $actionedPack === $packName ) {
				continue;
			}

			$dependencies = $manifestPacks[$actionedPack]['depends_on'] ?? [];
			if ( in_array( $packName, $dependencies, true ) ) {
				$dependents[] = $actionedPack;
			}
		}

		return $dependents;
	}

	/**
	 * Find installed packs that depend on the given pack and are NOT marked for removal/update.
	 * Used to prevent removing/updating a pack that's still needed.
	 *
	 * @param PackSessionState $state
	 * @param array $manifest
	 * @param string $packName Pack to check dependents for
	 * @return array Array of dependent pack names
	 */
	protected function findInstalledPacksDependingOn( PackSessionState $state, array $manifest, string $packName ): array {
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];
		
		$dependents = [];
		foreach ( $state->packs() as $otherPackName => $otherPackState ) {
			if ( $otherPackName === $packName ) {
				continue;
			}

			// Only check installed packs
			$otherInstalled = $otherPackState['installed'] ?? false;
			if ( !$otherInstalled ) {
				continue;
			}

			// Skip packs that are already marked for removal or update
			$otherAction = $otherPackState['action'] ?? 'unchanged';
			if ( $otherAction === 'remove' || $otherAction === 'update' ) {
				continue;
			}

			// Check if this pack depends on the target pack
			$dependencies = $manifestPacks[$otherPackName]['depends_on'] ?? [];
			if ( in_array( $packName, $dependencies, true ) ) {
				$dependents[] = $otherPackName;
			}
		}

		return $dependents;
	}

	/**
	 * Propagate removal action to dependents (packs that depend on this pack).
	 * When removing a pack, also remove any packs that depend on it to prevent orphans.
	 * This propagates UPWARD in the dependency tree, not downward.
	 *
	 * @param PackSessionState $state
	 * @param array $manifest
	 * @param string $packName Pack being removed
	 */
	protected function propagateRemovalToDependencies( 
		PackSessionState $state, 
		array $manifest, 
		string $packName 
	): void {
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];

		// Find all packs that depend on this pack
		foreach ( $manifestPacks as $otherPackName => $otherPackDef ) {
			if ( $otherPackName === $packName ) {
				continue;
			}

			$dependencies = $otherPackDef['depends_on'] ?? [];
			
			// Check if this pack depends on the pack being removed
			if ( !in_array( $packName, $dependencies, true ) ) {
				continue;
			}

			$otherPack = $state->getPack( $otherPackName );
			if ( !$otherPack ) {
				continue;
			}

			// Only auto-remove if the dependent is installed
			$otherInstalled = $otherPack['installed'] ?? false;
			if ( !$otherInstalled ) {
				continue;
			}

			// Don't override manual actions
			$otherAutoReason = $otherPack['auto_selected_reason'] ?? null;
			$otherAction = $otherPack['action'] ?? 'unchanged';
			if ( $otherAction !== 'unchanged' && $otherAutoReason === null ) {
				continue;
			}

			// Auto-remove this dependent pack (it would be orphaned)
			$state->setPackAction( $otherPackName, 'remove', "Depends on {$packName} which is being removed" );
			
			// Recursively propagate to packs that depend on THIS pack
			$this->propagateRemovalToDependencies( $state, $manifest, $otherPackName );
		}
	}

	/**
	 * Clear auto-actioned dependencies that are no longer needed.
	 *
	 * @param PackSessionState $state
	 * @param array $manifest
	 */
	protected function clearUnneededAutoActions( PackSessionState $state, array $manifest ): void {
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];

		// Get all manually actioned packs
		$manualPacks = $state->getManuallyActionedPackNames();
		
		// Build set of all required dependencies
		$requiredDeps = [];
		foreach ( $manualPacks as $manualPack ) {
			$this->collectAllDependencies( $manifestPacks, $manualPack, $requiredDeps );
		}

		// Clear auto-actioned packs that are not in required set
		foreach ( $state->getAutoActionedPackNames() as $autoPack ) {
			if ( !in_array( $autoPack, $requiredDeps, true ) ) {
				$state->setPackAction( $autoPack, 'unchanged', null );
			}
		}
	}

	/**
	 * Recursively collect all dependencies for a pack.
	 *
	 * @param array $manifestPacks Pack definitions from manifest
	 * @param string $packName Pack to collect dependencies for
	 * @param array &$collected Reference to collected dependencies
	 */
	private function collectAllDependencies( array $manifestPacks, string $packName, array &$collected ): void {
		$dependencies = $manifestPacks[$packName]['depends_on'] ?? [];
		
		foreach ( $dependencies as $depName ) {
			if ( in_array( $depName, $collected, true ) ) {
				continue;
			}
			
			$collected[] = $depName;
			
			// Recursively collect nested dependencies
			if ( isset( $manifestPacks[$depName] ) ) {
				$this->collectAllDependencies( $manifestPacks, $depName, $collected );
			}
		}
	}

	/**
	 * Convenience method for handlers to build uniform result arrays.
	 *
	 * @param PackSessionState $state
	 * @param array $warnings
	 * @param bool $save Whether to persist the state
	 * @return array
	 */
	protected function result( PackSessionState $state, array $warnings = [], bool $save = true ): array {
		return [
			'state'    => $state,
			'warnings' => $warnings,
			'save'     => $save,
		];
	}
}
