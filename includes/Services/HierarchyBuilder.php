<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * HierarchyBuilder
 *
 * Builds a fully connected hierarchical view model of packs and pages
 * from normalized manifest data. This structure powers both
 * visualization (nested pack tree) and dependency-aware operations
 * in the front-end.
 *
 * ## Input
 * The input is an array of normalized packs, e.g.:
 *
 * ```php
 * [
 *   [
 *     'id'          => 'lab-operations',
 *     'description' => 'Basic lab SOPs',
 *     'version'     => '1.0.0',
 *     'pages'       => ['Safety', 'Storage'],
 *     'depends_on'  => ['core'],
 *   ],
 *   [
 *     'id'          => 'core',
 *     'description' => 'Core templates',
 *     'version'     => '1.0.0',
 *     'pages'       => ['Template_Page'],
 *     'depends_on'  => [],
 *   ]
 * ]
 * ```
 *
 * ## Output
 * Returns a structured array containing:
 *
 * ```php
 * [
 *   'tree' => [ ... ],     // Nested hierarchy of packs → dependencies → pages
 *   'nodes' => [           // Flat map of every pack/page for quick lookup
 *     'pack:core' => [
 *       'type'         => 'pack',
 *       'id'           => 'pack:core',
 *       'description'  => 'Core templates',
 *       'version'      => '1.0.0',
 *       'depends_on'   => [],
 *       'depended_by'  => ['lab-operations'],
 *       'pages'        => ['Template_Page'],
 *       'parent'       => null,
 *       'packsBeneath' => 0,
 *       'pagesBeneath' => 1
 *     ],
 *     'page:Template_Page' => [
 *       'type'   => 'page',
 *       'id'     => 'page:Template_Page',
 *       'parent' => 'pack:core'
 *     ],
 *     ...
 *   ],
 *   'roots'      => ['pack:lab-operations', ...],
 *   'packCount'  => 2,
 *   'pageCount'  => 3
 * ]
 * ```
 *
 * Each pack node now includes explicit dependency, reverse-dependency,
 * and parent relationships to allow efficient traversal or visualization
 * on the front-end without additional computation.
 */
final class HierarchyBuilder {

    /**
     * Build a hierarchical view model from normalized pack definitions.
     *
     * @param array<int,array<string,mixed>> $packs
     * @return array<string,mixed>
     */
    public function buildViewModel(array $packs): array {
        // Normalize packs by ID
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

        // Build reverse dependency map: dep -> [parents...]
        $dependedBy = [];
        foreach ($packs as $p) {
            $pid = (string)($p['id'] ?? '');
            foreach (($p['depends_on'] ?? []) as $dep) {
                $dependedBy[$dep][] = $pid;
            }
        }

        // Identify root packs (not depended on by any other pack)
        $roots = [];
        foreach ($byId as $id => $_) {
            if (!isset($dependedBy[$id])) {
                $roots[] = $id;
            }
        }

        // Initialize accumulators
        $nodes = [];
        $seenPages = [];
        $visited = [];

        /**
         * Recursively build a pack node and its dependencies.
         *
         * @param string $packId
         * @param string|null $parentId
         * @return array<string,mixed>
         */
        $buildNode = function (string $packId, ?string $parentId = null)
            use (&$buildNode, $byId, &$nodes, &$seenPages, &$visited, &$dependedBy): array
        {
            if (isset($visited[$packId])) {
                // Prevent cycles
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

            // Recursively include dependency packs
            foreach (($pack['depends_on'] ?? []) as $childId) {
                if (!isset($byId[$childId])) {
                    continue;
                }
                $childVm = $buildNode($childId, $packId);
                $packChildren[] = $childVm;
                $packsBeneath += 1 + ($childVm['packsBeneath'] ?? 0);
                $pagesBeneath += $childVm['pagesBeneath'] ?? 0;
            }

            // Add own pages
            $pageChildren = [];
            foreach (($pack['pages'] ?? []) as $pg) {
                $pageChildren[] = [
                    'type' => 'page',
                    'id'   => (string)$pg
                ];
                $seenPages[$pg] = true;
                $pagesBeneath++;
            }

            // Construct full pack node
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
                'children'     => array_merge($packChildren, $pageChildren)
            ];

            // Flat registry entry for packs
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

            // Flat registry entries for pages
            foreach ($pageChildren as $pc) {
                $nodes['page:' . $pc['id']] = [
                    'type'   => 'page',
                    'id'     => 'page:' . $pc['id'],
                    'parent' => 'pack:' . $packId
                ];
            }

            unset($visited[$packId]);
            return $node;
        };

        // Build tree starting from root packs
        $tree = [];
        foreach ($roots as $rid) {
            $vm = $buildNode($rid, null);
            if ($vm) {
                $tree[] = $vm;
            }
        }

        return [
            'tree'       => $tree,
            'nodes'      => $nodes,
            'roots'      => array_map(static fn($r) => 'pack:' . $r, $roots),
            'packCount'  => count($byId),
            'pageCount'  => count($seenPages)
        ];
    }
}
