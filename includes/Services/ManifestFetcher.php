<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\Status\Status;
use LabkiPackManager\Services\LabkiRefRegistry;

/**
 * ManifestFetcher
 *
 * Responsible for retrieving manifest YAML files from local worktree storage
 * that has already been prepared by GitContentManager.
 */
final class ManifestFetcher {

    private LabkiRefRegistry $refRegistry;

    public function __construct(?LabkiRefRegistry $refRegistry = null) {
        $this->refRegistry = $refRegistry ?? new LabkiRefRegistry();
    }

    /**
     * Fetch raw manifest YAML for the given repo/ref from the local worktree.
     *
     * @param string $repoUrl Remote repository URL used to resolve the worktree path
     * @param string $ref Branch, tag, or commit for the worktree
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
