<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use LabkiPackManager\Parser\ManifestParser;

class ManifestFetcher {
    /**
     * Fetch and parse the root YAML manifest from the configured URL.
     *
     * @return StatusValue StatusValue::newGood( array $packs ) on success; newFatal on failure
     */
    public function fetchRootManifest() {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $sources = $config->get( 'LabkiContentSources' );
        if ( is_array( $sources ) && $sources ) {
            $first = reset( $sources );
            $url = $first['manifestUrl'] ?? '';
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
        $httpFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
        $req = $httpFactory->create( $url, [ 'method' => 'GET', 'timeout' => 10 ] );
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
            $packs = $parser->parseRoot( $body );
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


