<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;

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

		wfDebugLog( 'labkipack', "ClearHandler: Clearing state for user={$userId}, ref={$refId->toInt()}" );

		// Build fresh state from manifest and installed packs (same as InitHandler)
		$newState = $this->buildFreshState( $refId, $userId, $manifest );

		wfDebugLog( 'labkipack', "ClearHandler: Cleared and rebuilt state with " . count( $newState->packs() ) . " packs" );

		// Persist this cleared/reset state
		return $this->result( $newState, [], true );
	}
}
