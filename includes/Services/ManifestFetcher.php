<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use LabkiPackManager\Parser\ManifestParser;

class ManifestFetcher {
    /**
     * Optional HTTP request factory for testability. When null, resolves via MediaWikiServices.
     * @var object|null
     */
    private $httpRequestFactory;
    /** @var array<string,mixed>|null */
    private ?array $configuredSources = null;

    public function __construct( $httpRequestFactory = null, ?array $sources = null ) {
        $this->httpRequestFactory = $httpRequestFactory;
        $this->configuredSources = $sources;
    }
    /**
     * Fetch and parse the manifest from the configured URL.
     *
     * @return StatusValue StatusValue::newGood( array $packs ) on success; newFatal on failure
     */
    public function fetchManifest() {
        // Prefer explicitly provided sources (tests) → global → MW services
        $sources = $this->configuredSources ?? ( $GLOBALS['wgLabkiContentSources'] ?? null );
        if ( $sources === null && class_exists( '\MediaWiki\MediaWikiServices' ) ) {
            try {
                $config = MediaWikiServices::getInstance()->getMainConfig();
                $sources = $config->get( 'LabkiContentSources' );
            } catch ( \LogicException $e ) {
                // Unit tests without MW service container; keep $sources as null
            }
        }
        if ( is_array( $sources ) && $sources ) {
            $first = reset( $sources );
            $url = (string)$first;
            if ( $url !== '' ) {
                return $this->fetchManifestFromUrl( $url );
            }
        }
        return $this->newFatal( 'labkipackmanager-error-no-sources' );
    }

    /**
     * Fetch and parse the root YAML manifest from a specific URL.
     *
     * @param string $url
     * @return StatusValue StatusValue::newGood( string $yaml ) on success; newFatal on failure
     */
    public function fetchManifestFromUrl( string $url ) {
        $factory = $this->httpRequestFactory ?? MediaWikiServices::getInstance()->getHttpRequestFactory();

        // Support local file paths in addition to HTTP(S)
        $body = null;
        $trimUrl = trim( $url );
        $isFileScheme = str_starts_with( $trimUrl, 'file://' );
        $isAbsolutePath = !$isFileScheme && ( preg_match( '~^/|^[A-Za-z]:[\\/]~', $trimUrl ) === 1 );
        if ( $isFileScheme || $isAbsolutePath ) {
            $path = $isFileScheme ? substr( $trimUrl, 7 ) : $trimUrl;
            if ( !is_readable( $path ) ) {
                return $this->newFatal( 'labkipackmanager-error-fetch' );
            }
            $content = @file_get_contents( $path );
            if ( $content === false || $content === '' ) {
                return $this->newFatal( 'labkipackmanager-error-fetch' );
            }
            $body = $content;
        } else {
            $req = $factory->create( $trimUrl, [ 'method' => 'GET', 'timeout' => 10 ] );
            $status = $req->execute();
            if ( !$status->isOK() ) {
                return $this->newFatal( 'labkipackmanager-error-fetch' );
            }
            $code = $req->getStatus();
            $content = $req->getContent();
            if ( $code !== 200 || $content === '' ) {
                return $this->newFatal( 'labkipackmanager-error-fetch' );
            }
            $body = $content;
        }

        // Return raw YAML; validation/parsing handled by ManifestLoader layer
        return $this->newGood( $body );
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


