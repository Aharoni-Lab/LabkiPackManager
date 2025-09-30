<?php

declare(strict_types=1);

namespace LabkiPackManager\Schema;

use MediaWiki\MediaWikiServices;

/**
 * Resolves and fetches manifest JSON Schemas from labki-packs-tools (or a configured index).
 */
final class SchemaResolver {
    /** @var object|null */
    private $httpRequestFactory;

    /** @var array<string,mixed>|null */
    private ?array $config;

    public function __construct( $httpRequestFactory = null, ?array $config = null ) {
        $this->httpRequestFactory = $httpRequestFactory;
        $this->config = $config;
    }

    public function resolveManifestSchemaUrl( string $schemaVersion ) : ?string {
        $indexUrl = $this->getConfigValue( 'LabkiSchemaIndexUrl' )
            ?? 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs-tools/main/schema/index.json';
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

    /**
     * Fetch a JSON document as an associative array or null on failure.
     */
    public function fetchSchemaJson( string $url ) : mixed {
        return $this->fetchJson( $url );
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

    private function getConfigValue( string $key ) {
        if ( is_array( $this->config ) && array_key_exists( $key, $this->config ) ) {
            return $this->config[$key];
        }
        // Try MW config if available
        try {
            $services = MediaWikiServices::getInstance();
            $conf = $services->getMainConfig();
            if ( $conf->has( $key ) ) {
                return $conf->get( $key );
            }
        } catch ( \Throwable $e ) {
            // ignore when not in MW runtime
        }
        return null;
    }
}


