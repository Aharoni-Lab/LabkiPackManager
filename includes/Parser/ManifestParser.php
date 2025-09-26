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
        foreach ( $parsed['packs'] as $packId => $meta ) {
            if ( !is_string( $packId ) || !is_array( $meta ) ) {
                continue;
            }
            $version = (string)( $meta['version'] ?? '' );
            $description = (string)( $meta['description'] ?? '' );
            $normalized[] = [
                'id' => $packId,
                'version' => $version,
                'description' => $description,
            ];
        }
        return $normalized;
    }
}


