<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use MediaWiki\Title\Title;
use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Services\DependencyResolver;
use LabkiPackManager\Services\PackStateStore;

/**
 * Base class for all PackCommandHandlers.
 *
 * Provides common utilities such as dependency resolution, conflict detection,
 * and normalized response building. All handlers should extend this.
 */
abstract class BasePackHandler implements PackCommandHandler {

	protected DependencyResolver $resolver;
	protected PackStateStore $stateStore;

	public function __construct( ?DependencyResolver $resolver = null, ?PackStateStore $stateStore = null ) {
		$this->resolver = $resolver ?? new DependencyResolver();
		$this->stateStore = $stateStore ?? new PackStateStore();
	}

	/**
	 * Resolve dependencies and auto-select required packs.
	 *
	 * @param PackSessionState $state
	 * @param array $manifest
	 * @return array List of packs that were auto-selected
	 */
	protected function resolveDependencies( PackSessionState $state, array $manifest ): array {
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];
		$selectedPacks = $state->getSelectedPackNames();
		$autoSelected = [];

		// Clear previous auto-selections
		foreach ( $state->packs() as $packName => $packState ) {
			if ( $packState['auto_selected'] ?? false ) {
				$state->deselectPack( $packName );
			}
		}

		$queue = $selectedPacks;
		$processed = [];

		while ( $queue ) {
			$packName = array_shift( $queue );
			if ( isset( $processed[$packName] ) ) {
				continue;
			}
			$processed[$packName] = true;

			$deps = $manifestPacks[$packName]['depends_on'] ?? [];
			foreach ( $deps as $depName ) {
				if ( in_array( $depName, $selectedPacks, true ) ) {
					continue;
				}
				$pack = $state->getPack( $depName );
				if ( $pack && !( $pack['selected'] ?? false ) ) {
					$state->autoSelectPack( $depName, "Required by {$packName}" );
					$autoSelected[] = $depName;
					$queue[] = $depName;
				}
			}
		}
		return $autoSelected;
	}

	/**
	 * Detect page title conflicts with existing wiki pages.
	 *
	 * @param PackSessionState $state
	 * @return array Array of warning strings
	 */
	protected function detectPageConflicts( PackSessionState $state ): array {
		$warnings = [];
		foreach ( $state->packs() as $packName => $packState ) {
			$isSelected = ( $packState['selected'] ?? false ) || ( $packState['auto_selected'] ?? false );
			if ( !$isSelected ) {
				continue;
			}
			foreach ( $packState['pages'] ?? [] as $pageName => $pageState ) {
				$titleText = $pageState['final_title'] ?? '';
				if ( $titleText === '' ) {
					continue;
				}
				$title = Title::newFromText( $titleText );
				if ( $title && $title->exists() ) {
					$warnings[] = "Page '{$titleText}' already exists (pack: {$packName}, page: {$pageName})";
				}
			}
		}
		return $warnings;
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
