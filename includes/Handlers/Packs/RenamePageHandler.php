<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;

/**
 * Handles renaming a page within a pack.
 *
 * Command: "rename_page"
 *
 * Expected payload:
 * {
 *   "command": "rename_page",
 *   "repo_url": "...",
 *   "ref": "...",
 *   "data": {
 *     "pack_name": "example_pack",
 *     "page_name": "example_page",
 *     "new_title": "Custom/Page/Title"
 *   }
 * }
 *
 * Behavior:
 * - Updates the final_title for a specific page
 * - Detects conflicts with the new title
 * - Returns diff of changes
 */
final class RenamePageHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		if ( !$state ) {
			throw new \RuntimeException( 'RenamePageHandler: state cannot be null' );
		}

		$packName = $data['pack_name'] ?? null;
		$pageName = $data['page_name'] ?? null;
		$newTitle = $data['new_title'] ?? null;

		if ( !$packName || !is_string( $packName ) ) {
			throw new \InvalidArgumentException( 'RenamePageHandler: invalid or missing pack_name' );
		}

		if ( !$pageName || !is_string( $pageName ) ) {
			throw new \InvalidArgumentException( 'RenamePageHandler: invalid or missing page_name' );
		}

		if ( $newTitle === null ) {
			throw new \InvalidArgumentException( 'RenamePageHandler: new_title is required' );
		}

		if ( !is_string( $newTitle ) ) {
			throw new \InvalidArgumentException( 'RenamePageHandler: new_title must be a string' );
		}

		// Validate pack and page exist
		$pack = $state->getPack( $packName );
		if ( !$pack || !isset( $pack['pages'][$pageName] ) ) {
			throw new \InvalidArgumentException(
				"RenamePageHandler: page '{$pageName}' not found in pack '{$packName}'"
			);
		}

		// Update page title
		$state->setPageFinalTitle( $packName, $pageName, $newTitle );

		// Detect conflicts with new title
		$warnings = $this->detectPageConflicts( $state );

		return $this->result( $state, $warnings );
	}
}
