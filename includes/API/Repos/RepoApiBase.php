<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Repos;

use LabkiPackManager\API\LabkiApiBase;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;

/**
 * Abstract base for repository-related API modules.
 *
 * Extends LabkiApiBase with repository-specific functionality:
 * - Repository and ref registry accessors
 * - Repository management helpers
 *
 * Subclasses must implement execute().
 *
 * @ingroup API
 */
abstract class RepoApiBase extends LabkiApiBase {

	/**
	 * Get the repository registry.
	 *
	 * @return LabkiRepoRegistry
	 */
	protected function getRepoRegistry(): LabkiRepoRegistry {
		return new LabkiRepoRegistry();
	}

	/**
	 * Get the ref registry.
	 *
	 * @return LabkiRefRegistry
	 */
	protected function getRefRegistry(): LabkiRefRegistry {
		return new LabkiRefRegistry();
	}
}
