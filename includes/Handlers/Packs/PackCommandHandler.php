<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

use LabkiPackManager\Session\PackSessionState;

/**
 * Common interface for all pack command handlers.
 *
 * Every handler receives:
 *  - The current PackSessionState (or null for init)
 *  - The manifest array for the repo/ref
 *  - The `data` portion of the payload (arbitrary)
 *  - A context array containing:
 *        user_id, repo_url, ref, repo_id, ref_id, services
 *
 * It must return an array:
 *  [
 *    'state'    => PackSessionState,   // the authoritative state after mutation
 *    'warnings' => [ ... ],            // optional array of warning strings
 *    'save'     => bool                // optional flag, default true
 *  ]
 *
 * Implementations should NOT output directly or handle I/O.
 */
interface PackCommandHandler {
	/**
	 * Execute the command logic.
	 *
	 * @param ?PackSessionState $state  Current session state, or null if none exists
	 * @param array $manifest            Manifest array for the repo/ref
	 * @param array $data                The payload "data" section
	 * @param array $context             Contextual info (user_id, repo_url, etc.)
	 * @return array Result array (see above)
	 */
	public function handle( ?PackSessionState $state, array $manifest, array $data, array $context ): array;
}
