<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Schema\ManifestSchemaAdapter;
use LabkiPackManager\Schema\SchemaResolver;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads raw YAML from a URL or store and maps to domain.
 */
final class ManifestLoader {
    private ManifestFetcher $fetcher;
    private ManifestSchemaAdapter $adapter;
    private ManifestValidator $validator;

    public function __construct( ?ManifestFetcher $fetcher = null, ?ManifestSchemaAdapter $adapter = null, ?ManifestValidator $validator = null ) {
        $this->fetcher = $fetcher ?? new ManifestFetcher();
        $this->adapter = $adapter ?? new ManifestSchemaAdapter();
        $this->validator = $validator ?? new ManifestValidator( null, new SchemaResolver() );
    }

    /**
     * @return array{schema_version: string|null, packs: array, errorKey?: string}
     */
    public function loadFromUrl( string $manifestUrl, bool $refresh = false ): array {
        $status = $this->fetcher->fetchManifestFromUrl( $manifestUrl );
        if ( !method_exists( $status, 'isOK' ) || !$status->isOK() ) {
            return [ 'schema_version' => null, 'packs' => [], 'errorKey' => $this->extractStatusKey( $status ) ];
        }
        $val = $status->getValue();
        if ( is_array( $val ) && isset( $val['packs'] ) ) {
            $raw = [ 'schema_version' => $val['schema_version'] ?? null, 'packs' => [] ];
            foreach ( $val['packs'] as $p ) {
                $raw['packs'][] = [
                    'id' => $p['id'] ?? '',
                    'description' => $p['description'] ?? null,
                    'version' => $p['version'] ?? null,
                    'depends' => $p['depends_on'] ?? [],
                    'pages' => $p['pages'] ?? [],
                ];
            }
            return $this->adapter->toDomain( $raw );
        }
        return [ 'schema_version' => null, 'packs' => [] ];
	}

    private function extractStatusKey( $status ) : ?string {
        try {
            if ( method_exists( $status, 'getMessage' ) && is_object( $status->getMessage() ) && method_exists( $status->getMessage(), 'getKey' ) ) {
                return $status->getMessage()->getKey();
            }
            if ( method_exists( $status, 'getMessageValue' ) && is_object( $status->getMessageValue() ) && method_exists( $status->getMessageValue(), 'getKey' ) ) {
                return $status->getMessageValue()->getKey();
            }
        } catch ( \Throwable $e ) {}
        return null;
    }
}


