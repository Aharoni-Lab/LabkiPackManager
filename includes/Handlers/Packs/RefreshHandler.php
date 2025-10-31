<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Domain\PackSessionState;

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
 * - Revalidates selections against the current manifest
 * - Re-resolves all dependencies
 * - Detects page conflicts
 * - Useful after manifest updates or to synchronize state
 */
final class RefreshHandler extends BasePackHandler {

	/**
	 * @inheritDoc
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array {
		if ( !$state ) {
			throw new \RuntimeException( 'RefreshHandler: state cannot be null' );
		}

		// Re-resolve all dependencies
		$this->resolveDependencies( $state, $manifest );

		// Detect conflicts
		$warnings = $this->detectPageConflicts( $state );

		return $this->result( $state, $warnings );
	}
}
