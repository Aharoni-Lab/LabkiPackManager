<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Manifests;

use ApiMain;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\ManifestStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API endpoint to retrieve the dependency graph
 * for a given repository and reference (branch/tag/commit).
 *
 * ## Action
 * `labkiGraphGet`
 *
 * ## Purpose
 * Returns the computed dependency and containment graph
 * for all packs and pages defined in manifest.yml.
 * Used for dependency visualization (e.g., Mermaid) or analysis.
 *
 * ## Example
 * ```
 * api.php?action=labkiGraphGet
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
 *   "graph": {
 *     "containsEdges": [ {"from": "pack-a", "to": "page-x"}, ... ],
 *     "dependsEdges": [ {"from": "pack-b", "to": "pack-a"}, ... ],
 *     "roots": [ "pack-x", "pack-y" ],
 *     "hasCycle": false
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
final class ApiLabkiGraphGet extends ManifestApiBase {

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

		// 1. Resolve and validate repository
		$repoUrl = $this->resolveRepoUrl($params['repo_url'], true);
		$repo = $this->repoRegistry->getRepo($repoUrl);

		// 2. Resolve ref and refresh flag
		$ref = $params['ref'] ?? $repo->defaultRef();
		$refresh = $params['refresh'];

		// This is currently how it is due to issues with setting up testing
		// TODO: Figure out how to improve this
		$store = $this->manifestStore ?? new ManifestStore($repoUrl, $ref);

		// Get graph from store
		$status = $store->getGraph($refresh);
		$result = $this->unwrapStatus($status);

		$meta = $result['meta'];
		$graph = $result['graph'];

		$out = $this->getResult();
		$out->addValue(null, 'repo_url', $meta['repo_url']);
		$out->addValue(null, 'ref', $meta['ref']);
		$out->addValue(null, 'hash', $meta['hash']);
		$out->addValue(null, 'graph', $graph);
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
				self::PARAM_HELP_MSG => 'labkipackmanager-api-graph-get-param-repo-url',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-graph-get-param-ref',
			],
			'refresh' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-graph-get-param-refresh',
			],
		];
	}

	protected function getExamplesMessages(): array {
		return [
			'action=labkiGraphGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main'
				=> 'apihelp-labkigraphget-example-basic',
			'action=labkiGraphGet&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main&refresh=1'
				=> 'apihelp-labkigraphget-example-refresh',
		];
	}

	public function isWriteMode(): bool {
		return false;
	}

	public function isInternal(): bool {
		return false;
	}
}
