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
}


