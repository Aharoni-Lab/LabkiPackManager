<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\ManifestStore;
use LabkiPackManager\Parser\ManifestParser;
use LabkiPackManager\Services\HierarchyBuilder;
use LabkiPackManager\Services\GraphBuilder;

/**
 * Action API for fetching and preparing manifest data for client-side display.
 *
 * Returns parsed, normalized, and structured data for Labki pack repositories.
 * Does not perform dependency resolution (handled in frontend).
 *
 * Example:
 *   api.php?action=labkiManifest&repo=https://github.com/Aharoni-Lab/labki-packs
 *
 * Response:
 * {
 *   "labkiManifest": {
 *     "manifest": {...},
 *     "hierarchy": {...},
 *     "graph": {...},
 *     "_meta": { "schemaVersion": 1, "timestamp": "..." }
 *   }
 * }
 */
final class ApiLabkiManifest extends ApiBase {

    public function __construct(ApiMain $main, string $name) {
        parent::__construct($main, $name);
    }

    public function execute(): void {
        $params = $this->extractRequestParams();
        $repoUrl = (string)($params['repo'] ?? '');
        $refresh = (bool)($params['refresh'] ?? false);

        if ($repoUrl === '') {
            $this->dieWithError(['apierror-missing-param', 'repo'], 'missing_repo');
        }

        // --- 1. Retrieve manifest (cached or refreshed) ---
        $store = new ManifestStore($repoUrl);
        $status = $store->get($refresh);
        if (!$status->isOK()) {
            $this->dieWithError('labkipackmanager-error-fetch');
        }

        $manifestYaml = $status->getValue();
        if (!is_string($manifestYaml)) {
            $this->dieWithError('labkipackmanager-error-invalid-manifest');
        }

        // --- 2. Parse manifest YAML ---
        $parser = new ManifestParser();
        try {
            $manifestData = $parser->parse($manifestYaml);
        } catch (\Throwable $e) {
            $this->dieWithError('labkipackmanager-error-parse');
        }

        $packs = $manifestData['packs'] ?? [];

        // --- 3. Build hierarchy and graph ---
        $hierarchy = (new HierarchyBuilder())->buildViewModel($packs);
        $graph = (new GraphBuilder())->build($packs);

        // --- 4. Bundle result ---
        $result = [
            'manifest' => $manifestData,
            'hierarchy' => $hierarchy,
            'graph' => $graph,
            '_meta' => [
                'schemaVersion' => 1,
                'timestamp' => wfTimestampNow(),
                'repo' => $repoUrl,
                'refreshed' => $refresh,
            ],
        ];

        $this->getResult()->addValue(null, $this->getModuleName(), $result);
    }

    public function getAllowedParams(): array {
        return [
            'repo' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'refresh' => [ self::PARAM_TYPE => 'boolean', self::PARAM_DFLT => false ],
        ];
    }

    public function isInternal(): bool {
        return false;
    }
}
