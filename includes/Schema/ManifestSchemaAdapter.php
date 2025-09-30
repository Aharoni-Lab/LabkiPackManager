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

        // First pass: build IDs and basic structures
        $allPackIds = [];
        $allPageIds = [];
        foreach ( $rawPacks as $p ) {
			if ( !is_array( $p ) ) {
				continue;
			}
			$idStr = (string)( $p['id'] ?? '' );
			if ( $idStr === '' ) {
				continue;
			}
            $allPackIds[$idStr] = true;
            if ( isset( $p['pages'] ) && is_array( $p['pages'] ) ) {
                foreach ( $p['pages'] as $pg ) {
                    $pgStr = (string)$pg;
                    if ( $pgStr !== '' ) {
                        $allPageIds[$pgStr] = true;
                    }
                }
            }
		}

        // Second pass: map with validation of references and rules
        foreach ( $rawPacks as $p ) {
            if ( !is_array( $p ) ) { continue; }
            $idStr = (string)( $p['id'] ?? '' );
            if ( $idStr === '' ) { continue; }

            $containedPacks = [];
            $dependsOnPacks = [];
            $includedPages = [];

            if ( isset( $p['contains'] ) && is_array( $p['contains'] ) ) {
                foreach ( $p['contains'] as $childPackId ) {
                    $child = (string)$childPackId;
                    if ( $child !== '' ) {
                        if ( !isset( $allPackIds[$child] ) ) {
                            throw new \InvalidArgumentException( "Pack '$idStr' contains unknown pack '$child'" );
                        }
                        $containedPacks[] = new PackId( $child );
                    }
                }
            }

            if ( isset( $p['depends'] ) && is_array( $p['depends'] ) ) {
                foreach ( $p['depends'] as $dep ) {
                    $depStr = (string)$dep;
                    if ( $depStr !== '' ) {
                        if ( !isset( $allPackIds[$depStr] ) ) {
                            throw new \InvalidArgumentException( "Pack '$idStr' depends on unknown pack '$depStr'" );
                        }
                        $dependsOnPacks[] = new PackId( $depStr );
                    }
                }
            }

            if ( isset( $p['pages'] ) && is_array( $p['pages'] ) ) {
                foreach ( $p['pages'] as $pg ) {
                    $pgStr = (string)$pg;
                    if ( $pgStr !== '' ) {
                        // All pages are declared inline; just map to VO
                        $includedPages[] = new PageId( $pgStr );
                    }
                }
            }

            // Semantic rule: A pack must contain ≥1 page OR ≥2 packs.
            $numPages = count( $includedPages );
            $numChildPacks = count( $containedPacks );
            if ( $numPages < 1 && $numChildPacks < 2 ) {
                throw new \InvalidArgumentException( "Pack '$idStr' must contain at least 1 page or at least 2 packs" );
            }

            $packs[] = new Pack(
                new PackId( $idStr ),
                isset( $p['description'] ) ? (string)$p['description'] : null,
                isset( $p['version'] ) ? (string)$p['version'] : null,
                $containedPacks,
                $dependsOnPacks,
                $includedPages
            );
        }

        return [ 'schema_version' => $schema, 'packs' => $packs ];
	}
}


