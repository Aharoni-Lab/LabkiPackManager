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
 *  - Repository validation and normalization
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
	 * Validate repo_url, ensure repository exists, and return normalized URL.
	 *
	 * @param array $params Extracted API parameters
	 * @return string Normalized repository URL
	 */
	protected function resolveAndValidateRepo(array $params): string {
		$repoUrl = trim((string)($params['repo_url'] ?? ''));
		if ($repoUrl === '') {
			$this->dieWithError(['apierror-missingparam', 'repo_url'], 'missing_repo_url');
		}

		$repoUrl = $this->validateAndNormalizeUrl($repoUrl);

		$repo = $this->repoRegistry->getRepo($repoUrl);
		if ($repo === null) {
			$this->dieWithError('labkipackmanager-error-repo-not-found', 'repo_not_found');
		}

		return $repoUrl;
	}

	/**
	 * Resolve which Git ref to use based on parameters or repository default.
	 *
	 * @param array $params Extracted API parameters
	 * @param object|null $repo Optional repo object if already resolved
	 * @return string
	 */
	protected function resolveRef(array $params, ?object $repo = null): string {
		$ref = (string)($params['ref'] ?? ($repo ? $repo->defaultRef() : 'main'));
		return $ref !== '' ? $ref : 'main';
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
