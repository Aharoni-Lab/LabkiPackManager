<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * GraphBuilder
 *
 * Utility for computing dependency relationships between packs and pages.
 * Used for visualization (e.g., Mermaid) and dependency resolution.
 *
 * Input:
 *   [
 *     [ 'id' => 'pack-a', 'pages' => [...], 'depends_on' => [...], 'contains' => [...] ],
 *     ...
 *   ]
 *
 * Output:
 *   [
 *     'containsEdges' => [ ['from' => 'pack-a', 'to' => 'page-x'], ... ],
 *     'dependsEdges'  => [ ['from' => 'pack-a', 'to' => 'pack-b'], ... ],
 *     'hasCycle'      => bool,
 *     'roots'         => string[],
 *   ]
 */
final class GraphBuilder {

    /**
     * Build a dependency graph structure from normalized pack data.
     *
     * @param array<int,array<string,mixed>> $packs
     * @return array<string,mixed>
     */
    public function build(array $packs): array {
        $contains = [];
        $depends = [];
        $allIds = [];

        foreach ($packs as $pack) {
            $id = (string)($pack['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $allIds[$id] = true;

            foreach (($pack['pages'] ?? []) as $page) {
                $contains[] = ['from' => $id, 'to' => (string)$page];
            }

            foreach (($pack['depends_on'] ?? []) as $dep) {
                $depends[] = ['from' => $id, 'to' => (string)$dep];
            }
        }

        $hasCycle = $this->hasCycle($depends);
        $roots = $this->findRoots($allIds, $depends);

        return [
            'containsEdges' => $contains,
            'dependsEdges' => $depends,
            'hasCycle' => $hasCycle,
            'roots' => $roots,
        ];
    }

    /**
     * Detect if there is a dependency cycle.
     *
     * @param array<array{from:string,to:string}> $edges
     */
    private function hasCycle(array $edges): bool {
        $adj = [];
        $inDegree = [];

        foreach ($edges as $e) {
            $from = $e['from'];
            $to = $e['to'];
            $adj[$from][] = $to;
            $inDegree[$to] = ($inDegree[$to] ?? 0) + 1;
            $inDegree[$from] = $inDegree[$from] ?? 0;
        }

        $queue = array_keys(array_filter($inDegree, fn($d) => $d === 0));
        $visited = 0;

        while ($queue) {
            $node = array_shift($queue);
            $visited++;
            foreach ($adj[$node] ?? [] as $nbr) {
                $inDegree[$nbr]--;
                if ($inDegree[$nbr] === 0) {
                    $queue[] = $nbr;
                }
            }
        }

        return $visited !== count($inDegree);
    }

    /**
     * Identify "root" packs (those with no incoming dependencies).
     *
     * @param array<string,bool> $ids
     * @param array<array{from:string,to:string}> $edges
     * @return string[]
     */
    private function findRoots(array $ids, array $edges): array {
        $hasParent = [];
        foreach ($edges as $e) {
            $hasParent[$e['to']] = true;
        }

        return array_values(
            array_diff(array_keys($ids), array_keys($hasParent))
        );
    }
}
