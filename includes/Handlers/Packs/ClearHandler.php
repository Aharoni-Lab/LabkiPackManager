<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Services\LabkiPackRegistry;

/**
 * Handles clearing session state.
 *
 * Command: "clear"
 *
 * Expected payload:
 * {
 *   "command": "clear",
 *   "repo_url": "...",
 *   "ref": "...",
 *   "data": {}
 * }
 *
 * Behavior:
 * - Resets session state to the initial state based on currently installed packs
 * - Returns a state reflecting what's currently installed in MediaWiki
 */
final class ClearHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		$userId = $context['user_id'];
		$refId = $context['ref_id'];

		$packRegistry = new LabkiPackRegistry();
		$pageRegistry = new \LabkiPackManager\Services\LabkiPageRegistry();
		
		// Get installed packs for this ref
		$installed = $packRegistry->listPacksByRef( $refId );
		$installedMap = [];
		$installedPagesMap = [];
		
		foreach ( $installed as $p ) {
			$installedMap[$p->name()] = $p;
			
			// Load installed pages for this pack
			$pages = $pageRegistry->listPagesByPack( $p->id() );
			$pageNames = array_map( fn( $page ) => $page->name(), $pages );
			$installedPagesMap[$p->name()] = $pageNames;
		}

		// Build pack states from manifest - same as InitHandler
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];
		
		$packs = [];
		foreach ( $manifestPacks as $packName => $packDef ) {
			$currentVersion = isset( $installedMap[$packName] )
				? $installedMap[$packName]->version()
				: null;
			$installedPageNames = $installedPagesMap[$packName] ?? [];

			$packs[$packName] = PackSessionState::createPackState(
				$packName,
				$packDef,
				$currentVersion,
				$installedPageNames
			);
		}

		// Create new session state based on what's currently installed
		$newState = new PackSessionState( $refId, $userId, $packs );

		// Persist this cleared/reset state
		return $this->result( $newState, [], true );
	}
}
