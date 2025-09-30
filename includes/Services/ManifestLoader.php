<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Schema\ManifestSchemaAdapter;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads raw YAML from a URL or store and maps to domain.
 */
final class ManifestLoader {
	private ManifestFetcher $fetcher;
	private ManifestSchemaAdapter $adapter;

	public function __construct( ?ManifestFetcher $fetcher = null, ?ManifestSchemaAdapter $adapter = null ) {
		$this->fetcher = $fetcher ?? new ManifestFetcher();
		$this->adapter = $adapter ?? new ManifestSchemaAdapter();
	}

	/**
	 * @return array{schema_version: string|null, packs: array}
	 */
	public function loadFromUrl( string $manifestUrl ): array {
		$status = $this->fetcher->fetchManifestFromUrl( $manifestUrl );
		if ( method_exists( $status, 'isOK' ) && $status->isOK() ) {
			$val = $status->getValue();
			if ( is_string( $val ) ) {
				$raw = Yaml::parse( $val );
				if ( is_array( $raw ) ) {
					return $this->adapter->toDomain( $raw );
				}
			}
			if ( is_array( $val ) ) {
				return $this->adapter->toDomain( $val );
			}
		}
		return [ 'schema_version' => null, 'packs' => [] ];
	}
}


