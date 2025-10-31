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
 * - Clears all session state for the current user and ref
 * - Returns a new empty state (not persisted, save=false)
 */
final class ClearHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		$userId = $context['user_id'];
		$refId = $context['ref_id'];

		// Clear the state from storage
		$this->stateStore->clear( $userId, $refId );

		// Create a new empty state to return (not persisted)
		$newState = new PackSessionState( $refId, $userId, [] );

		// Don't persist, just return the cleared state
		return [
			'state'    => $newState,
			'warnings' => [],
			'save'     => false,
		];
	}
}
