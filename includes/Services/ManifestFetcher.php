<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use LabkiPackManager\Parser\ManifestParser;
use LabkiPackManager\Util\UrlResolver;

/**
 * ManifestFetcher
 *
 * Responsible for retrieving manifest YAML files, either from local storage or
 * a remote HTTP(S) source, and providing change detection via lightweight HEAD
 * or hash requests.
 */
final class ManifestFetcher {

    private $httpRequestFactory;

    public function __construct($httpRequestFactory = null) {
        $this->httpRequestFactory = $httpRequestFactory
            ?? MediaWikiServices::getInstance()->getHttpRequestFactory();
    }

    /**
     * Fetch and parse a manifest from the given URL or file path.
     *
     * @param string $url URL or file:// path
     * @return Status containing string YAML body on success or fatal error
     */
    public function fetch(string $repoUrl): Status {
        $manifestUrl = UrlResolver::resolveContentRepoUrl($repoUrl);        
        $body = $this->getRawManifest($manifestUrl);
        if (!$body->isOK()) {
            return $body;
        }

        // Return the raw YAML content (parsing happens higher up)
        return $body;
    }

    /**
     * Perform a lightweight hash or revision check on the given URL.
     *
     * Attempts to extract a stable content signature using:
     *   - ETag header (preferred)
     *   - Last-Modified header (fallback)
     *   - SHA1 of local file contents (for file://)
     *   - SHA1 of fetched body (fallback only if headers not available)
     *
     * @param string $url
     * @return string|null Unique hash string or null if unavailable
     */
    public function headHash(string $repoUrl): ?string {
        $manifestUrl = UrlResolver::resolveContentRepoUrl($repoUrl);
        $trimManifestUrl = trim($manifestUrl);

        // Local filesystem paths are no longer supported

        // Remote HTTP(S)
        try {
            $req = $this->httpRequestFactory->create($trimManifestUrl, [
                'method' => 'HEAD',
                'timeout' => 5,
            ]);
            $status = $req->execute();
            if (!$status->isOK()) {
                return null;
            }

            $headers = $req->getResponseHeaders();
            if (isset($headers['etag'][0])) {
                return trim($headers['etag'][0], '"\'');
            }

            if (isset($headers['last-modified'][0])) {
                return sha1($headers['last-modified'][0]);
            }

            // Fallback: if HEAD didn’t give a signature, do a light GET to hash
            $fallback = $this->getRawManifest($manifestUrl);
            if ($fallback->isOK()) {
                return sha1($fallback->getValue());
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }


    /**
     * Retrieve the raw manifest file contents, local or remote.
     *
     * @param string $url
     * @return StatusValue::newGood(string $body) or newFatal(error)
     */
    private function getRawManifest(string $manifestUrl): Status {
        $trimManifestUrl = trim($manifestUrl);

        // --- Remote HTTP(S) ---
        try {
            $req = $this->httpRequestFactory->create($trimManifestUrl, [
                'method' => 'GET',
                'timeout' => 10,
            ]);
            $status = $req->execute();
            if (!$status->isOK()) {
                return Status::newFatal('labkipackmanager-error-fetch');
            }

            $content = $req->getContent();
            $code = $req->getStatus();
            if ($code !== 200 || $content === '') {
                return Status::newFatal('labkipackmanager-error-fetch');
            }

            return Status::newGood($content);
        } catch (\Throwable $e) {
            return Status::newFatal('labkipackmanager-error-fetch');
        }
    }
}
