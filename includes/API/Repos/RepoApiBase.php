<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Repos;

use LabkiPackManager\API\LabkiApiBase;

/**
 * Abstract base for repository-related API modules.
 *
 * Extends LabkiApiBase with repository-specific functionality:
 *
 * Subclasses must implement execute().
 *
 * @ingroup API
 */
abstract class RepoApiBase extends LabkiApiBase {

}
