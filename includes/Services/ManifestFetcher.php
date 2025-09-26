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
     * @return StatusValue StatusValue::newGood( array $packs ) on success; newFatal on failure
     */
    public function fetchManifestFromUrl( string $url ) {
        $factory = $this->httpRequestFactory ?? MediaWikiServices::getInstance()->getHttpRequestFactory();
        $req = $factory->create( $url, [ 'method' => 'GET', 'timeout' => 10 ] );
        $status = $req->execute();
        if ( !$status->isOK() ) {
            return $this->newFatal( 'labkipackmanager-error-fetch' );
        }

        $code = $req->getStatus();
        $body = $req->getContent();
        if ( $code !== 200 || $body === '' ) {
            return $this->newFatal( 'labkipackmanager-error-fetch' );
        }

        $parser = new ManifestParser();
        try {
            $packs = $parser->parse( $body );
        } catch ( \InvalidArgumentException $e ) {
            $msg = $e->getMessage() === 'Invalid YAML' ? 'labkipackmanager-error-parse' : 'labkipackmanager-error-schema';
            return $this->newFatal( $msg );
        }

        return $this->newGood( $packs );
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


