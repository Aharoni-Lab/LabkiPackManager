<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use LabkiPackManager\Parser\ManifestParser;
use LabkiPackManager\Util\UrlResolver;
use LabkiPackManager\Services\LabkiRefRegistry;

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
     * Fetch and parse a manifest from the given worktree path.
     *
     * @param string $url URL or file:// path
     * @return Status containing string YAML body on success or fatal error
     */
    public function fetch(string $repoUrl, string $ref): Status {
        $manifestPath = $this->refRegistry->getWorktreePath($repoUrl, $ref) . '/manifest.yml';      
        $body = $this->getRawManifest($manifestPath);
        if (!$body->isOK()) {
            return $body;
        }

        // Return the raw YAML content (parsing happens higher up)
        return $body;
    }

        /**
     * Retrieve the raw manifest file contents from a local path.
     *
     * @param string $manifestPath Absolute path to manifest.yml
     * @return Status::newGood(string $body) or Status::newFatal(error)
     */
    private function getRawManifest(string $manifestPath): Status {
        $path = trim($manifestPath);

        if ($path === '' || !is_file($path)) {
            return Status::newFatal('labkipackmanager-error-manifest-missing');
        }

        try {
            $content = file_get_contents($path);
            if ($content === false || $content === '') {
                return Status::newFatal('labkipackmanager-error-manifest-empty');
            }
            return Status::newGood($content);
        } catch (\Throwable $e) {
            wfDebugLog('labkipack', "Failed to read manifest at {$path}: " . $e->getMessage());
            return Status::newFatal('labkipackmanager-error-manifest-read');
        }
    }

}
