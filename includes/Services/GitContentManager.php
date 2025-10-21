<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Util\UrlResolver;
use RuntimeException;

/**
 * GitContentManager
 *
 * Manages both bare Git repositories (cached mirrors)
 * and their associated worktrees for specific refs.
 *
 * Hybrid structure:
 *   - One shared bare mirror per repository under /cache
 *   - Persistent worktrees per ref under /worktrees
 *
 * Integrates with:
 *   - LabkiRepoRegistry (bare-level)
 *   - LabkiRefRegistry (ref-level)
 */
final class GitContentManager {
    private string $cloneBasePath;
    private LabkiRepoRegistry $repoRegistry;
    private LabkiRefRegistry $refRegistry;

    public function __construct(
        ?LabkiRepoRegistry $repoRegistry = null,
        ?LabkiRefRegistry $refRegistry = null
    ) {
        global $wgCacheDirectory;
        if (!$wgCacheDirectory) {
            throw new RuntimeException('$wgCacheDirectory must be configured');
        }

        $this->cloneBasePath = "{$wgCacheDirectory}/labki-content-repos";
        $this->repoRegistry = $repoRegistry ?? new LabkiRepoRegistry();
        $this->refRegistry = $refRegistry ?? new LabkiRefRegistry();

        // Ensure base directories exist
        foreach (["cache", "worktrees"] as $subdir) {
            $path = "{$this->cloneBasePath}/{$subdir}";
            if (!is_dir($path) && !mkdir($path, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$path}");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Public API
    // ─────────────────────────────────────────────────────────────

    /**
     * Ensure local bare and worktree clones exist and are up to date.
     *
     * @param string $repoUrl Repository URL (e.g., GitHub HTTPS)
     * @param string|array $refs One or more branches/tags/commits
     * @return array<string,string> Map of ref => local worktree path
     */
    public function ensureClone(string $repoUrl, string|array $refs = 'main'): array {
        wfDebugLog('labkipack', "ensureClone() called for {$repoUrl}");

        // Normalize refs to array
        $refs = is_array($refs) ? $refs : [$refs];
        $gitUrl = UrlResolver::resolveContentRepoUrl($repoUrl);

        // ────────────────────────────────────────────────
        // 1. Ensure bare repo and register in DB
        // ────────────────────────────────────────────────
        $barePath = $this->ensureBareRepo($gitUrl);
        $repoId = $this->repoRegistry->ensureRepoEntry($gitUrl, [
            'bare_path' => $barePath,
            'last_fetched' => \wfTimestampNow(),
        ]);

        // ────────────────────────────────────────────────
        // 2. Fetch updates (throttled to 1/hr)
        // ────────────────────────────────────────────────
        $stampFile = "{$barePath}/last_fetch";
        $shouldFetch = true;

        if (file_exists($stampFile)) {
            $age = time() - filemtime($stampFile);
            if ($age < 3600) {
                $shouldFetch = false;
                wfDebugLog('labkipack', "Skipping fetch (last updated {$age}s ago): {$barePath}");
            }
        }

        if ($shouldFetch) {
            wfDebugLog('labkipack', "Fetching updates for bare repo: {$barePath}");
            $this->fetchBareRepo($barePath);
            touch($stampFile);
            $this->repoRegistry->updateRepoEntry($repoId, ['last_fetched' => \wfTimestampNow()]);
        }

        // ────────────────────────────────────────────────
        // 3. Ensure worktrees for all requested refs
        // ────────────────────────────────────────────────
        $paths = [];
        foreach ($refs as $ref) {
            wfDebugLog('labkipack', "Processing ref {$ref} for {$repoUrl}");

            // Ensure worktree for this ref
            $worktreePath = $this->ensureWorktree($barePath, $gitUrl, $ref);

            // Get commit hashes
            $commit = trim($this->runGit(['-C', $worktreePath, 'rev-parse', 'HEAD'], true));
            $remoteCommit = trim($this->runGit(['-C', $barePath, 'rev-parse', $ref], true));

            // Sync worktree if needed
            if ($commit !== $remoteCommit && $remoteCommit !== '') {
                wfDebugLog('labkipack', "Worktree {$worktreePath} out of sync (local={$commit}, remote={$remoteCommit}); resetting");
                $this->runGit(['-C', $worktreePath, 'reset', '--hard', $remoteCommit]);
                $commit = $remoteCommit;
            }

            // Register or update in labki_content_ref
            $refId = $this->refRegistry->ensureRefEntry($repoId, $ref, [
                'last_commit' => $commit,
                'worktree_path' => $worktreePath,
                'updated_at' => \wfTimestampNow(),
            ]);

            wfDebugLog('labkipack', "Ref {$ref} ready (commit {$commit}, refID={$refId->toInt()}, repoID={$repoId->toInt()})");
            $paths[$ref] = $worktreePath;
        }

        wfDebugLog('labkipack', "All refs processed for {$repoUrl}: " . implode(', ', array_keys($paths)));
        return $paths;
    }

    // ─────────────────────────────────────────────────────────────
    //  Bare repo management
    // ─────────────────────────────────────────────────────────────

    /**
     * Ensure a bare repository exists and return its path.
     */
    public function ensureBareRepo(string $repoUrl): string {
        $gitUrl = UrlResolver::resolveContentRepoUrl($repoUrl);
        $safeName = $this->generateRepoDirName($gitUrl);
        $barePath = "{$this->cloneBasePath}/cache/{$safeName}.git";

        if (!is_dir($barePath)) {
            wfDebugLog('labkipack', "Creating new bare clone: {$barePath}");
            $this->runGit(['clone', '--mirror', $gitUrl, $barePath]);
        }

        return $barePath;
    }

    /**
     * Fetch updates for a bare repository.
     */
    public function fetchBareRepo(string $barePath): void {
        wfDebugLog('labkipack', "Fetching bare repo at {$barePath}");
        $this->runGit(['-C', $barePath, 'fetch', '--all', '--tags', '--prune']);
    }

    // ─────────────────────────────────────────────────────────────
    //  Worktree management
    // ─────────────────────────────────────────────────────────────

    /**
     * Ensure a persistent worktree exists for the given ref.
     */
    public function ensureWorktree(string $barePath, string $repoUrl, string $ref): string {
        $safeName = $this->generateRepoDirName($repoUrl);
        $worktreePath = "{$this->cloneBasePath}/worktrees/{$safeName}_{$ref}";

        if (!is_dir($worktreePath)) {
            wfDebugLog('labkipack', "Creating worktree for {$repoUrl}@{$ref}");
            $this->runGit(['-C', $barePath, 'worktree', 'add', '--detach', $worktreePath, $ref]);
        }

        return $worktreePath;
    }

    // ─────────────────────────────────────────────────────────────
    //  Utility methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Determine whether a directory is a valid git repo.
     */
    private function isValidGitRepo(string $path): bool {
        return is_dir("{$path}/.git") || file_exists("{$path}/HEAD");
    }

    /**
     * Generate a safe directory name for a repository or worktree.
     */
    private function generateRepoDirName(string $gitUrl): string {
        $parsed = parse_url($gitUrl);
        $host = $parsed['host'] ?? 'unknown';
        $path = trim((string)($parsed['path'] ?? ''), '/');
        $path = preg_replace('/\.git$/', '', $path);
        return str_replace(['/', ':', '@'], '_', "{$host}_{$path}");
    }

    /**
     * Run a git command and optionally return its output.
     */
    private function runGit(array $args, bool $captureOutput = false): string {
        $cmd = 'git ' . implode(' ', array_map('escapeshellarg', $args));
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        if ($code !== 0) {
            wfDebugLog('labkipack', "Git command failed: {$cmd}\n" . implode("\n", $output));
            throw new RuntimeException("Git command failed: {$cmd}");
        }

        return $captureOutput ? implode("\n", $output) : '';
    }

    /**
     * Get base directory path for all clones.
     */
    public function getCloneBasePath(): string {
        return $this->cloneBasePath;
    }
}
