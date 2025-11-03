<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use MediaWiki\Title\Title;
use LabkiPackManager\Domain\PackSessionState;
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
