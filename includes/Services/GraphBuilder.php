<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * GraphBuilder
 *
 * Computes a dependency graph among packs and their contained pages.
 * Used by {@see ManifestStore} to generate dependency visualizations
 * and support higher-level relationship queries.
 *
 * ## Input
 * Array of normalized pack definitions:
 * ```php
 * [
 *   [
 *     'id' => 'pack-a',
 *     'pages' => [ 'page1', 'page2' ],
 *     'depends_on' => [ 'pack-b', 'pack-c' ]
 *   ],
 *   ...
 * ]
 * ```
 *
 * ## Output
 * Structured graph data:
 * ```php
 * [
 *   'containsEdges' => [ ['from' => 'pack-a', 'to' => 'page1'], ... ],
 *   'dependsEdges'  => [ ['from' => 'pack-a', 'to' => 'pack-b'], ... ],
 *   'roots'         => [ 'pack-x', 'pack-y' ], // packs with no incoming edges
 *   'hasCycle'      => true|false
 * ]
 * ```
 *
 * Complexity: O(N + E)
 */
final class GraphBuilder {

	/**
	 * Build the dependency graph.
	 *
	 * @param array<int,array<string,mixed>> $packs Normalized pack definitions
	 * @return array<string,mixed> Structured graph output
	 */
	public function build(array $packs): array {
		$containsEdges = [];
		$dependsEdges = [];
		$allPackIds = [];

		foreach ($packs as $pack) {
			$id = (string)($pack['id'] ?? '');
			if ($id === '') {
				continue;
			}
			// Prefix pack IDs to ensure they're distinct from page names
			$packPrefix = 'pack:' . $id;
			$allPackIds[$packPrefix] = true;

			// "Contains" edges: pack -> page (with prefixes)
			foreach ((array)($pack['pages'] ?? []) as $page) {
				$page = trim((string)$page);
				if ($page !== '') {
					$pagePrefix = 'page:' . $page;
					$containsEdges[] = ['from' => $packPrefix, 'to' => $pagePrefix];
				}
			}

			// "Depends on" edges: pack -> other pack (with prefixes)
			foreach ((array)($pack['depends_on'] ?? []) as $dep) {
				$dep = trim((string)$dep);
				if ($dep !== '' && $dep !== $id) { // ignore self-dependency
					$depPrefix = 'pack:' . $dep;
					$dependsEdges[] = ['from' => $packPrefix, 'to' => $depPrefix];
				}
			}
		}

		$hasCycle = $this->detectCycle($dependsEdges);
		$roots = $this->findRoots($allPackIds, $dependsEdges);

		return [
			'containsEdges' => $containsEdges,
			'dependsEdges'  => $dependsEdges,
			'roots'         => $roots,
			'hasCycle'      => $hasCycle,
		];
	}

	/**
	 * Detect if a cycle exists in the pack dependency graph
	 * using topological sorting (Kahn's algorithm).
	 *
	 * @param array<array{from:string,to:string}> $edges
	 * @return bool True if a cycle exists, false if DAG
	 */
	private function detectCycle(array $edges): bool {
		if (empty($edges)) {
			return false;
		}

		$adj = [];
		$inDegree = [];

		foreach ($edges as $e) {
			$from = $e['from'];
			$to = $e['to'];

			$adj[$from][] = $to;
			$inDegree[$to] = ($inDegree[$to] ?? 0) + 1;
			$inDegree[$from] = $inDegree[$from] ?? 0;
		}

		// Nodes with no incoming edges
		$queue = array_keys(array_filter($inDegree, static fn(int $d) => $d === 0));
		$visited = 0;

		while ($queue) {
			$node = array_shift($queue);
			$visited++;
			foreach ($adj[$node] ?? [] as $neighbor) {
				$inDegree[$neighbor]--;
				if ($inDegree[$neighbor] === 0) {
					$queue[] = $neighbor;
				}
			}
		}

		// If all nodes visited, graph is acyclic
		return $visited !== count($inDegree);
	}

	/**
	 * Identify "root" packs with no incoming dependencies.
	 *
	 * @param array<string,bool> $packIds Map of all pack IDs
	 * @param array<array{from:string,to:string}> $edges Dependency edges
	 * @return string[] List of root pack IDs
	 */
	private function findRoots(array $packIds, array $edges): array {
		if (empty($packIds)) {
			return [];
		}

		$hasParent = [];
		foreach ($edges as $e) {
			$to = $e['to'];
			if ($to !== '') {
				$hasParent[$to] = true;
			}
		}

		// Packs with no inbound edges
		$roots = array_diff(array_keys($packIds), array_keys($hasParent));

		sort($roots); // deterministic order for cache comparison and testing
		return array_values($roots);
	}
}
