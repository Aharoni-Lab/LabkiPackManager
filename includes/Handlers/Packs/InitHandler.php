<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;

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
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		$userId   = $context['user_id'];
		$refId    = $context['ref_id'];
		$services = $context['services'];

		$packRegistry = $services->getService( 'LabkiPackManager.PackRegistry' );
		if ( !$packRegistry ) {
			throw new \RuntimeException( 'InitHandler: PackRegistry service not found' );
		}

		// Get installed packs for this ref
		$installed = $packRegistry->listPacksByRef( $refId );
		$installedMap = [];
		foreach ( $installed as $p ) {
			$installedMap[$p->name()] = $p;
		}

		// Build pack states from manifest
		$manifestPacks = $manifest['packs'] ?? [];
		$packs = [];
		foreach ( $manifestPacks as $packName => $packDef ) {
			$currentVersion = isset( $installedMap[$packName] )
				? $installedMap[$packName]->version()
				: null;

			$packs[$packName] = PackSessionState::createPackState(
				$packName,
				$packDef,
				$currentVersion
			);
		}

		// Create new session state
		$newState = new PackSessionState( $refId, $userId, $packs );

		// Return full state, no diff
		return $this->result( $newState, [], true );
	}
}
