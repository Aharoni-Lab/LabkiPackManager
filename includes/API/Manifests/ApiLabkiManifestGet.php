<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Manifests;

use ApiMain;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\ManifestStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API endpoint to retrieve only the parsed manifest data
 * for a given repository and reference (branch/tag/commit).
 *
 * ## Action
 * `labkiManifestGet`
 *
 * ## Purpose
 * Returns the parsed manifest.yml content stored in ManifestStore.
 * Does not include derived hierarchy or graph data.
 *
 * ## Example
 * ```
 * api.php?action=labkiManifestGet
 *   &repo_url=https://github.com/Aharoni-Lab/labki-packs
 *   &ref=main
 *   &format=json
 * ```
 *
 * ## Example Response
 * ```json
 * {
 *   "repo_url": "https://github.com/Aharoni-Lab/labki-packs",
 *   "ref": "main",
 *   "hash": "19aba05e8751431c92890b569d4d2a5ef75a4194",
 *   "manifest": {
 *     "schema_version": "1.0.0",
 *     "last_updated": "2025-09-22T00:00:01Z",
 *     "name": "Labki Packs",
 *     "packs": {...},
 *     "pages": {...}
 *   },
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251027T213442",
 *     "from_cache": true
 *   }
 * }
 * ```
 *
 * @ingroup API
 */
final class ApiLabkiManifestGet extends ManifestApiBase {

	private ?ManifestStore $manifestStore = null;

	public function __construct(
		ApiMain $main,
		string $name,
		?LabkiRepoRegistry $repoRegistry = null,
		?ManifestStore $manifestStore = null
	) {
		parent::__construct($main, $name, $repoRegistry);
		$this->manifestStore = $manifestStore;
	}

	/**
	 * Execute the API request.
	 */
	public function execute(): void {
		$params = $this->extractRequestParams();
	
		$repoUrl = $this->resolveAndValidateRepo($params);
		$repo = $this->repoRegistry->getRepo($repoUrl);
		$ref = $this->resolveRef($params, $repo);
		$refresh = (bool)($params['refresh'] ?? false);
	
		$store = $this->manifestStore ?? new ManifestStore($repoUrl, $ref);
		$status = $store->get($refresh);
	
		if (!$status->isOK()) {
			$this->dieWithError('labkipackmanager-error-fetch', 'fetch_error');
		}
	
		$data = $status->getValue();
		if (!isset($data['manifest']) || !is_array($data['manifest'])) {
			$this->dieWithError('labkipackmanager-error-invalid-manifest', 'invalid_manifest');
		}
	
		$meta = $data['meta'] ?? [];
		$manifest = $data['manifest'];
	
		$out = $this->getResult();
		$out->addValue(null, 'repo_url', $meta['repo_url'] ?? $repoUrl);
		$out->addValue(null, 'ref', $meta['ref'] ?? $ref);
		$out->addValue(null, 'hash', $meta['hash'] ?? ($data['hash'] ?? ''));
		$out->addValue(null, 'manifest', $manifest);
		$out->addValue(null, 'meta', [
			'schemaVersion' => $meta['schema_version'] ?? 1,
			'timestamp' => wfTimestampNow(),
			'from_cache' => $data['from_cache'] ?? false,
		]);
	}

	/**
	 * Define allowed parameters.
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
