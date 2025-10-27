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
 *   "meta": {
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

	private LabkiRepoRegistry $repoRegistry;
	private ?ManifestStore $manifestStore;

	public function __construct(
		ApiMain $main,
		string $name,
		?LabkiRepoRegistry $repoRegistry = null,
		?ManifestStore $manifestStore = null
	) {
		parent::__construct($main, $name);
		$this->repoRegistry = $repoRegistry ?? new LabkiRepoRegistry();
		// Defer creation of ManifestStore until execute(), when repo/ref are known.
		$this->manifestStore = $manifestStore;
	}

	/**
	 * Execute API request.
	 */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$repoUrl = trim((string)($params['repo_url'] ?? ''));

		if ($repoUrl === '') {
			$this->dieWithError(['apierror-missingparam', 'repo_url'], 'missing_repo_url');
		}

		// Normalize and validate repository URL
		$repoUrl = $this->validateAndNormalizeUrl($repoUrl);

		// Verify that repository exists
		$repo = $this->repoRegistry->getRepo($repoUrl);
		if ($repo === null) {
			$this->dieWithError('labkipackmanager-error-repo-not-found', 'repo_not_found');
		}

		// Determine ref and refresh behavior
		$ref = (string)($params['ref'] ?? $repo->defaultRef() ?? 'main');
		$refresh = (bool)($params['refresh'] ?? false);

		// Create ManifestStore only now (with full repo/ref)
		$store = $this->manifestStore ?? new ManifestStore($repoUrl, $ref);

		$status = $store->get($refresh);
		if (!$status->isOK()) {
			$this->dieWithError('labkipackmanager-error-fetch', 'fetch_error');
		}

		$result = $status->getValue();
		if (!is_array($result) || !isset($result['manifest'])) {
			$this->dieWithError('labkipackmanager-error-invalid-manifest', 'invalid_manifest');
		}

		// Remove backend-only keys
		unset($result['manifest']['pages']);

		// Ensure repo_url is included
		if (!isset($result['content_repo_url'])) {
			$result['content_repo_url'] = $repoUrl;
		}

		// Build API result
		$out = $this->getResult();
		$out->addValue(null, 'repo_url', $repoUrl);
		$out->addValue(null, 'ref', $ref);
		$out->addValue(null, 'hash', $result['hash'] ?? '');
		$out->addValue(null, 'manifest', $result['manifest']);
		$out->addValue(null, 'hierarchy', $result['hierarchy'] ?? []);
		$out->addValue(null, 'graph', $result['graph'] ?? []);
		$out->addValue(null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
			'from_cache' => $result['from_cache'] ?? false,
		]);
	}

	public function getAllowedParams(): array {
		return [
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-manifest-get-param-repo-url',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
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

	protected function getExamplesMessages(): array {
		return [
			'action=labkiManifestGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main'
				=> 'apihelp-labkimanifestget-example-basic',
			'action=labkiManifestGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main&refresh=1'
				=> 'apihelp-labkimanifestget-example-refresh',
		];
	}

	public function isWriteMode(): bool {
		return false;
	}

	public function isInternal(): bool {
		return false;
	}
}