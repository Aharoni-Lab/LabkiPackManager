<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\Pack;

/**
 * Produces a simple hierarchical view model of packs/pages.
 */
final class HierarchyBuilder {
	/**
	 * @param Pack[] $packs
	 * @return array[]
	 */
	public function buildTree( array $packs ): array {
		$nodes = [];
		foreach ( $packs as $p ) {
			$nodes[] = [
				'type' => 'pack',
				'id' => $p->getIdString(),
				'children' => array_map( static fn( $pg ) => [ 'type' => 'page', 'id' => (string)$pg ], $p->getIncludedPages() ),
			];
		}
		return $nodes;
	}

	/**
	 * Build front-end friendly view-model.
	 *
	 * @param Pack[] $packs
	 * @return array{tree:array,nodes:array,roots:string[],packCount:int,pageCount:int}
	 */
	public function buildViewModel( array $packs ): array {
		$byId = [];
		foreach ( $packs as $p ) { $byId[$p->getIdString()] = $p; }
		// Build contains parent map to find roots
		$hasParent = [];
		foreach ( $packs as $p ) {
			foreach ( $p->getContainedPacks() as $child ) { $hasParent[(string)$child] = true; }
		}
		$roots = [];
		foreach ( $byId as $id => $_ ) { if ( !isset( $hasParent[$id] ) ) { $roots[] = $id; } }

		// Build tree recursively and compute counts
		$nodes = [];
		$tree = [];
		$seenPages = [];
		$compute = function( Pack $p ) use ( &$compute, &$nodes, &$seenPages, $byId ) {
			$childPacks = [];
			$packsBeneath = 0;
			$pagesBeneath = 0;
			foreach ( $p->getContainedPacks() as $childId ) {
				$child = $byId[(string)$childId] ?? null;
				if ( !$child ) { continue; }
				$vm = $compute( $child );
				$childPacks[] = $vm['node'];
				$packsBeneath += 1 + $vm['packsBeneath'];
				$pagesBeneath += $vm['pagesBeneath'];
			}
			$pageChildren = [];
			foreach ( $p->getContainedPages() as $pg ) {
				$id = (string)$pg;
				$pageChildren[] = [ 'type' => 'page', 'id' => $id ];
				$seenPages[$id] = true;
				$pagesBeneath += 1;
			}
			$node = [
				'type' => 'pack',
				'id' => $p->getIdString(),
				'packsBeneath' => $packsBeneath,
				'pagesBeneath' => $pagesBeneath,
				'children' => array_merge( $childPacks, $pageChildren ),
			];
			$nodes['pack:' . $p->getIdString()] = [
				'type' => 'pack',
				'id' => 'pack:' . $p->getIdString(),
				'packsBeneath' => $packsBeneath,
				'pagesBeneath' => $pagesBeneath,
			];
			foreach ( $pageChildren as $pc ) {
				$nodes['page:' . $pc['id']] = [ 'type' => 'page', 'id' => 'page:' . $pc['id'] ];
			}
			return [ 'node' => $node, 'packsBeneath' => $packsBeneath, 'pagesBeneath' => $pagesBeneath ];
		};
		foreach ( $roots as $rid ) {
			$rootPack = $byId[$rid];
			$vm = $compute( $rootPack );
			$tree[] = $vm['node'];
		}
		return [
			'tree' => $tree,
			'nodes' => $nodes,
			'roots' => array_map( static fn( $r ) => 'pack:' . $r, $roots ),
			'packCount' => count( $packs ),
			'pageCount' => count( $seenPages ),
		];
	}
}


