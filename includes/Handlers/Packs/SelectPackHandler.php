<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;

/**
 * Handles user selecting a pack.
 *
 * Command: "select_pack"
 *
 * Expected payload:
 * {
 *   "command": "select_pack",
 *   "repo_url": "...",
 *   "ref": "...",
 *   "data": { "pack_name": "example_pack" }
 * }
 */
final class SelectPackHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		if ( !$state ) {
			throw new \RuntimeException( 'SelectPackHandler: state cannot be null' );
		}

		$packName = $data['pack_name'] ?? null;
		if ( !$packName || !is_string( $packName ) ) {
			throw new \InvalidArgumentException( 'SelectPackHandler: invalid or missing pack_name' );
		}

		// Capture old state for comparison if needed (handled at API level)
		$manifestPacks = $manifest['packs'] ?? [];
		if ( !isset( $manifestPacks[$packName] ) ) {
			throw new \InvalidArgumentException( "SelectPackHandler: pack '{$packName}' not found in manifest" );
		}

		// Select pack
		$state->selectPack( $packName );

		// Resolve dependencies and conflicts
		$this->resolveDependencies( $state, $manifest );
		$warnings = $this->detectPageConflicts( $state );

		return $this->result( $state, $warnings );
	}
}
