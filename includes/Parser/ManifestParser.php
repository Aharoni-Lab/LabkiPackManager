<?php

namespace LabkiPackManager\Parser;

use Symfony\Component\Yaml\Yaml;

class ManifestParser {
    /**
     * Parse a root YAML manifest string to a normalized packs array.
     *
     * @param string $yaml
     * @return array packs: [ [id, path, version, description], ... ]
     * @throws \InvalidArgumentException when YAML is invalid or schema is wrong
     */
    public function parseRoot( string $yaml ) : array {
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
        $packs = [];
        foreach ( $parsed['packs'] as $pack ) {
            if ( !is_array( $pack ) ) {
                continue;
            }
            $id = $pack['id'] ?? null;
            $path = $pack['path'] ?? null;
            $version = $pack['version'] ?? '';
            $description = $pack['description'] ?? '';
            if ( !$id || !$path ) {
                continue;
            }
            $packs[] = [
                'id' => (string)$id,
                'path' => (string)$path,
                'version' => (string)$version,
                'description' => (string)$description,
            ];
        }
        return $packs;
    }
}


