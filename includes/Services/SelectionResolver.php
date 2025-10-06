<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\Pack;

// THIS IS GOING TO GET ABSORBED INTO FRONTEND
/**
 * Resolves dependency closure and final page list without I/O.
 */
final class SelectionResolver {
    /**
     * @param Pack[] $packs
     * @param string[] $selectedPackIds
     * @return array{packs: string[], pages: string[], pageOwners: array<string,string[]>}
     */
    public function resolve( array $packs, array $selectedPackIds ): array {
		$graph = $this->buildDependsAdjacency( $packs );
		$closure = $this->closure( $selectedPackIds, $graph );
        $pages = [];
        $owners = [];
		$selectedSet = array_flip( $closure );
		foreach ( $packs as $p ) {
			if ( isset( $selectedSet[$p->getIdString()] ) ) {
				foreach ( $p->getIncludedPages() as $pg ) {
                    $id = (string)$pg;
                    $pages[$id] = true;
                    $owners[$id][] = $p->getIdString();
				}
			}
		}
        return [ 'packs' => $closure, 'pages' => array_keys( $pages ), 'pageOwners' => $owners ];
	}

	/**
	 * Like resolve(), but also returns lock reasons for implicitly required packs.
	 * @param Pack[] $packs
	 * @param string[] $selectedPackIds
	 * @return array{packs: string[], pages: string[], locks: array<string,string>}
	 */
    public function resolveWithLocks( array $packs, array $selectedPackIds ): array {
		$graph = $this->buildDependsAdjacency( $packs );
		$closure = $this->closure( $selectedPackIds, $graph );
		$selectedDirect = array_flip( $selectedPackIds );
		$locks = [];
		foreach ( $selectedPackIds as $src ) {
			foreach ( $this->dfsCollect( $src, $graph ) as $dep ) {
				if ( !isset( $selectedDirect[$dep] ) ) {
					$locks[$dep] = "Required by $src";
				}
			}
		}
        $base = $this->resolve( $packs, $selectedPackIds );
        return [ 'packs' => $base['packs'], 'pages' => $base['pages'], 'locks' => $locks, 'pageOwners' => $base['pageOwners'] ];
	}

	/**
	 * @param Pack[] $packs
	 * @return array<string,string[]>
	 */
	private function buildDependsAdjacency( array $packs ): array {
		$adj = [];
		foreach ( $packs as $p ) {
			$from = $p->getIdString();
			foreach ( $p->getDependsOnPacks() as $dep ) {
				$adj[$from][] = (string)$dep;
			}
			$adj[$from] = $adj[$from] ?? [];
		}
		return $adj;
	}

	/**
	 * @param string[] $selected
	 * @param array<string,string[]> $adj
	 * @return string[]
	 */
	private function closure( array $selected, array $adj ): array {
		$seen = [];
		foreach ( $selected as $s ) {
			$this->dfs( $s, $adj, $seen );
		}
		return array_keys( $seen );
	}

	private function dfs( string $node, array $adj, array &$seen ): void {
		if ( isset( $seen[$node] ) ) { return; }
		$seen[$node] = true;
		foreach ( $adj[$node] ?? [] as $nbr ) { $this->dfs( $nbr, $adj, $seen ); }
	}

	/**
	 * @return string[] all nodes reachable from $src (excluding $src)
	 */
	private function dfsCollect( string $src, array $adj ): array {
		$seen = [];
		$this->dfs( $src, $adj, $seen );
		unset( $seen[$src] );
		return array_keys( $seen );
	}
}


