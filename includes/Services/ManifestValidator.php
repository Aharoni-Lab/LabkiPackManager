<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use Symfony\Component\Yaml\Yaml;

class ManifestValidator {
    /** @var object|null */
    private $httpRequestFactory;

    public function __construct( $httpRequestFactory = null ) {
        $this->httpRequestFactory = $httpRequestFactory;
    }
    /**
     * Validate a manifest string and return the decoded array on success.
     * Performs basic checks:
     *  - YAML parseable
     *  - has schema_version (semantic format)
     *  - packs is an object mapping id => metadata
     *  - optional: depends_on refers to known packs; pages array contains strings
     *  - best-effort JSON Schema top-level validation via remote schema
     *
     * @param string $manifestYaml
     * @return StatusValue StatusValue::newGood( array $decoded ) on success; newFatal on failure
     */
    public function validate( string $manifestYaml ) {
        try {
            $decoded = Yaml::parse( $manifestYaml );
        } catch ( \Throwable $e ) {
            return $this->newFatal( 'labkipackmanager-error-parse' );
        }
        if ( !is_array( $decoded ) ) {
            return $this->newFatal( 'labkipackmanager-error-schema' );
        }
        // schema_version
        $schemaVersion = $decoded['schema_version'] ?? null;
        if ( !is_string( $schemaVersion ) || !preg_match( '/^(v)?(\d+)(\.\d+)?(\.\d+)?$/', $schemaVersion ) ) {
            return $this->newFatal( 'labkipackmanager-error-schema' );
        }
        // packs must be an object (associative). YAML empty {} parses to [], allow empty.
        if ( !isset( $decoded['packs'] ) || !is_array( $decoded['packs'] ) ) {
            return $this->newFatal( 'labkipackmanager-error-schema' );
        }
        if ( $decoded['packs'] !== [] && \array_is_list( $decoded['packs'] ) ) {
            return $this->newFatal( 'labkipackmanager-error-schema' );
        }
        // Heuristic: if packs is empty and YAML used [] (sequence) instead of {} (mapping), fail
        if ( $decoded['packs'] === [] && preg_match( '/^\s*packs\s*:\s*\[\s*\]\s*$/m', $manifestYaml ) ) {
            return $this->newFatal( 'labkipackmanager-error-schema' );
        }
        $packIds = array_keys( $decoded['packs'] );
        // optional deeper checks
        foreach ( $decoded['packs'] as $id => $meta ) {
            if ( !is_string( $id ) || !is_array( $meta ) ) {
                return $this->newFatal( 'labkipackmanager-error-schema' );
            }
            if ( isset( $meta['version'] ) && !is_string( $meta['version'] ) ) {
                return $this->newFatal( 'labkipackmanager-error-schema' );
            }
            if ( isset( $meta['pages'] ) ) {
                if ( !is_array( $meta['pages'] ) ) {
                    return $this->newFatal( 'labkipackmanager-error-schema' );
                }
                foreach ( $meta['pages'] as $p ) {
                    if ( !is_string( $p ) ) {
                        return $this->newFatal( 'labkipackmanager-error-schema' );
                    }
                }
            }
            if ( isset( $meta['depends_on'] ) ) {
                if ( !is_array( $meta['depends_on'] ) ) {
                    return $this->newFatal( 'labkipackmanager-error-schema' );
                }
                foreach ( $meta['depends_on'] as $dep ) {
                    if ( !is_string( $dep ) || !in_array( $dep, $packIds, true ) ) {
                        return $this->newFatal( 'labkipackmanager-error-schema' );
                    }
                }
            }
        }

        // Resolve schema URL from index and validate top-level fields
        $schemaUrl = $this->resolveSchemaUrl( $schemaVersion );
        if ( $schemaUrl ) {
            $schema = $this->fetchJson( $schemaUrl );
            if ( is_array( $schema ) && !$this->validateAgainstSchemaTopLevel( $decoded, $schema ) ) {
                return $this->newFatal( 'labkipackmanager-error-schema' );
            }
        }

        return $this->newGood( $decoded );
    }

    private function resolveSchemaUrl( string $schemaVersion ) : ?string {
        $indexUrl = 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs-tools/main/schema/index.json';
        try {
            $data = $this->fetchJson( $indexUrl );
            if ( !is_array( $data ) || !isset( $data['manifest'] ) || !is_array( $data['manifest'] ) ) {
                return null;
            }
            $map = $data['manifest'];
            $path = $map[$schemaVersion] ?? $map['latest'] ?? null;
            if ( is_string( $path ) && $path !== '' ) {
                return 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs-tools/main/schema/' . $path;
            }
        } catch ( \Throwable $e ) {
            return null;
        }
        return null;
    }

    private function fetchJson( string $url ) : mixed {
        $http = $this->httpRequestFactory ?? MediaWikiServices::getInstance()->getHttpRequestFactory();
        $req = $http->create( $url, [ 'method' => 'GET', 'timeout' => 10 ] );
        $ok = $req->execute();
        if ( !$ok->isOK() || $req->getStatus() !== 200 ) {
            return null;
        }
        return json_decode( $req->getContent(), true );
    }

    /**
     * Minimal JSON Schema top-level validation: enforce required fields and simple types.
     */
    private function validateAgainstSchemaTopLevel( array $decoded, array $schema ) : bool {
        $required = is_array( $schema['required'] ?? null ) ? $schema['required'] : [];
        foreach ( $required as $key ) {
            if ( !array_key_exists( $key, $decoded ) ) {
                return false;
            }
        }
        $props = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
        foreach ( $props as $key => $def ) {
            if ( !array_key_exists( $key, $decoded ) ) {
                continue;
            }
            $type = $def['type'] ?? null;
            if ( is_string( $type ) ) {
                if ( $type === 'string' && !is_string( $decoded[$key] ) ) return false;
                if ( $type === 'object' && !is_array( $decoded[$key] ) ) return false;
                if ( $type === 'array' && !is_array( $decoded[$key] ) ) return false;
            }
        }
        return true;
    }

    private function newFatal( string $key ) {
        $statusClass = class_exists( '\\MediaWiki\\Status\\StatusValue' )
            ? '\\MediaWiki\\Status\\StatusValue'
            : '\\StatusValue';
        return $statusClass::newFatal( $key );
    }

    private function newGood( $value ) {
        $statusClass = class_exists( '\\MediaWiki\\Status\\StatusValue' )
            ? '\\MediaWiki\\Status\\StatusValue'
            : '\\StatusValue';
        return $statusClass::newGood( $value );
    }
}


