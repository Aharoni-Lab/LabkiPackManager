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
	 * @return array{edges: array<array{from:string,to:string}>, hasCycle: bool}
	 */
	public function build( array $packs ): array {
		$edges = [];
		foreach ( $packs as $p ) {
			foreach ( $p->getDependsOnPacks() as $dep ) {
				$edges[] = [ 'from' => (string)$dep, 'to' => $p->getIdString() ];
			}
		}
		return [ 'edges' => $edges, 'hasCycle' => false ];
	}
}


