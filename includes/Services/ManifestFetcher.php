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
 *
 * Error messages:
 * @error labkipackmanager-error-manifest-missing - File not found
 * @error labkipackmanager-error-manifest-empty - File is empty or whitespace-only
 * @error labkipackmanager-error-manifest-unreadable - File exists but no read permission
 * @error labkipackmanager-error-manifest-read - General read failure
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

        // Check if file exists
        if ($path === '' || !is_file($path)) {
            return Status::newFatal('labkipackmanager-error-manifest-missing');
        }

        // Check if file is readable
        if (!is_readable($path)) {
            wfDebugLog('labkipack', "Manifest at {$path} exists but is not readable (permission denied)");
            return Status::newFatal('labkipackmanager-error-manifest-unreadable');
        }

        try {
            $content = file_get_contents($path);
            if ($content === false) {
                return Status::newFatal('labkipackmanager-error-manifest-read');
            }
            
            // Treat empty or whitespace-only content as invalid
            if (trim($content) === '') {
                return Status::newFatal('labkipackmanager-error-manifest-empty');
            }
            
            return Status::newGood($content);
        } catch (\Throwable $e) {
            wfDebugLog('labkipack', "Failed to read manifest at {$path}: " . $e->getMessage());
            return Status::newFatal('labkipackmanager-error-manifest-read');
        }
    }
}
