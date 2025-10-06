<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\StatusValue;
use LabkiPackManager\Parser\ManifestParser;

final class ManifestFetcher {

    private $httpRequestFactory;

    public function __construct( $httpRequestFactory = null ) {
        $this->httpRequestFactory = $httpRequestFactory
            ?? MediaWikiServices::getInstance()->getHttpRequestFactory();
    }

    /**
     * Fetch and parse a manifest from the given URL or file path.
     *
     * @param string $url URL or file:// path
     * @return StatusValue containing ['packs' => array, 'schema_version' => string|null]
     */
    public function fetch( string $url ): StatusValue {
        $body = $this->getRawManifest($url);
        if ( !$body->isOK() ) {
            return $body;
        }

        $parser = new ManifestParser();
        try {
            $parsed = $parser->parse( $body->getValue() );
        } catch ( \Throwable $e ) {
            return StatusValue::newFatal( 'labkipackmanager-error-parse' );
        }

        return StatusValue::newGood([
            'packs' => $parsed['packs'] ?? [],
            'schema_version' => $parsed['schema_version'] ?? null,
        ]);
    }

    /**
     * Retrieve the raw manifest file contents, local or remote.
     *
     * @param string $url
     * @return StatusValue::newGood(string $body) or newFatal(error)
     */
    private function getRawManifest( string $url ): StatusValue {
        $trim = trim($url);

        // Case 1: Local file (supports file:// or absolute path)
        if ( str_starts_with($trim, 'file://') || preg_match('~^/|^[A-Za-z]:[\\/]~', $trim) ) {
            $path = str_starts_with($trim, 'file://') ? substr($trim, 7) : $trim;
            if ( !is_readable($path) ) {
                return StatusValue::newFatal('labkipackmanager-error-fetch');
            }
            $content = @file_get_contents($path);
            return ($content && $content !== '')
                ? StatusValue::newGood($content)
                : StatusValue::newFatal('labkipackmanager-error-fetch');
        }

        // Case 2: Remote HTTP(S)
        try {
            $req = $this->httpRequestFactory->create($trim, [
                'method' => 'GET',
                'timeout' => 10,
            ]);
            $status = $req->execute();
            if ( !$status->isOK() ) {
                return StatusValue::newFatal('labkipackmanager-error-fetch');
            }

            $content = $req->getContent();
            $code = $req->getStatus();
            if ( $code !== 200 || $content === '' ) {
                return StatusValue::newFatal('labkipackmanager-error-fetch');
            }

            return StatusValue::newGood($content);
        } catch ( \Throwable $e ) {
            return StatusValue::newFatal('labkipackmanager-error-fetch');
        }
    }
}
