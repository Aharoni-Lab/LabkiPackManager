<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Util\UrlResolver;
use MediaWiki\MediaWikiServices;
use RuntimeException;

/**
 * GitContentRepoManager
 *
 * Manages local git repository clones for content repositories.
 * Handles cloning, updating, and ensuring repositories are available locally
 * for specific branches, tags, or commits.
 */
final class GitContentRepoManager {
    private string $cloneBasePath;
    private LabkiRepoRegistry $repoRegistry;

    public function __construct(?LabkiRepoRegistry $repoRegistry = null) {
        global $wgCacheDirectory;
        $this->cloneBasePath = $wgCacheDirectory
            ? "{$wgCacheDirectory}/labki-content-repos"
            : wfTempDir() . '/labki-content-repos';

        $this->repoRegistry = $repoRegistry ?? new LabkiRepoRegistry();

        // Ensure base directory exists
        if (!is_dir($this->cloneBasePath) && !mkdir($this->cloneBasePath, 0755, true)) {
            throw new RuntimeException("Failed to create clone directory: {$this->cloneBasePath}");
        }
    }

    /**
     * Ensure a clone of the repository exists and is up to date for a given ref.
     *
     * @param string $repoUrl Repository URL (e.g., GitHub HTTPS)
     * @param string $ref Branch, tag, or commit
     * @return string The local path to the cloned repository
     * @throws RuntimeException
     */
    public function ensureClone(string $repoUrl, string $ref): string {
        wfDebugLog('labkipack', "ensureClone() called for URL={$repoUrl} ref={$ref}");

        // This is already done in onMediaWikiServices, but we'll do it again here for safety
        $gitUrl = UrlResolver::resolveContentRepoUrl($repoUrl);
        $repoDir = $this->generateRepoDirName($gitUrl, $ref);
        $localPath = "{$this->cloneBasePath}/{$repoDir}";

        if ($this->isValidGitRepo($localPath)) {
            wfDebugLog('labkipack', "Repository already exists at {$localPath}, updating...");
            $this->updateClone($localPath, $ref);
        } else {
            wfDebugLog('labkipack', "Cloning new repository {$gitUrl} (ref={$ref}) to {$localPath}");
            $this->cloneRepo($gitUrl, $localPath, $ref);
        }

        // Determine current commit
        $commit = $this->getCurrentCommit($localPath);
        wfDebugLog('labkipack', "Repository {$repoUrl}@{$ref} at commit {$commit}");

        // Register repository in DB (unique per URL+ref)
        $repoId = $this->repoRegistry->ensureRepoEntry($gitUrl, $ref, [
            'last_commit' => $commit,
            'updated_at' => \wfTimestampNow(),
        ]);

        wfDebugLog('labkipack', "Registered/updated repo {$gitUrl}@{$ref} as ID={$repoId->toInt()}");
        return $localPath;
    }

    /**
     * Clone repository at specific ref (branch/tag/commit).
     */
    private function cloneRepo(string $gitUrl, string $localPath, string $ref): void {
        if (is_dir($localPath)) {
            $this->removeDirectory($localPath);
        }

        // Use --branch for branch/tag; --depth=1 keeps shallow clone
        $cmd = sprintf(
            'git clone --depth 1 --branch %s %s %s 2>&1',
            escapeshellarg($ref),
            escapeshellarg($gitUrl),
            escapeshellarg($localPath)
        );

        $this->runCommand($cmd, "Failed to clone {$gitUrl}@{$ref}");
        wfDebugLog('labkipack', "Successfully cloned {$gitUrl}@{$ref}");
    }

    /**
     * Update an existing repository to match the remote for the specified ref.
     */
    private function updateClone(string $localPath, string $ref): void {
        if (!$this->isValidGitRepo($localPath)) {
            throw new RuntimeException("Not a valid git repository: {$localPath}");
        }

        $cwd = getcwd();
        chdir($localPath);

        try {
            $this->runCommand('git fetch --all --tags 2>&1', "Failed to fetch updates");

            // Checkout and hard reset the requested ref
            $checkoutCmd = sprintf('git checkout %s 2>&1', escapeshellarg($ref));
            $this->runCommand($checkoutCmd, "Failed to checkout ref {$ref}");

            $resetCmd = sprintf('git reset --hard origin/%s 2>&1', escapeshellarg($ref));
            $this->runCommand($resetCmd, "Failed to reset to origin/{$ref}");
        } finally {
            chdir($cwd);
        }

        wfDebugLog('labkipack', "Successfully updated repo at {$localPath} to ref {$ref}");
    }

    /**
     * Determine the current HEAD commit hash.
     */
    private function getCurrentCommit(string $localPath): ?string {
        if (!$this->isValidGitRepo($localPath)) {
            return null;
        }
        $cmd = sprintf('git -C %s rev-parse HEAD 2>&1', escapeshellarg($localPath));
        $output = [];
        $ret = 0;
        exec($cmd, $output, $ret);
        return $ret === 0 ? trim($output[0] ?? '') : null;
    }

    /**
     * Determine whether a local path is a valid git repository.
     */
    private function isValidGitRepo(string $path): bool {
        return is_dir("{$path}/.git");
    }

    /**
     * Generate a safe directory name for a repository/ref pair.
     */
    private function generateRepoDirName(string $gitUrl, string $ref): string {
        $parsed = parse_url($gitUrl);
        $host = $parsed['host'] ?? 'unknown';
        $path = trim((string)($parsed['path'] ?? ''), '/');
        $path = preg_replace('/\.git$/', '', $path);
        $safe = str_replace(['/', ':', '@'], '_', $path);
        $refSafe = str_replace(['/', ':'], '_', $ref);
        return "{$host}_{$safe}_{$refSafe}";
    }

    /**
     * Remove a directory recursively.
     */
    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $f) {
            $path = "{$dir}/{$f}";
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Run a system command, throw exception on failure.
     */
    private function runCommand(string $command, string $errorMsg): void {
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        if ($code !== 0) {
            $details = implode("\n", $output);
            wfDebugLog('labkipack', "{$errorMsg}: {$details}");
            throw new RuntimeException("{$errorMsg}: {$details}");
        }
    }

    /**
     * Get local clone base path.
     */
    public function getCloneBasePath(): string {
        return $this->cloneBasePath;
    }
}
