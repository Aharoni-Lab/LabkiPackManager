<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Session\PackSessionState;

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
		$userId = $context['user_id'];
		$refId = $context['ref_id'];

		wfDebugLog( 'labkipack', "InitHandler: Initializing state for user={$userId}, ref={$refId->toInt()}" );

		// Build fresh state from manifest and installed packs
		$newState = $this->buildFreshState( $refId, $userId, $manifest );

		wfDebugLog( 'labkipack', "InitHandler: Created state with " . count( $newState->packs() ) . " packs" );

		// Return full state, no diff
		return $this->result( $newState, [], true );
	}
}
