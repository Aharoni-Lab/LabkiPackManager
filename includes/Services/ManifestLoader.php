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
	 * @return array{schema_version: string|null, packs: array}
	 */
	public function loadFromUrl( string $manifestUrl ): array {
        $status = $this->fetcher->fetchManifestFromUrl( $manifestUrl );
        if ( !method_exists( $status, 'isOK' ) || !$status->isOK() ) {
            return [ 'schema_version' => null, 'packs' => [] ];
        }
        $val = $status->getValue();
        $yaml = is_string( $val ) ? $val : Yaml::dump( $val );
        $validated = $this->validator->validate( $yaml );
        if ( !$validated->isOK() ) {
            return [ 'schema_version' => null, 'packs' => [] ];
        }
        $raw = $validated->getValue();
        return $this->adapter->toDomain( is_array( $raw ) ? $raw : [] );
	}
}


