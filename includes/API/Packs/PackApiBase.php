<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use LabkiPackManager\API\LabkiApiBase;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;

/**
 * Base class for pack-related API endpoints.
 *
 * Extends LabkiApiBase with pack-specific functionality:
 * - Pack and page registry accessors
 * - Repository and ref registry accessors
 * - Identifier resolution helpers (repo, ref, pack)
 *
 * Subclasses must implement execute().
 *
 * @ingroup API
 */
abstract class PackApiBase extends LabkiApiBase {

}
