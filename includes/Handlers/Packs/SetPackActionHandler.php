<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;

/**
 * Handles setting the action for a pack.
 *
 * Command: "set_pack_action"
 *
 * Expected payload:
 * {
 *   "command": "set_pack_action",
 *   "repo_url": "...",
 *   "ref": "...",
 *   "data": {
 *     "pack_name": "example_pack",
 *     "action": "install|update|remove|unchanged"
 *   }
 * }
 *
 * Behavior:
 * - Explicitly sets the action field for a pack
 * - Also updates selected/auto_selected flags accordingly
 * - Resolves dependencies for install/update actions
 * - Detects page conflicts after action change
 */
final class SetPackActionHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		if ( !$state ) {
			throw new \RuntimeException( 'SetPackActionHandler: state cannot be null' );
		}

		$packName = $data['pack_name'] ?? null;
		$action = $data['action'] ?? null;

		if ( !$packName || !is_string( $packName ) ) {
			throw new \InvalidArgumentException( 'SetPackActionHandler: invalid or missing pack_name' );
		}

		if ( !$action || !is_string( $action ) ) {
			throw new \InvalidArgumentException( 'SetPackActionHandler: invalid or missing action' );
		}

		// Validate action value
		$validActions = [ 'install', 'update', 'remove', 'unchanged' ];
		if ( !in_array( $action, $validActions, true ) ) {
			throw new \InvalidArgumentException( "SetPackActionHandler: invalid action '{$action}'. Must be one of: " . implode( ', ', $validActions ) );
		}

		// Verify pack exists in manifest
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];
		if ( !isset( $manifestPacks[$packName] ) ) {
			throw new \InvalidArgumentException( "SetPackActionHandler: pack '{$packName}' not found in manifest" );
		}

		// Get pack state
		$packState = $state->getPack( $packName );
		if ( !$packState ) {
			throw new \InvalidArgumentException( "SetPackActionHandler: pack '{$packName}' not found in state" );
		}

		// Validate action makes sense for pack's installation status
		$currentVersion = $packState['current_version'] ?? null;
		$targetVersion = $packState['target_version'] ?? null;

		// Validate the action is appropriate
		if ( $action === 'install' && $currentVersion !== null ) {
			throw new \InvalidArgumentException( "SetPackActionHandler: cannot install '{$packName}' - already installed (version {$currentVersion})" );
		}
		if ( $action === 'update' && $currentVersion === null ) {
			throw new \InvalidArgumentException( "SetPackActionHandler: cannot update '{$packName}' - not installed" );
		}
		if ( $action === 'update' && $currentVersion === $targetVersion ) {
			throw new \InvalidArgumentException( "SetPackActionHandler: cannot update '{$packName}' - already at target version ({$targetVersion})" );
		}
		if ( $action === 'remove' && $currentVersion === null ) {
			throw new \InvalidArgumentException( "SetPackActionHandler: cannot remove '{$packName}' - not installed" );
		}

		// For remove/update actions on installed packs, check if other installed packs depend on it
		if ( ( $action === 'remove' || $action === 'update' ) && $currentVersion !== null ) {
			$dependents = $this->findInstalledPacksDependingOn( $state, $manifest, $packName );
			if ( !empty( $dependents ) ) {
				$actionVerb = $action === 'remove' ? 'remove' : 'update';
				throw new \RuntimeException(
					"Cannot {$actionVerb} '{$packName}' - required by installed packs: " . 
					implode( ', ', $dependents ) . ". Please {$actionVerb} those packs first."
				);
			}
		}

		// Update pack state based on action (manual action, no auto reason)
		$state->setPackAction( $packName, $action, null );

		$warnings = [];

		// Handle dependency propagation and validation based on action
		if ( $action === 'install' || $action === 'update' ) {
			// Propagate action DOWN to dependencies
			$this->propagateActionToDependencies( $state, $manifest, $packName, $action );
			$warnings = $this->detectPageConflicts( $state );
		} elseif ( $action === 'remove' ) {
			// Propagate removal DOWN to dependencies (if safe)
			$this->propagateRemovalToDependencies( $state, $manifest, $packName );
		} elseif ( $action === 'unchanged' ) {
			// Check if other packs depend on this one
			$dependents = $this->findPacksDependingOn( $state, $manifest, $packName );
			if ( !empty( $dependents ) ) {
				throw new \RuntimeException(
					"Cannot set '{$packName}' to unchanged - required by: " . implode( ', ', $dependents ) .
					". Please clear those packs first."
				);
			}
			// Clear any auto-actioned dependencies that are no longer needed
			$this->clearUnneededAutoActions( $state, $manifest );
		}

		return $this->result( $state, $warnings );
	}
}

