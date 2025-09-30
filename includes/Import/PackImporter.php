<?php

declare(strict_types=1);

namespace LabkiPackManager\Import;

/**
 * Writes/updates pages only. I/O layer for imports (Phase 5 will implement).
 */
final class PackImporter {
	public function __construct() {}

	/**
	 * Placeholder import function. Will be implemented in later phases.
	 * @param array $pagesToImport
	 * @return array{created:int, updated:int}
	 */
	public function import( array $pagesToImport ): array {
		return [ 'created' => 0, 'updated' => 0 ];
	}
}


