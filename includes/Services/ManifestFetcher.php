<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\StatusValue;
use LabkiPackManager\Parser\ManifestParser;

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
     * @return StatusValue containing string YAML body on success or fatal error
     */
    public function fetch(string $url): StatusValue {
        $body = $this->getRawManifest($url);
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
    public function headHash(string $url): ?string {
        $trim = trim($url);

        // Local file case
        if (str_starts_with($trim, 'file://') || preg_match('~^/|^[A-Za-z]:[\\/]~', $trim)) {
            $path = str_starts_with($trim, 'file://') ? substr($trim, 7) : $trim;
            if (is_readable($path)) {
                $content = @file_get_contents($path);
                return $content !== false ? sha1($content) : null;
            }
            return null;
        }

        // Remote HTTP(S)
        try {
            $req = $this->httpRequestFactory->create($trim, [
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
            $fallback = $this->getRawManifest($url);
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
    private function getRawManifest(string $url): StatusValue {
        $trim = trim($url);

        // --- Local file ---
        if (str_starts_with($trim, 'file://') || preg_match('~^/|^[A-Za-z]:[\\/]~', $trim)) {
            $path = str_starts_with($trim, 'file://') ? substr($trim, 7) : $trim;
            if (!is_readable($path)) {
                return StatusValue::newFatal('labkipackmanager-error-fetch');
            }
            $content = @file_get_contents($path);
            return ($content && $content !== '')
                ? StatusValue::newGood($content)
                : StatusValue::newFatal('labkipackmanager-error-fetch');
        }

        // --- Remote HTTP(S) ---
        try {
            $req = $this->httpRequestFactory->create($trim, [
                'method' => 'GET',
                'timeout' => 10,
            ]);
            $status = $req->execute();
            if (!$status->isOK()) {
                return StatusValue::newFatal('labkipackmanager-error-fetch');
            }

            $content = $req->getContent();
            $code = $req->getStatus();
            if ($code !== 200 || $content === '') {
                return StatusValue::newFatal('labkipackmanager-error-fetch');
            }

            return StatusValue::newGood($content);
        } catch (\Throwable $e) {
            return StatusValue::newFatal('labkipackmanager-error-fetch');
        }
    }
}
