<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\Pack;

/**
 * Resolves dependency closure and final page list without I/O.
 */
final class SelectionResolver {
	/**
	 * @param Pack[] $packs
	 * @param string[] $selectedPackIds
	 * @return array{packs: string[], pages: string[]}
	 */
	public function resolve( array $packs, array $selectedPackIds ): array {
		$selected = [];
		foreach ( $selectedPackIds as $id ) {
			$selected[$id] = true;
		}
		$pages = [];
		foreach ( $packs as $p ) {
			if ( isset( $selected[$p->getIdString()] ) ) {
				foreach ( $p->getIncludedPages() as $pg ) {
					$pages[(string)$pg] = true;
				}
			}
		}
		return [ 'packs' => array_keys( $selected ), 'pages' => array_keys( $pages ) ];
	}
}


