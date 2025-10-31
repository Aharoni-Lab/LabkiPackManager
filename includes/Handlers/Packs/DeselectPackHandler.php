<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;

/**
 * Handles user deselecting a pack.
 *
 * Command: "deselect_pack"
 *
 * Expected payload:
 * {
 *   "command": "deselect_pack",
 *   "repo_url": "...",
 *   "ref": "...",
 *   "data": {
 *     "pack_name": "example_pack",
 *     "cascade": false
 *   }
 * }
 *
 * Behavior:
 * - Deselects the specified pack
 * - Checks if other selected packs depend on it
 * - If dependents exist and cascade=false, throws error
 * - If cascade=true, deselects dependents recursively
 * - Re-resolves dependencies for remaining selections
 * - Detects page conflicts
 */
final class DeselectPackHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		if ( !$state ) {
			throw new \RuntimeException( 'DeselectPackHandler: state cannot be null' );
		}

		$packName = $data['pack_name'] ?? null;
		$cascade = $data['cascade'] ?? false;

		if ( !$packName || !is_string( $packName ) ) {
			throw new \InvalidArgumentException( 'DeselectPackHandler: invalid or missing pack_name' );
		}

		if ( !is_bool( $cascade ) ) {
			throw new \InvalidArgumentException( 'DeselectPackHandler: cascade must be boolean' );
		}

		// Validate pack exists
		if ( !$state->hasPack( $packName ) ) {
			throw new \InvalidArgumentException( "DeselectPackHandler: pack '{$packName}' not found in state" );
		}

		$manifestPacks = $manifest['packs'] ?? [];

		// Check if other selected packs depend on this one
		$dependents = [];
		foreach ( $state->getSelectedPackNames() as $selectedPack ) {
			if ( $selectedPack === $packName ) {
				continue;
			}

			$dependencies = $manifestPacks[$selectedPack]['depends_on'] ?? [];
			if ( in_array( $packName, $dependencies, true ) ) {
				$dependents[] = $selectedPack;
			}
		}

		// Handle dependents
		$cascadeDeselected = [];
		if ( !empty( $dependents ) && !$cascade ) {
			// Error: cannot deselect without cascade
			throw new \RuntimeException(
				"Cannot deselect pack '{$packName}' without cascade. " .
				"Dependents: " . implode( ', ', $dependents )
			);
		} elseif ( !empty( $dependents ) && $cascade ) {
			// Cascade deselect dependents
			foreach ( $dependents as $dependent ) {
				$state->deselectPack( $dependent );
				$cascadeDeselected[] = $dependent;
			}
		}

		// Deselect the pack
		$state->deselectPack( $packName );

		// Re-resolve dependencies for remaining selections
		$this->resolveDependencies( $state, $manifest );

		// Detect conflicts
		$warnings = $this->detectPageConflicts( $state );

		// Add info about cascaded deselections to warnings if any
		if ( !empty( $cascadeDeselected ) ) {
			$warnings[] = "Cascade deselected: " . implode( ', ', $cascadeDeselected );
		}

		return $this->result( $state, $warnings );
	}
}
