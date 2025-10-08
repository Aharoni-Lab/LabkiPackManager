<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
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
     * @return Status containing string YAML body on success or fatal error
     */
    public function fetch(string $repoUrl): Status {
        $manifestUrl = $this->resolveManifestUrl($repoUrl);        
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
        $manifestUrl = $this->resolveManifestUrl($repoUrl);
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
     * Resolve a repository/base URL to the concrete manifest file location.
     *
     * Supported forms:
     *  - github.com repo URLs (optionally with /tree/<ref> or /blob/<ref>/manifest.yml)
     *    are converted to raw.githubusercontent.com URLs targeting manifest.yml
     *  - raw.githubusercontent.com base URLs: ensure trailing manifest.yml when pointing at a dir
     *  - Generic HTTP(S) base URLs ending with a slash get manifest.yml appended
     */
    private function resolveManifestUrl(string $repoUrl): string {
        $trimRepoUrl = trim($repoUrl);

        if ($trimRepoUrl === '') {
            return $trimRepoUrl;
        }

        // --- HTTP(S) URLs ---
        $parts = @parse_url($trimRepoUrl) ?: [];
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        // Helper to rebuild URL from parts
        $rebuild = function(array $p): string {
            $scheme = $p['scheme'] ?? 'https';
            $host = $p['host'] ?? '';
            $path = $p['path'] ?? '';
            $query = isset($p['query']) ? ('?' . $p['query']) : '';
            $fragment = isset($p['fragment']) ? ('#' . $p['fragment']) : '';
            return $scheme . '://' . $host . $path . $query . $fragment;
        };

        // If already points to a manifest file, return as-is
        if ($path !== '' && (str_ends_with($path, '/manifest.yml') || str_ends_with($path, '/manifest.yaml') || preg_match('~manifest\.(ya?ml)$~i', basename($path)))) {
            return $trimRepoUrl;
        }

        // GitHub canonical URLs → raw.githubusercontent.com
        if ($host === 'github.com' || $host === 'www.github.com') {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            if (count($segments) >= 2) {
                $owner = $segments[0];
                $repo = $segments[1];

                // Default branch from config (fallback to main)
                $defaultRef = 'main';
                try {
                    $cfg = MediaWikiServices::getInstance()->getMainConfig();
                    $val = (string)$cfg->get('LabkiDefaultBranch');
                    if ($val !== '') {
                        $defaultRef = $val;
                    }
                } catch (\Throwable $e) {}
                /*** 
                * github.com/<owner>/<repo>/tree/<ref>/<subpath?>
                * github.com/<owner>/<repo>/blob/<ref>/<path>
                */
                $ref = $defaultRef;
                $subPath = '';
                if (isset($segments[2]) && ($segments[2] === 'tree' || $segments[2] === 'blob')) {
                    $ref = $segments[3] ?? $defaultRef;
                    $subPath = implode('/', array_slice($segments, 4));
                }

                // Build raw URL
                $raw = [
                    'scheme' => 'https',
                    'host' => 'raw.githubusercontent.com',
                    'path' => '/' . $owner . '/' . $repo . '/' . $ref
                        . ($subPath !== '' ? '/' . $subPath : '')
                        . (str_ends_with($subPath, '/manifest.yml') || str_ends_with($subPath, '/manifest.yaml') ? '' : '/manifest.yml'),
                ];
                return $rebuild($raw);
            }
            // If not enough segments, fall through to generic behavior
        }

        // raw.githubusercontent.com base without explicit file → ensure manifest.yml
        if ($host === 'raw.githubusercontent.com' || $host === 'raw.fastgit.org') {
            if ($path !== '' && !preg_match('~/manifest\.(ya?ml)$~i', $path)) {
                $parts['path'] = rtrim($path, '/') . '/manifest.yml';
                return $rebuild($parts);
            }
            return $trimRepoUrl;
        }

        // Generic: if ends with a slash, assume directory and append manifest.yml
        if ($path === '' || str_ends_with($path, '/')) {
            $parts['path'] = rtrim($path, '/') . '/manifest.yml';
            return $rebuild($parts);
        }

        return $trimRepoUrl;
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
