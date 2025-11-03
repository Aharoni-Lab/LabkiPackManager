<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Services\LabkiPackRegistry;

/**
 * Handles initializing a new PackSessionState from the manifest.
 *
 * Command: "init"
 *
 * Expected payload:
 * {
 *   "command": "init",
 *   "repo_url": "https://github.com/Aharoni-Lab/labki-packs",
 *   "ref": "main",
 *   "data": {}
 * }
 *
 * Behavior:
 * - Loads manifest for given repo/ref.
 * - Creates PackSessionState containing all packs and pages from manifest.
 * - Marks any already-installed packs as selected.
 * - Returns full state (no diff) to frontend.
 */
final class InitHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	// TODO: We are passing the services in context right now. Should we do that
	// or should we just list used services in these handlers?
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		$userId   = $context['user_id'];
		$refId    = $context['ref_id'];
		$services = $context['services'];

		wfDebugLog( 'labkipack', "InitHandler: Starting with manifest keys: " . json_encode( array_keys( $manifest ) ) );

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

		wfDebugLog( 'labkipack', "InitHandler: Installed packs: " . json_encode( array_keys( $installedMap ) ) );

		// Build pack states from manifest
		// The manifest structure has packs nested under manifest.manifest.packs
		$manifestData = $manifest['manifest'] ?? $manifest;
		$manifestPacks = $manifestData['packs'] ?? [];
		wfDebugLog( 'labkipack', "InitHandler: Manifest packs: " . json_encode( array_keys( $manifestPacks ) ) );
		
		$packs = [];
		foreach ( $manifestPacks as $packName => $packDef ) {
			wfDebugLog( 'labkipack', "InitHandler: Processing pack: " . $packName );
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

		wfDebugLog( 'labkipack', "InitHandler: Built " . count( $packs ) . " packs, data: " . json_encode( $packs ) );

		// Create new session state
		$newState = new PackSessionState( $refId, $userId, $packs );

		wfDebugLog( 'labkipack', "InitHandler: New state has " . count( $newState->packs() ) . " packs" );

		// Return full state, no diff
		return $this->result( $newState, [], true );
	}
}
