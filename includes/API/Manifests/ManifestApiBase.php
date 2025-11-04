<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Manifests;

use LabkiPackManager\API\LabkiApiBase;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\ManifestStore;
use MediaWiki\Status\Status;

/**
 * Base class for all Manifest-related API modules.
 *
 * Provides common logic shared by:
 *  - ApiLabkiManifestGet
 *  - ApiLabkiHierarchyGet
 *  - ApiLabkiGraphGet
 *  - Future manifest-related endpoints
 *
 * Handles:
 *  - Reference resolution (branch/tag/commit)
 *  - ManifestStore instantiation and refresh logic
 *  - Consistent error handling for missing or invalid data
 *
 * @ingroup API
 */
abstract class ManifestApiBase extends LabkiApiBase {

	protected LabkiRepoRegistry $repoRegistry;

	public function __construct($main, string $name, ?LabkiRepoRegistry $repoRegistry = null) {
		parent::__construct($main, $name);
		$this->repoRegistry = $repoRegistry ?? new LabkiRepoRegistry();
	}

	/**
	 * Helper to standardize Status validation and extraction.
	 *
	 * @param Status $status
	 * @param string $errorCode
	 * @return array
	 */
	protected function unwrapStatus(Status $status, string $errorCode = 'manifest_error'): array {
		if (!$status->isOK()) {
			$this->dieWithError('labkipackmanager-error-' . $errorCode, $errorCode);
		}
		$value = $status->getValue();
		if (!is_array($value)) {
			$this->dieWithError('labkipackmanager-error-invalid-' . $errorCode, 'invalid_' . $errorCode);
		}
		return $value;
	}
}
