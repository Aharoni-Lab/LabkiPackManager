<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * HierarchyBuilder (Memory-Optimized)
 *
 * Builds a hierarchical view model of packs and pages from normalized manifest data.
 * Identical logical structure to the full version but stores only ID references
 * inside each pack’s `children` list to minimize memory and JSON output size.
 *
 * Complexity: O(N + E)
 */
final class HierarchyBuilder {

    /**
     * Build a hierarchical view model from normalized pack definitions.
     *
     * @param array<int,array<string,mixed>> $packs
     * @return array<string,mixed>
     */
    public function buildViewModel(array $packs): array {
        // ----------------------------------------------------------
        // 1. Normalize packs by ID
        // ----------------------------------------------------------
        $byId = [];
        foreach ($packs as $p) {
            $id = (string)($p['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = $p + [
                'depends_on'  => [],
                'pages'       => [],
                'description' => '',
                'version'     => '',
            ];
        }

        // ----------------------------------------------------------
        // 2. Build reverse dependency map
        // ----------------------------------------------------------
        $dependedBy = [];
        foreach ($packs as $p) {
            $pid = (string)($p['id'] ?? '');
            foreach (($p['depends_on'] ?? []) as $dep) {
                if ($dep === '' || !isset($byId[$dep])) {
                    continue;
                }
                $dependedBy[$dep][] = $pid;
            }
        }

        // ----------------------------------------------------------
        // 3. Identify roots
        // ----------------------------------------------------------
        $roots = [];
        foreach ($byId as $id => $_) {
            if (!isset($dependedBy[$id])) {
                $roots[] = $id;
            }
        }

        // ----------------------------------------------------------
        // 4. Initialize accumulators
        // ----------------------------------------------------------
        $nodes = [];
        $seenPages = [];
        $visited = [];
        $cache = [];

        /**
         * Recursively build a pack node and cache it.
         *
         * @param string $packId
         * @param string|null $parentId
         * @return array<string,mixed>
         */
        $buildNode = function (string $packId, ?string $parentId = null)
            use (&$buildNode, &$cache, &$byId, &$nodes, &$seenPages, &$visited, &$dependedBy): array
        {
            if (isset($visited[$packId])) {
                // cycle stub
                return [
                    'type' => 'pack',
                    'id' => $packId,
                    'children' => [],
                    'packsBeneath' => 0,
                    'pagesBeneath' => 0
                ];
            }

            if (isset($cache[$packId])) {
                // shallow clone with new parent reference
                $cached = $cache[$packId];
                $cached['parent'] = $parentId;
                return $cached;
            }

            $visited[$packId] = true;
            $pack = $byId[$packId] ?? null;
            if (!$pack) {
                unset($visited[$packId]);
                return [];
            }

            // --- Build dependency references only (no deep copies) ---
            $packsBeneath = 0;
            $pagesBeneath = 0;
            $childRefs = [];

            foreach (($pack['depends_on'] ?? []) as $depId) {
                if (!isset($byId[$depId])) {
                    continue;
                }
                $childVm = $buildNode($depId, $packId);
                $childRefs[] = ['type' => 'pack', 'id' => $depId];
                $packsBeneath += 1 + ($childVm['packsBeneath'] ?? 0);
                $pagesBeneath += $childVm['pagesBeneath'] ?? 0;
            }

            // --- Add page refs ---
            foreach (($pack['pages'] ?? []) as $pg) {
                $childRefs[] = ['type' => 'page', 'id' => (string)$pg];
                $seenPages[$pg] = true;
                $pagesBeneath++;
            }

            // --- Pack node (hierarchical reference only) ---
            $node = [
                'type'         => 'pack',
                'id'           => $packId,
                'description'  => (string)$pack['description'],
                'version'      => (string)$pack['version'],
                'depends_on'   => array_values($pack['depends_on'] ?? []),
                'depended_by'  => array_values($dependedBy[$packId] ?? []),
                'parent'       => $parentId,
                'packsBeneath' => $packsBeneath,
                'pagesBeneath' => $pagesBeneath,
                'children'     => $childRefs     // <-- only {type,id} entries
            ];

            // --- Flat registry for pack ---
            $nodes['pack:' . $packId] = [
                'type'         => 'pack',
                'id'           => 'pack:' . $packId,
                'description'  => (string)$pack['description'],
                'version'      => (string)$pack['version'],
                'depends_on'   => array_values($pack['depends_on'] ?? []),
                'depended_by'  => array_values($dependedBy[$packId] ?? []),
                'pages'        => array_values($pack['pages'] ?? []),
                'parent'       => $parentId,
                'packsBeneath' => $packsBeneath,
                'pagesBeneath' => $pagesBeneath
            ];

            // --- Flat registry entries for pages ---
            foreach (($pack['pages'] ?? []) as $pg) {
                $nodes['page:' . $pg] = [
                    'type'   => 'page',
                    'id'     => 'page:' . $pg,
                    'parent' => 'pack:' . $packId
                ];
            }

            $cache[$packId] = $node;
            unset($visited[$packId]);
            return $node;
        };

        // ----------------------------------------------------------
        // 5. Build hierarchy from roots
        // ----------------------------------------------------------
        $tree = [];
        foreach ($roots as $rid) {
            $vm = $buildNode($rid, null);
            if ($vm) {
                $tree[] = $vm;
            }
        }

        // ----------------------------------------------------------
        // 6. Return assembled structure
        // ----------------------------------------------------------
        return [
            'tree'       => $tree,
            'nodes'      => $nodes,
            'roots'      => array_map(static fn($r) => 'pack:' . $r, $roots),
            'packCount'  => count($byId),
            'pageCount'  => count($seenPages)
        ];
    }
}
