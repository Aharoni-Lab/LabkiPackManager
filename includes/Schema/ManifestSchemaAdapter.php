<?php

declare(strict_types=1);

namespace LabkiPackManager\Schema;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;

/**
 * Maps raw manifest arrays to domain objects.
 * Supports schema versioning via simple branching for now.
 */
final class ManifestSchemaAdapter {
	/**
	 * @param array<string,mixed> $rawManifest
	 * @return array{schema_version: string|null, packs: Pack[]}
	 */
	public function toDomain( array $rawManifest ): array {
		$schema = isset( $rawManifest['schema_version'] ) ? (string)$rawManifest['schema_version'] : null;
		$packs = [];
		$rawPacks = [];
		if ( isset( $rawManifest['packs'] ) && is_array( $rawManifest['packs'] ) ) {
			$rawPacks = $rawManifest['packs'];
		} elseif ( isset( $rawManifest[0] ) ) {
			$rawPacks = $rawManifest; // tolerate array root
		}
		foreach ( $rawPacks as $p ) {
			if ( !is_array( $p ) ) {
				continue;
			}
			$idStr = (string)( $p['id'] ?? '' );
			if ( $idStr === '' ) {
				continue;
			}
			$dependsOnPacks = [];
			$includedPages = [];
			if ( isset( $p['depends'] ) && is_array( $p['depends'] ) ) {
				foreach ( $p['depends'] as $dep ) {
					$depStr = (string)$dep;
					if ( $depStr !== '' ) {
						$dependsOnPacks[] = new PackId( $depStr );
					}
				}
			}
			if ( isset( $p['pages'] ) && is_array( $p['pages'] ) ) {
				foreach ( $p['pages'] as $pg ) {
					$pgStr = (string)$pg;
					if ( $pgStr !== '' ) {
						$includedPages[] = new PageId( $pgStr );
					}
				}
			}
			$packs[] = new Pack(
				new PackId( $idStr ),
				isset( $p['description'] ) ? (string)$p['description'] : null,
				isset( $p['version'] ) ? (string)$p['version'] : null,
				$dependsOnPacks,
				$includedPages
			);
		}
		return [ 'schema_version' => $schema, 'packs' => $packs ];
	}
}


