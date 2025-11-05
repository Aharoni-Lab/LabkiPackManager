<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Session\PackSessionState;

/**
 * Handles refreshing/revalidating the session state.
 *
 * Command: "refresh"
 *
 * Expected payload:
 * {
 *   "command": "refresh",
 *   "repo_url": "...",
 *   "ref": "...",
 *   "data": {}
 * }
 *
 * Behavior:
 * - Rebuilds state from current manifest and installed packs
 * - Useful after manifest updates or to synchronize state
 * - Same as init/clear - creates fresh state
 */
final class RefreshHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		$userId = $context['user_id'];
		$refId = $context['ref_id'];

		wfDebugLog( 'labkipack', "RefreshHandler: Refreshing state for user={$userId}, ref={$refId->toInt()}" );

		// Build fresh state from manifest and installed packs (same as Init/Clear)
		$newState = $this->buildFreshState( $refId, $userId, $manifest );

		wfDebugLog( 'labkipack', "RefreshHandler: Rebuilt state with " . count( $newState->packs() ) . " packs" );

		// Persist the refreshed state
		return $this->result( $newState, [], true );
	}
}
