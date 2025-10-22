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

    /**
     * Ensure the bare mirror of a Git repository exists and is reasonably up to date.
     *
     * This function:
     *  - Creates the mirror under /cache if missing.
     *  - Fetches updates if the last sync was >1 hour ago.
     *  - Registers or updates the repository entry in labki_content_repo.
     *
     * @param string $repoUrl Repository URL (e.g., GitHub HTTPS)
     * @return string Absolute path to the local bare repository
     */
    public function ensureBareRepo(string $repoUrl): string {
        wfDebugLog('labkipack', "ensureBareRepo() called for {$repoUrl}");
    
        $gitUrl = UrlResolver::resolveContentRepoUrl($repoUrl);
        $safeName = $this->generateRepoDirName($gitUrl);
        $barePath = "{$this->cloneBasePath}/cache/{$safeName}.git";
    
        // Ensure parent directory exists
        if (!is_dir(dirname($barePath))) {
            mkdir(dirname($barePath), 0755, true);
        }
    
        // Create bare repository if missing
        if (!is_dir($barePath)) {
            wfDebugLog('labkipack', "Creating new bare clone: {$barePath}");
            $this->runGit(['clone', '--mirror', $gitUrl, $barePath]);
        }
    
        // Check if the repository is registered
        $repoId = $this->repoRegistry->getRepoIdByUrl($gitUrl);
    
        if ($repoId === null) {
            // Register new repository
            wfDebugLog('labkipack', "Registering new repo {$gitUrl}");
            $repoId = $this->repoRegistry->ensureRepoEntry($gitUrl, [
                'bare_path' => $barePath,
                'last_fetched' => \wfTimestampNow(),
            ]);
        } else {
            // Repository exists, check if we need to update it
            $repo = $this->repoRegistry->getRepoById($repoId);
            $lastFetched = method_exists($repo, 'lastFetched')
                ? (int)wfTimestamp(TS_UNIX, $repo->lastFetched())
                : 0;
    
            $oneHourAgo = time() - 3600;

            // Fetch latest updates if the last fetch was more than 1 hour ago
    
            if ($lastFetched < $oneHourAgo) {
                // Fetch latest updates for the bare repository
                wfDebugLog('labkipack', "Fetching latest updates for {$gitUrl}");
                try {
                    wfDebugLog('labkipack', "Fetching bare repo at {$barePath}");
                    // fetches all branches, tags, and prunes any branches that no longer exist
                    $this->runGit(['-C', $barePath, 'fetch', '--all', '--tags', '--prune']);
                    // Update the repository entry in the database
                    $this->repoRegistry->updateRepoEntry($repoId, [
                        'last_fetched' => \wfTimestampNow(),
                        'updated_at'   => \wfTimestampNow(),
                    ]);
                } catch (\Exception $e) {
                    wfDebugLog('labkipack', "Fetch failed for {$gitUrl}: " . $e->getMessage());
                }
            } else {
                wfDebugLog('labkipack', "Skipping fetch for {$gitUrl} (recently updated)");
            }
        }
    
        return $barePath;
    }    

    /**
     * Ensure a local worktree exists and is synchronized for the given ref.
     *
     * This function:
     *  - Creates a new worktree from the bare repo if missing.
     *  - Verifies the local worktree is up to date with the remote commit.
     *  - Registers or updates the ref entry in labki_content_ref.
     *
     * @param string $barePath Path to the bare repository (from ensureBareRepo)
     * @param string $repoUrl  Repository URL (e.g., GitHub HTTPS)
     * @param string $ref      Branch, tag, or commit to check out
     * @return string Absolute path to the local worktree directory
     */
    public function ensureWorktree(string $repoUrl, string $ref): string {
        wfDebugLog('labkipack', "ensureWorktree() called for {$repoUrl}@{$ref}");

        $safeName = $this->generateRepoDirName($repoUrl);
        $barePath = "{$this->cloneBasePath}/cache/{$safeName}.git";
        $worktreePath = "{$this->cloneBasePath}/worktrees/{$safeName}_{$ref}";

        // Ensure parent directory exists
        if (!is_dir(dirname($worktreePath))) {
            mkdir(dirname($worktreePath), 0755, true);
        }

        // Create worktree if missing
        if (!is_dir($worktreePath)) {
            wfDebugLog('labkipack', "Creating worktree for {$repoUrl}@{$ref}");
            try {
                $this->runGit(['-C', $barePath, 'worktree', 'add', '--detach', $worktreePath, $ref]);
            } catch (\Exception $e) {
                throw new RuntimeException("Failed to create worktree for {$repoUrl}@{$ref}: " . $e->getMessage());
            }
        } else {
            wfDebugLog('labkipack', "Worktree already exists for {$repoUrl}@{$ref}");
        }

        // Determine current and remote commit hashes
        $commit = trim($this->runGit(['-C', $worktreePath, 'rev-parse', 'HEAD'], true));
        $remoteCommit = trim($this->runGit(['-C', $barePath, 'rev-parse', $ref], true));

        // If the worktree is out of sync, reset to match remote
        if ($commit !== $remoteCommit && $remoteCommit !== '') {
            wfDebugLog('labkipack', "Worktree {$worktreePath} out of sync (local={$commit}, remote={$remoteCommit}); resetting");
            $this->runGit(['-C', $worktreePath, 'reset', '--hard', $remoteCommit]);
            $commit = $remoteCommit;
        } else {
            wfDebugLog('labkipack', "Worktree {$worktreePath} is up to date (commit {$commit})");
        }

        // Register or update ref in database
        $repoId = $this->repoRegistry->getRepoIdByUrl($repoUrl);
        if ($repoId !== null) {
            $refId = $this->refRegistry->ensureRefEntry($repoId, $ref, [
                'last_commit'   => $commit,
                'worktree_path' => $worktreePath,
                'updated_at'    => \wfTimestampNow(),
                
            ]);
            wfDebugLog('labkipack', "Ref {$ref} registered (commit {$commit}, refID={$refId->toInt()}, repoID={$repoId->toInt()})");
        } else {
            throw new RuntimeException("No repo entry found for {$repoUrl} — ensureBareRepo() must be called first.");
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
     * Generate a filesystem-safe, unique directory name for a repository or worktree.
     *
     * Example:
     *   https://github.com/Aharoni-Lab/labki-packs.git
     *   → github_com_Aharoni_Lab_labki_packs_hash
     *
     * This ensures:
     *   - all non-alphanumeric characters become underscores
     *   - long URLs remain deterministic (with optional hash suffix)
     *   - no collisions between similarly named hosts or subpaths
     */
    private function generateRepoDirName(string $gitUrl): string {
        $parsed = parse_url($gitUrl);
        $host = $parsed['host'] ?? 'unknown';
        $path = trim((string)($parsed['path'] ?? ''), '/');

        // Strip trailing ".git" and normalize separators
        $path = preg_replace('/\.git$/i', '', $path);
        $full = "{$host}_{$path}";

        // Replace anything that isn’t alphanumeric or underscore
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $full);

        // append short hash for uniqueness (useful for forks with same path)
        $hash = substr(sha1($gitUrl), 0, 6);

        return "{$safe}_{$hash}";
    }


    /**
     * Run a git command safely and optionally capture its output.
     *
     * @param array $args Command arguments (e.g. ['-C', '/repo', 'status'])
     * @param bool $captureOutput Whether to return stdout (true) or discard (false)
     * @return string Command output if $captureOutput = true, otherwise empty string
     * @throws RuntimeException If the command fails (non-zero exit code)
     */
    private function runGit(array $args, bool $captureOutput = false): string {
        // Build full shell command safely
        $cmd = 'git ' . implode(' ', array_map('escapeshellarg', $args));

        // Execute command and capture output and return code
        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        // Log failures clearly
        if ($exitCode !== 0) {
            $msg = sprintf(
                "Git command failed (exit=%d): %s\nOutput:\n%s",
                $exitCode,
                $cmd,
                implode("\n", $output)
            );
            wfDebugLog('labkipack', $msg);
            throw new RuntimeException($msg);
        }

        // Optionally return output for read-type commands
        return $captureOutput ? trim(implode("\n", $output)) : '';
    }


    /**
     * Get base directory path for all clones.
     */
    public function getCloneBasePath(): string {
        return $this->cloneBasePath;
    }
}
