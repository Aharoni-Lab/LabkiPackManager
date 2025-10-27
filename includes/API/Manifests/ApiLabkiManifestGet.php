<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Manifests;

use ApiMain;
use LabkiPackManager\API\LabkiApiBase;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\ManifestStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API endpoint to get manifest data for a specific repository and ref.
 *
 * ## Purpose
 * Retrieves manifest data including hierarchy, graph, and installation status
 * for a given repository and reference (branch/tag/commit).
 *
 * ## Action
 * `labkiManifestGet`
 *
 * ## Example Requests
 *
 * Basic request:
 * ```
 * api.php?action=labkiManifestGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main&format=json
 * ```
 *
 * With refresh to force re-fetch:
 * ```
 * api.php?action=labkiManifestGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main&refresh=1&format=json
 * ```
 *
 * ## Example Response
 * ```json
 * {
 *   "repo_url": "https://github.com/Aharoni-Lab/labki-packs",
 *   "ref": "main",
 *   "hash": "abc123...",
 *   "manifest": {
 *     "schema_version": "1.0.0",
 *     "name": "Labki Base Packs",
 *     "description": "...",
 *     "author": "Aharoni Lab",
 *     "packs": [...]
 *   },
 *   "hierarchy": {
 *     "tree": [...],
 *     "nodes": {...},
 *     "roots": [...],
 *     "packCount": 5,
 *     "pageCount": 45
 *   },
 *   "graph": {
 *     "nodes": [...],
 *     "edges": [...]
 *   },
 *   "_meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000",
 *     "from_cache": true
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 * - Uses {@see ManifestStore} to fetch cached or fresh manifest data
 * - Filters out backend-only data (pages) before sending to frontend
 * - Supports optional `refresh` parameter to force re-parsing
 *
 * @ingroup API
 */
final class ApiLabkiManifestGet extends LabkiApiBase {

	/**
	 * Constructor.
	 *
	 * @param ApiMain $main Main API object.
	 * @param string $name Module name.
	 */
	public function __construct(ApiMain $main, string $name) {
		parent::__construct($main, $name);
	}

	/**
	 * Execute API request.
	 *
	 * Extracts repository URL and ref, retrieves manifest data using ManifestStore,
	 * and returns structured JSON response.
	 */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$repoUrl = (string)($params['repo_url'] ?? '');

		if ($repoUrl === '') {
			$this->dieWithError(['apierror-missingparam', 'repo_url'], 'missing_repo_url');
		}

		// Validate and normalize the repository URL
		$repoUrl = $this->validateAndNormalizeUrl($repoUrl);

		// Validate repository exists in registry
		$repoRegistry = new LabkiRepoRegistry();
		$repo = $repoRegistry->getRepo($repoUrl);
		if ($repo === null) {
			$this->dieWithError('labkipackmanager-error-repo-not-found', 'repo_not_found');
		}

		// Use default ref if not provided
		$ref = (string)($params['ref'] ?? $repo->defaultRef() ?? 'main');
		$refresh = (bool)($params['refresh'] ?? false);

		

		// Retrieve manifest (cached or refreshed)
		$store = new ManifestStore($repoUrl, $ref);
		$status = $store->get($refresh);
		if (!$status->isOK()) {
			$this->dieWithError('labkipackmanager-error-fetch', 'fetch_error');
		}

		$result = $status->getValue();
		if (!is_array($result) || !isset($result['manifest'])) {
			$this->dieWithError('labkipackmanager-error-invalid-manifest', 'invalid_manifest');
		}

		// Filter out backend-only data (pages) before sending to frontend
		unset($result['manifest']['pages']);

		// Ensure content_repo_url is present at the root for the frontend
		if (!isset($result['content_repo_url'])) {
			$result['content_repo_url'] = $repoUrl;
		}

		$resultObj = $this->getResult();
		$resultObj->addValue(null, 'repo_url', $repoUrl);
		$resultObj->addValue(null, 'ref', $ref);
		$resultObj->addValue(null, 'hash', $result['hash'] ?? '');
		$resultObj->addValue(null, 'manifest', $result['manifest']);
		$resultObj->addValue(null, 'hierarchy', $result['hierarchy'] ?? []);
		$resultObj->addValue(null, 'graph', $result['graph'] ?? []);
		$resultObj->addValue(null, '_meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
			'from_cache' => $result['from_cache'] ?? false,
		]);
	}

	/**
	 * Define allowed API parameters.
	 *
	 * @return array
	 */
	public function getAllowedParams(): array {
		return [
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-manifest-get-param-repo-url',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 'main',
				self::PARAM_HELP_MSG => 'labkipackmanager-api-manifest-get-param-ref',
			],
			'refresh' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-manifest-get-param-refresh',
			],
		];
	}

	/**
	 * Example requests for auto-generated API documentation.
	 *
	 * @return array
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiManifestGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main'
				=> 'apihelp-labkimanifestget-example-basic',
			'action=labkiManifestGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main&refresh=1'
				=> 'apihelp-labkimanifestget-example-refresh',
		];
	}

	/**
	 * This API is read-only and can be called via GET.
	 *
	 * @return bool
	 */
	public function isWriteMode(): bool {
		return false;
	}

	/**
	 * Indicates whether the module is internal.
	 * Here it's exposed publicly for automation and tooling.
	 *
	 * @return bool
	 */
	public function isInternal(): bool {
		return false;
	}
}

