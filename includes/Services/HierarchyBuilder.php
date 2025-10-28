<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * HierarchyBuilder
 *
 * Builds a UI-ready hierarchy structure of packs and pages from a manifest.
 * Supports both associative and numeric-keyed pack definitions.
 */
final class HierarchyBuilder {

    /**
     * Build a hierarchical tree of packs and pages.
     *
     * @param array<string,mixed> $manifest Parsed manifest.yml data
     * @return array<string,mixed> UI-ready hierarchy structure
     */
    public function build(array $manifest): array {
        $packs = $manifest['packs'] ?? [];
        $pages = $manifest['pages'] ?? [];

        // --- Normalize pack definitions ---
        $byId = [];
        foreach ($packs as $key => $packDef) {
            // Handle both keyed and numeric arrays
            $packId = is_string($key) ? $key : (string)($packDef['id'] ?? '');
            if ($packId === '') {
                continue;
            }

            $byId[$packId] = [
                'id' => $packId,
                'label' => $packId,
                'description' => (string)($packDef['description'] ?? ''),
                'version' => (string)($packDef['version'] ?? ''),
                'pages' => array_values((array)($packDef['pages'] ?? [])),
                'depends_on' => array_values((array)($packDef['depends_on'] ?? []))
            ];
        }

        // --- Reverse dependency map ---
        $dependedBy = [];
        foreach ($byId as $pid => $pack) {
            foreach ($pack['depends_on'] as $dep) {
                if (isset($byId[$dep])) {
                    $dependedBy[$dep][] = $pid;
                }
            }
        }

        // --- Identify roots (no one depends on them) ---
        $roots = [];
        foreach ($byId as $id => $_) {
            if (!isset($dependedBy[$id])) {
                $roots[] = $id;
            }
        }

        // --- Recursive builder ---
        $visited = [];
        $cache = [];
        $pageCount = 0;

        $buildNode = function (string $packId) use (&$buildNode, &$visited, &$cache, &$byId, &$pageCount): array {
            if (isset($visited[$packId])) {
                // Prevent infinite recursion
                return [
                    'id' => "pack:$packId",
                    'label' => $packId,
                    'type' => 'pack',
                    'description' => '(cyclic reference)',
                    'children' => []
                ];
            }

            if (isset($cache[$packId])) {
                return $cache[$packId];
            }

            $visited[$packId] = true;
            $pack = $byId[$packId];
            $children = [];

            // Build dependency subtrees
            foreach ($pack['depends_on'] as $dep) {
                if (!isset($byId[$dep])) {
                    continue;
                }
                $children[] = $buildNode($dep);
            }

            // Add pages under this pack
            foreach ($pack['pages'] as $pageId) {
                $children[] = [
                    'id' => "page:$pageId",
                    'label' => $pageId,
                    'type' => 'page'
                ];
                $pageCount++;
            }

            $node = [
                'id' => "pack:$packId",
                'label' => $packId,
                'type' => 'pack',
                'description' => $pack['description'],
                'version' => $pack['version'],
                'depends_on' => $pack['depends_on'],
                'children' => $children,
                'stats' => [
                    'packs_beneath' => $this->countDescendantsOfType($children, 'pack'),
                    'pages_beneath' => $this->countDescendantsOfType($children, 'page')
                ]
            ];

            $cache[$packId] = $node;
            unset($visited[$packId]);
            return $node;
        };

        // --- Build tree from roots ---
        $tree = [];
        foreach ($roots as $rootId) {
            $tree[] = $buildNode($rootId);
        }

        return [
            'root_nodes' => $tree,
            'meta' => [
                'pack_count' => count($byId),
                'page_count' => $pageCount,
                'timestamp' => wfTimestampNow()
            ]
        ];
    }

    /**
     * Recursively count descendants of a given type.
     *
     * @param array<int,array<string,mixed>> $children
     * @param string $type
     * @return int
     */
    private function countDescendantsOfType(array $children, string $type): int {
        $count = 0;
        foreach ($children as $child) {
            if (($child['type'] ?? '') === $type) {
                $count++;
            }
            if (!empty($child['children'])) {
                $count += $this->countDescendantsOfType($child['children'], $type);
            }
        }
        return $count;
    }
}
