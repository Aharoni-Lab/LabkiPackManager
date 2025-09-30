<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;

/**
 * Builds dependency graph and provides cycle detection and topo sort.
 */
final class GraphBuilder {
	/**
	 * @param Pack[] $packs
	 * @return array{
	 *   containsEdges: array<array{from:string,to:string}>,
	 *   dependsEdges: array<array{from:string,to:string}>,
	 *   hasCycle: bool,
	 *   cycle?: string[],
	 *   transitiveDepends: array<string,string[]>,
	 *   reverseDepends: array<string,string[]>,
	 *   rootPacks: string[]
	 * }
	 */
	public function build( array $packs ): array {
		$contains = [];
		$depends = [];
		$idSet = [];
		foreach ( $packs as $p ) {
			$id = $p->getIdString();
			$idSet[$id] = true;
			foreach ( $p->getContainedPacks() as $child ) {
				$contains[] = [ 'from' => $id, 'to' => (string)$child ];
			}
			foreach ( $p->getContainedPages() as $pg ) {
				$contains[] = [ 'from' => $id, 'to' => (string)$pg ];
			}
			foreach ( $p->getDependsOnPacks() as $dep ) {
				$depends[] = [ 'from' => $id, 'to' => (string)$dep ];
			}
		}

		$cycleInfo = $this->detectCycle( $depends );
		$rev = $this->reverseDeps( $depends );
		$trans = $this->transitiveDeps( $depends );
		$roots = $this->rootPacks( $idSet, $depends );

		return [
			'containsEdges' => $contains,
			'dependsEdges' => $depends,
			'hasCycle' => $cycleInfo['hasCycle'],
			'cycle' => $cycleInfo['cycle'] ?? null,
			'transitiveDepends' => $trans,
			'reverseDepends' => $rev,
			'rootPacks' => $roots,
		];
	}

	/**
	 * @param array<array{from:string,to:string}> $edges
	 * @return array{hasCycle: bool, cycle?: string[]}
	 */
	private function detectCycle( array $edges ): array {
		$inDegree = [];
		$adj = [];
		foreach ( $edges as $e ) {
			$from = $e['from']; $to = $e['to'];
			$adj[$from][] = $to;
			$inDegree[$to] = ($inDegree[$to] ?? 0) + 1;
			$inDegree[$from] = $inDegree[$from] ?? 0;
		}
		$queue = [];
		foreach ( $inDegree as $node => $deg ) {
			if ( $deg === 0 ) { $queue[] = $node; }
		}
		$visited = 0;
		while ( $queue ) {
			$node = array_shift( $queue );
			$visited++;
			foreach ( $adj[$node] ?? [] as $nbr ) {
				$inDegree[$nbr]--;
				if ( $inDegree[$nbr] === 0 ) { $queue[] = $nbr; }
			}
		}
		$total = count( $inDegree );
		if ( $visited !== $total ) {
			return [ 'hasCycle' => true ];
		}
		return [ 'hasCycle' => false ];
	}

	/**
	 * @param array<array{from:string,to:string}> $edges
	 * @return array<string,string[]>
	 */
	private function reverseDeps( array $edges ): array {
		$out = [];
		foreach ( $edges as $e ) {
			$out[$e['to']][] = $e['from'];
		}
		foreach ( $out as $k => $arr ) { $out[$k] = array_values( array_unique( $arr ) ); }
		return $out;
	}

	/**
	 * @param array<array{from:string,to:string}> $edges
	 * @return array<string,string[]>
	 */
	private function transitiveDeps( array $edges ): array {
		$adj = [];
		foreach ( $edges as $e ) { $adj[$e['from']][] = $e['to']; }
		$out = [];
		foreach ( array_keys( $adj ) as $node ) {
			$seen = [];
			$this->dfs( $node, $adj, $seen );
			unset( $seen[$node] );
			$out[$node] = array_keys( $seen );
		}
		return $out;
	}

	private function dfs( string $node, array $adj, array &$seen ): void {
		if ( isset( $seen[$node] ) ) { return; }
		$seen[$node] = true;
		foreach ( $adj[$node] ?? [] as $nbr ) { $this->dfs( $nbr, $adj, $seen ); }
	}

	/**
	 * @param array<string,bool> $idSet
	 * @param array<array{from:string,to:string}> $depends
	 * @return string[]
	 */
	private function rootPacks( array $idSet, array $depends ): array {
		$hasOutgoing = [];
		foreach ( $depends as $e ) { $hasOutgoing[$e['from']] = true; $hasOutgoing[$e['to']] = $hasOutgoing[$e['to']] ?? false; }
		$roots = [];
		foreach ( $idSet as $id => $_ ) { if ( empty( $hasOutgoing[$id] ) ) { $roots[] = $id; } }
		return $roots;
	}
}


