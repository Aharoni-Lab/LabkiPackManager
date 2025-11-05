<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Session\PackSessionState;

/**
 * Handles setting a pack's prefix.
 *
 * Command: "set_pack_prefix"
 *
 * Expected payload:
 * {
 *   "command": "set_pack_prefix",
 *   "repo_url": "...",
 *   "ref": "...",
 *   "data": {
 *     "pack_name": "example_pack",
 *     "prefix": "NewPrefix"
 *   }
 * }
 *
 * Behavior:
 * - Updates the pack's prefix
 * - Automatically recomputes all page titles for the pack
 * - Detects conflicts with new titles
 * - Returns diff of changes
 */
final class SetPackPrefixHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		if ( !$state ) {
			throw new \RuntimeException( 'SetPackPrefixHandler: state cannot be null' );
		}

		$packName = $data['pack_name'] ?? null;
		$prefix = $data['prefix'] ?? null;

		if ( !$packName || !is_string( $packName ) ) {
			throw new \InvalidArgumentException( 'SetPackPrefixHandler: invalid or missing pack_name' );
		}

		if ( $prefix === null ) {
			throw new \InvalidArgumentException( 'SetPackPrefixHandler: prefix is required' );
		}

		if ( !is_string( $prefix ) ) {
			throw new \InvalidArgumentException( 'SetPackPrefixHandler: prefix must be a string' );
		}

		// Validate pack exists
		if ( !$state->hasPack( $packName ) ) {
			throw new \InvalidArgumentException(
				"SetPackPrefixHandler: pack '{$packName}' not found in state"
			);
		}

		// Update pack prefix (this recomputes page titles automatically)
		$state->setPackPrefix( $packName, $prefix );

		// Detect conflicts with new titles
		$warnings = $this->detectPageConflicts( $state );

		return $this->result( $state, $warnings );
	}
}
