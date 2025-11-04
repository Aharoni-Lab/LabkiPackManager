<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Manifests;

use ApiMain;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\ManifestStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API endpoint to retrieve the computed hierarchy tree
 * for a given repository and reference (branch/tag/commit).
 *
 * ## Action
 * `labkiHierarchyGet`
 *
 * ## Purpose
 * Returns a fully resolved, UI-ready hierarchy structure
 * derived from the manifest.yml packs and dependencies.
 * Does not include raw manifest or graph data.
 *
 * ## Example
 * ```
 * api.php?action=labkiHierarchyGet
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
 *   "hierarchy": { ... },
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
final class ApiLabkiHierarchyGet extends ManifestApiBase {

	private ?ManifestStore $manifestStore;

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

		$repoUrl = $this->resolveRepoUrl($params['repo_url'], true);
		$repo = $this->repoRegistry->getRepo($repoUrl);

		// Use default ref if not specified
		$ref = $params['ref'] ?? $repo->defaultRef();
		$refresh = $params['refresh'];

		// This is currently how it is due to issues with setting up testing
		// TODO: Figure out how to improve this
		$store = $this->manifestStore ?? new ManifestStore($repoUrl, $ref);

		// Get hierarchy from store
		$status = $store->getHierarchy($refresh);
		$result = $this->unwrapStatus($status);

		$meta = $result['meta'];
		$hierarchy = $result['hierarchy'];

		// 4. Output response
		$out = $this->getResult();
		$out->addValue(null, 'repo_url', $meta['repo_url'] ?? $repoUrl);
		$out->addValue(null, 'ref', $meta['ref'] ?? $ref);
		$out->addValue(null, 'hash', $meta['hash'] ?? '');
		$out->addValue(null, 'hierarchy', $hierarchy);
		$out->addValue(null, 'meta', [
			'schemaVersion' => $meta['schema_version'] ?? 1,
			'timestamp' => wfTimestampNow(),
			'from_cache' => $result['from_cache'] ?? false,
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
				self::PARAM_HELP_MSG => 'labkipackmanager-api-hierarchy-get-param-repo-url',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-hierarchy-get-param-ref',
			],
			'refresh' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-hierarchy-get-param-refresh',
			],
		];
	}

	protected function getExamplesMessages(): array {
		return [
			'action=labkiHierarchyGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main'
				=> 'apihelp-labkihierarchyget-example-basic',
			'action=labkiHierarchyGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main&refresh=1'
				=> 'apihelp-labkihierarchyget-example-refresh',
		];
	}

	public function isWriteMode(): bool {
		return false;
	}

	public function isInternal(): bool {
		return false;
	}
}
