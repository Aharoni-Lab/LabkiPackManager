<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\StatusValue;
use LabkiPackManager\Parser\ManifestParser;

class ManifestFetcher {
    /**
     * Fetch and parse the root YAML manifest from the configured URL.
     *
     * @return StatusValue StatusValue::newGood( array $packs ) on success; newFatal on failure
     */
    public function fetchRootManifest() : StatusValue {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $url = $config->get( 'LabkiContentManifestURL' );

        $httpFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
        $req = $httpFactory->create( $url, [ 'method' => 'GET', 'timeout' => 10 ] );
        $status = $req->execute();
        if ( !$status->isOK() ) {
            return StatusValue::newFatal( 'labkipackmanager-error-fetch' );
        }

        $code = $req->getStatus();
        $body = $req->getContent();
        if ( $code !== 200 || $body === '' ) {
            return StatusValue::newFatal( 'labkipackmanager-error-fetch' );
        }

        $parser = new ManifestParser();
        try {
            $packs = $parser->parseRoot( $body );
        } catch ( \InvalidArgumentException $e ) {
            $msg = $e->getMessage() === 'Invalid YAML' ? 'labkipackmanager-error-parse' : 'labkipackmanager-error-schema';
            return StatusValue::newFatal( $msg );
        }

        return StatusValue::newGood( $packs );
    }
}


