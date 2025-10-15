<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\ManifestStore;

/**
 * Action API for fetching and preparing manifest data for client-side display.
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
 *     "_meta": { "schemaVersion": 1, "timestamp": "...", "repo": "...", "refreshed": true|false }
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

        // Retrieve manifest (cached or refreshed)
        $store = new ManifestStore($repoUrl);
        $status = $store->get($refresh);
        if (!$status->isOK()) {
            $this->dieWithError('labkipackmanager-error-fetch');
        }

        $result = $status->getValue();
        if (!is_array($result) || !isset($result['manifest'])) {
            $this->dieWithError('labkipackmanager-error-invalid-manifest');
        }

        // Filter out backend-only data (pages) before sending to frontend
        unset($result['manifest']['pages']);

        // Append runtime metadata
        $result['_meta']['timestamp'] = \wfTimestampNow();
        $result['_meta']['repo'] = $repoUrl;
        $result['_meta']['refreshed'] = $refresh;

        // Ensure content_repo_url is present at the root for the frontend
        if (!isset($result['content_repo_url'])) {
            $result['content_repo_url'] = $repoUrl;
        }

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
