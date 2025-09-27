<?php

namespace LabkiPackManager\Parser;

use Symfony\Component\Yaml\Yaml;

class ManifestParser {
    /**
     * Parse a manifest YAML string to a normalized packs array.
     *
     * @param string $yaml
     * @return array packs: [ [id, version, description], ... ]
     * @throws \InvalidArgumentException when YAML is invalid or schema is wrong
     */
    public function parse( string $yaml ) : array {
        if ( trim( $yaml ) === '' ) {
            throw new \InvalidArgumentException( 'Empty YAML' );
        }
        try {
            $parsed = Yaml::parse( $yaml );
        } catch ( \Throwable $e ) {
            throw new \InvalidArgumentException( 'Invalid YAML' );
        }
        if ( !is_array( $parsed ) || !isset( $parsed['packs'] ) || !is_array( $parsed['packs'] ) ) {
            throw new \InvalidArgumentException( 'Invalid schema: missing packs' );
        }
        // New schema: packs is an object mapping id => metadata; pages is a registry
        $normalized = [];
        $pagesRegistry = is_array( $parsed['pages'] ?? null ) ? $parsed['pages'] : [];
        foreach ( $parsed['packs'] as $packId => $meta ) {
            if ( !is_string( $packId ) || !is_array( $meta ) ) {
                continue;
            }
            $version = (string)( $meta['version'] ?? '' );
            $description = (string)( $meta['description'] ?? '' );
            $pages = [];
            if ( isset( $meta['pages'] ) && is_array( $meta['pages'] ) ) {
                foreach ( $meta['pages'] as $title ) {
                    if ( is_string( $title ) ) {
                        $pages[] = $title;
                    }
                }
            }
            $depends = [];
            if ( isset( $meta['depends_on'] ) && is_array( $meta['depends_on'] ) ) {
                foreach ( $meta['depends_on'] as $dep ) {
                    if ( is_string( $dep ) ) {
                        $depends[] = $dep;
                    }
                }
            }
            $tags = [];
            if ( isset( $meta['tags'] ) && is_array( $meta['tags'] ) ) {
                foreach ( $meta['tags'] as $tag ) {
                    if ( is_string( $tag ) ) {
                        $tags[] = $tag;
                    }
                }
            }
            $normalized[] = [
                'id' => $packId,
                'version' => $version,
                'description' => $description,
                'pages' => $pages,
                'page_count' => count( $pages ),
                'depends_on' => $depends,
                'tags' => $tags,
            ];
        }
        return $normalized;
    }
}


