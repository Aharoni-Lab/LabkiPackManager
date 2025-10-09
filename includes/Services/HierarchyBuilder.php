<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * HierarchyBuilder
 *
 * Produces a simple hierarchical view model of packs and pages
 * from normalized manifest data.
 *
 * Input (normalized packs):
 * [
 *   [
 *     'id' => 'lab-operations',
 *     'description' => 'Basic lab SOPs',
 *     'version' => '1.0.0',
 *     'pages' => ['Safety', 'Storage', ...],
 *     'depends_on' => ['core'],
 *   ],
 *   ...
 * ]
 *
 * Output:
 * [
 *   'tree' => [...],
 *   'nodes' => [...],
 *   'roots' => [...],
 *   'packCount' => int,
 *   'pageCount' => int
 * ]
 */
final class HierarchyBuilder {

    /**
     * @param array<int,array<string,mixed>> $packs
     * @return array<string,mixed>
     */
    public function buildViewModel(array $packs): array {
        $byId = [];
        foreach ($packs as $p) {
            $id = (string)($p['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = $p;
        }

        // Identify parent/child relationships
        $hasParent = [];
        foreach ($packs as $p) {
            foreach (($p['depends_on'] ?? []) as $dep) {
                $hasParent[(string)$dep] = true;
            }
        }

        $roots = [];
        foreach ($byId as $id => $_) {
            if (!isset($hasParent[$id])) {
                $roots[] = $id;
            }
        }

        // Recursive builder
        $nodes = [];
        $seenPages = [];
        $visited = [];

        $buildNode = function (string $packId) use (&$buildNode, $byId, &$nodes, &$seenPages, &$visited): array {
            if (isset($visited[$packId])) {
                return [
                    'type' => 'pack',
                    'id' => $packId,
                    'children' => [],
                    'packsBeneath' => 0,
                    'pagesBeneath' => 0
                ];
            }

            $visited[$packId] = true;
            $pack = $byId[$packId] ?? null;
            if (!$pack) {
                return [];
            }

            $packChildren = [];
            $packsBeneath = 0;
            $pagesBeneath = 0;

            foreach (($pack['depends_on'] ?? []) as $childId) {
                $child = $byId[$childId] ?? null;
                if (!$child) {
                    continue;
                }
                $vm = $buildNode($childId);
                $packChildren[] = $vm;
                $packsBeneath += 1 + ($vm['packsBeneath'] ?? 0);
                $pagesBeneath += $vm['pagesBeneath'] ?? 0;
            }

            $pageChildren = [];
            foreach (($pack['pages'] ?? []) as $pg) {
                $pageChildren[] = ['type' => 'page', 'id' => (string)$pg];
                $seenPages[$pg] = true;
                $pagesBeneath++;
            }

            $node = [
                'type' => 'pack',
                'id' => $packId,
                'description' => (string)($pack['description'] ?? ''),
                'version' => (string)($pack['version'] ?? ''),
                'packsBeneath' => $packsBeneath,
                'pagesBeneath' => $pagesBeneath,
                'children' => array_merge($packChildren, $pageChildren)
            ];

            $nodes['pack:' . $packId] = [
                'type' => 'pack',
                'id' => 'pack:' . $packId,
                'description' => (string)($pack['description'] ?? ''),
                'version' => (string)($pack['version'] ?? ''),
                'packsBeneath' => $packsBeneath,
                'pagesBeneath' => $pagesBeneath
            ];

            foreach ($pageChildren as $pc) {
                $nodes['page:' . $pc['id']] = [
                    'type' => 'page',
                    'id' => 'page:' . $pc['id']
                ];
            }

            unset($visited[$packId]);
            return $node;
        };

        // Build tree from root packs
        $tree = [];
        foreach ($roots as $rid) {
            $vm = $buildNode($rid);
            if ($vm) {
                $tree[] = $vm;
            }
        }

        return [
            'tree' => $tree,
            'nodes' => $nodes,
            'roots' => array_map(static fn($r) => 'pack:' . $r, $roots),
            'packCount' => count($byId),
            'pageCount' => count($seenPages)
        ];
    }
}
