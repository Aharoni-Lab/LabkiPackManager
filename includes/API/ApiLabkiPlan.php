<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\ManifestStore;
use LabkiPackManager\Services\SelectionResolver;
use LabkiPackManager\Services\PreflightPlanner;
use LabkiPackManager\Services\PlanResolver;
use LabkiPackManager\Services\GraphBuilder;
use LabkiPackManager\Services\MermaidBuilder;

/**
 * Action API module for dependency resolution, preflight checks, and install planning.
 *
 * This API computes dependency graphs and proposed install/update plans
 * based on the selected packs from a remote or cached manifest.
 *
 * It does not alter the database or install content. It only simulates and returns
 * what would happen if the selected packs were installed, including:
 *   - dependency expansion
 *   - page ownership conflicts
 *   - mapping of names and prefixes
 *
 * Example usage:
 *   api.php?action=labkiplan&repo=https://github.com/Aharoni-Lab/labki-packs
 *       &selected[]=lab-operations&selected[]=protocols
 *       &plan={"globalPrefix":"MyLab_"}
 *
 * Example response:
 * {
 *   "labkiplan": {
 *     "preview": { "packs": [...], "pages": [...] },
 *     "preflight": { "conflicts": [], "pageOwners": {} },
 *     "plan": { "mappings": [...] },
 *     "graph": { "edges": [...], "nodes": [...] },
 *     "mermaid": { "code": "...", "idMap": {...} },
 *     "_meta": { "schemaVersion": 1, "timestamp": "20251005..." }
 *   }
 * }
 *
 * @ingroup API
 */
final class ApiLabkiPlan extends ApiBase {

    /**
     * @param ApiMain $main The main API module
     * @param string $name The name of this module (action name)
     */
    public function __construct(ApiMain $main, string $name) {
        parent::__construct($main, $name);
    }

    /**
     * Executes dependency and installation planning.
     *
     * Query flow:
     *   1. Retrieve or refresh manifest for given repo URL.
     *   2. Resolve dependencies and page ownership.
     *   3. Generate preflight plan summary and dependency graph.
     *   4. Return structured plan data for frontend preview.
     *
     * @return void
     */
    public function execute(): void {
        $params = $this->extractRequestParams();
        $repoUrl = (string)($params['repo'] ?? '');
        $selected = (array)($params['selected'] ?? []);
        $planJson = $params['plan'] ? json_decode((string)$params['plan'], true) : [];
        $refresh = (bool)($params['refresh'] ?? false);

        if ($repoUrl === '') {
            $this->dieWithError(['apierror-missing-param', 'repo'], 'missing_repo');
        }

        $store = new ManifestStore($repoUrl);
        $status = $store->get($refresh);
        if (!$status->isOK()) {
            $this->dieWithError('labkipackmanager-error-fetch');
        }

        $manifest = $status->getValue();
        $packs = $manifest['packs'] ?? [];

        // --- Dependency and preflight computation ---
        $resolver = new SelectionResolver();
        $preview = $resolver->resolveWithLocks($packs, $selected);

        $planner = new PreflightPlanner();
        $preflight = $planner->plan([
            'packs' => $preview['packs'],
            'pages' => $preview['pages'],
            'pageOwners' => $preview['pageOwners'] ?? [],
            'repoUrl' => $repoUrl,
        ]);

        $planResolver = new PlanResolver();
        $plan = $planResolver->resolve(
            ['packs' => $preview['packs'], 'pages' => $preview['pages']],
            $planJson,
            ['lists' => $preflight['lists'] ?? []]
        );

        // --- Graph and visualization (optional for UI) ---
        $graph = (new GraphBuilder())->build($packs);
        $diagram = (new MermaidBuilder())->generateWithIdMap($graph['edges'] ?? []);

        $result = [
            'preview' => $preview,
            'preflight' => $preflight,
            'plan' => $plan,
            'graph' => $graph,
            'mermaid' => $diagram,
            '_meta' => [
                'schemaVersion' => 1,
                'timestamp' => wfTimestampNow(),
            ],
        ];

        $this->getResult()->addValue(null, $this->getModuleName(), $result);
    }

    /**
     * Defines supported parameters for dependency planning.
     *
     * @return array<string,array>
     */
    public function getAllowedParams(): array {
        return [
            'repo' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'selected' => [ self::PARAM_TYPE => 'string', self::PARAM_ISMULTI => true ],
            'plan' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'refresh' => [ self::PARAM_TYPE => 'boolean', self::PARAM_DFLT => false ],
        ];
    }

    /**
     * Marks this API as publicly callable (not internal-only).
     *
     * @return bool Always false to allow external usage.
     */
    public function isInternal(): bool {
        return false;
    }
}
