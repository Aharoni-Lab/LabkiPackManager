<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

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
    
        $safeName = $this->generateRepoDirName($repoUrl);
        $barePath = "{$this->cloneBasePath}/cache/{$safeName}.git";
    
        // Ensure parent directory exists
        if (!is_dir(dirname($barePath))) {
            mkdir(dirname($barePath), 0755, true);
        }
    
        // Create bare repository if missing
        if (!is_dir($barePath)) {
            wfDebugLog('labkipack', "Creating new bare clone: {$barePath}");
            $this->runGit(['clone', '--mirror', $repoUrl, $barePath]);
        }
    
        // Check if the repository is registered
        $repoId = $this->repoRegistry->getRepoId($repoUrl);
    
        if ($repoId === null) {
            // Register new repository
            wfDebugLog('labkipack', "Registering new repo {$repoUrl}");
            $repoId = $this->repoRegistry->ensureRepoEntry($repoUrl, [
                'bare_path' => $barePath,
                'last_fetched' => $this->repoRegistry->now(),
            ]);
        } else {
            // Repository exists, check if we need to update it
            $repo = $this->repoRegistry->getRepo($repoId);
            $lastFetched = method_exists($repo, 'lastFetched')
                ? (int)wfTimestamp(TS_UNIX, $repo->lastFetched())
                : 0;
    
            $oneHourAgo = time() - 3600;

            // Fetch latest updates if the last fetch was more than 1 hour ago
    
            if ($lastFetched < $oneHourAgo) {
                // Fetch latest updates for the bare repository
                wfDebugLog('labkipack', "Fetching latest updates for {$repoUrl}");
                try {
                    wfDebugLog('labkipack', "Fetching bare repo at {$barePath}");
                    // fetches all branches, tags, and prunes any branches that no longer exist
                    $this->runGit(['-C', $barePath, 'fetch', '--all', '--tags', '--prune']);
                    // Update the repository entry in the database
                    $this->repoRegistry->updateRepoEntry($repoId, [
                        'last_fetched' => $this->repoRegistry->now(),
                        'updated_at'   => $this->repoRegistry->now(),
                    ]);
                } catch (\Exception $e) {
                    wfDebugLog('labkipack', "Fetch failed for {$repoUrl}: " . $e->getMessage());
                }
            } else {
                wfDebugLog('labkipack', "Skipping fetch for {$repoUrl} (recently updated)");
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

        // do an ensureRefEntry for the ref
        // Manifest Store expects a refId, so we need to get it from the registry
        $refId = $this->refRegistry->ensureRefEntry($repoUrl, $ref, [
            'last_commit'   => $commit,
            'worktree_path' => $worktreePath,
            'updated_at'    => $this->refRegistry->now(),
            
        ]);

        // Handle Manifest Store updates
        $manifestStore = new ManifestStore($repoUrl, $ref);
        $manifestResult = $manifestStore->get(true); // force refresh
        if ($manifestResult->isOK()) {
            $manifest = $manifestResult->getValue();
        }
        $refId = $this->refRegistry->ensureRefEntry($repoUrl, $ref, [
            'content_ref_name' => $manifest['manifest']['name'],
            'manifest_hash' => $manifest['hash'],
            'manifest_last_parsed' => $manifest['last_parsed_at'],
            'updated_at'    => $this->refRegistry->now(),
        ]);

        wfDebugLog('labkipack', "Ref {$ref} registered (commit {$commit}, refID={$refId->toInt()}, repoID={$repoUrl})");

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
    private function generateRepoDirName(string $repoUrl): string {
        $parsed = parse_url($repoUrl);
        $host = $parsed['host'] ?? 'unknown';
        $path = trim((string)($parsed['path'] ?? ''), '/');

        // Strip trailing ".git" and normalize separators
        $path = preg_replace('/\.git$/i', '', $path);
        $full = "{$host}_{$path}";

        // Replace anything that isn’t alphanumeric or underscore
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $full);

        // append short hash for uniqueness (useful for forks with same path)
        $hash = substr(sha1($repoUrl), 0, 6);

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

    // ─────────────────────────────────────────────────────────────
    //  Sync/Update methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Sync a specific ref from remote.
     *
     * This performs:
     * - Fetch updates for the bare repository
     * - Update the worktree to match the remote ref
     * - Update the ref entry in database with new commit hash
     *
     * @param string $repoUrl Repository URL
     * @param string $ref Ref name to sync
     * @throws RuntimeException If ref not found or sync fails
     */
    public function syncRef(string $repoUrl, string $ref): void {
        wfDebugLog('labkipack', "GitContentManager::syncRef() syncing {$repoUrl}@{$ref}");

        // Get repository ID
        $repoId = $this->repoRegistry->getRepoId($repoUrl);
        if ($repoId === null) {
            throw new RuntimeException("Repository not found: {$repoUrl}");
        }

        // Get ref ID
        $refId = $this->refRegistry->getRefIdByRepoAndRef($repoId, $ref);
        if ($refId === null) {
            throw new RuntimeException("Ref '{$ref}' not found in repository");
        }

        // Ensure bare repo is fetched (this will fetch updates)
        $barePath = $this->ensureBareRepo($repoUrl);
        wfDebugLog('labkipack', "Bare repo updated at {$barePath}");

        // Ensure worktree is synced (this will reset to remote commit if needed)
        $worktreePath = $this->ensureWorktree($repoUrl, $ref);
        wfDebugLog('labkipack', "Worktree synced at {$worktreePath}");

        wfDebugLog('labkipack', "Successfully synced ref {$repoUrl}@{$ref}");
    }

    /**
     * Sync an entire repository and all its refs.
     *
     * This performs:
     * - Fetch updates for the bare repository
     * - Update all existing worktrees to match their remote refs
     * - Update all ref entries in database with new commit hashes
     *
     * @param string $repoUrl Repository URL
     * @return int Number of refs that were synced
     * @throws RuntimeException If repository not found or sync fails
     */
    public function syncRepo(string $repoUrl): int {
        wfDebugLog('labkipack', "GitContentManager::syncRepo() syncing {$repoUrl}");

        // Get repository ID
        $repoId = $this->repoRegistry->getRepoId($repoUrl);
        if ($repoId === null) {
            throw new RuntimeException("Repository not found: {$repoUrl}");
        }

        // First, update the bare repository
        $barePath = $this->ensureBareRepo($repoUrl);
        wfDebugLog('labkipack', "Bare repo updated at {$barePath}");

        // Get all refs for this repository
        $refs = $this->refRegistry->listRefsForRepo($repoId);
        $refCount = count($refs);
        
        wfDebugLog('labkipack', "Found {$refCount} refs to sync for {$repoUrl}");

        // Sync each ref
        $syncedCount = 0;
        foreach ($refs as $ref) {
            try {
                $this->syncRef($repoUrl, $ref->sourceRef());
                $syncedCount++;
            } catch (\Exception $e) {
                wfDebugLog('labkipack', "Failed to sync ref {$ref->sourceRef()}: " . $e->getMessage());
                // Continue syncing other refs even if one fails
            }
        }

        wfDebugLog('labkipack', "Successfully synced repository {$repoUrl} ({$syncedCount}/{$refCount} refs)");

        return $syncedCount;
    }

    // ─────────────────────────────────────────────────────────────
    //  Removal methods
    // ─────────────────────────────────────────────────────────────

    /**
     * Remove a specific ref from a repository.
     *
     * This removes:
     * - The worktree directory from filesystem
     * - Associated packs and pages (TODO: implement when LabkiPackRegistry exists)
     * - The ref entry from database
     *
     * @param string $repoUrl Repository URL
     * @param string $ref Ref name to remove
     * @throws RuntimeException If ref not found or removal fails
     */
    public function removeRef(string $repoUrl, string $ref): void {
        wfDebugLog('labkipack', "GitContentManager::removeRef() removing {$repoUrl}@{$ref}");

        // Get repository ID
        $repoId = $this->repoRegistry->getRepoId($repoUrl);
        if ($repoId === null) {
            throw new RuntimeException("Repository not found: {$repoUrl}");
        }

        // Get ref ID
        $refId = $this->refRegistry->getRefIdByRepoAndRef($repoId, $ref);
        if ($refId === null) {
            throw new RuntimeException("Ref '{$ref}' not found in repository");
        }

        // Get worktree path before deletion
        $refData = $this->refRegistry->getRefById($refId);
        $worktreePath = $refData ? $refData->worktreePath() : null;

        // TODO: Remove associated packs and pages
        // When LabkiPackRegistry exists:
        // $packRegistry = new LabkiPackRegistry();
        // $packs = $packRegistry->getPacksForRef($refId);
        // foreach ($packs as $pack) {
        //     $packRegistry->removePack($pack->id());
        // }

        // Remove from database first (foreign keys will cascade)
        $this->refRegistry->deleteRef($refId);
        wfDebugLog('labkipack', "Deleted ref entry from database (refId={$refId->toInt()})");

        // Remove worktree from filesystem
        if ($worktreePath && is_dir($worktreePath)) {
            $this->removeDirectory($worktreePath);
            wfDebugLog('labkipack', "Removed worktree directory: {$worktreePath}");
        } elseif ($worktreePath) {
            wfDebugLog('labkipack', "Worktree directory not found: {$worktreePath}");
        }

        // Remove the worktree entry from the bare repository
        $safeName = $this->generateRepoDirName($repoUrl);
        $barePath = "{$this->cloneBasePath}/cache/{$safeName}.git";
        
        if (is_dir($barePath)) {
            try {
                // Prune the worktree entry
                $this->runGit(['-C', $barePath, 'worktree', 'prune']);
                wfDebugLog('labkipack', "Pruned worktree entry from bare repo");
            } catch (\Exception $e) {
                wfDebugLog('labkipack', "Failed to prune worktree: " . $e->getMessage());
            }
        }

        wfDebugLog('labkipack', "Successfully removed ref {$repoUrl}@{$ref}");
    }

    /**
     * Remove an entire repository.
     *
     * This removes:
     * - All refs (worktrees, packs, pages)
     * - The bare repository from filesystem
     * - The repository entry from database
     *
     * @param string $repoUrl Repository URL
     * @return int Number of refs that were removed
     * @throws RuntimeException If repository not found or removal fails
     */
    public function removeRepo(string $repoUrl): int {
        wfDebugLog('labkipack', "GitContentManager::removeRepo() removing {$repoUrl}");

        // Get repository ID
        $repoId = $this->repoRegistry->getRepoId($repoUrl);
        if ($repoId === null) {
            throw new RuntimeException("Repository not found: {$repoUrl}");
        }

        // Get all refs for this repository
        $refs = $this->refRegistry->listRefsForRepo($repoId);
        $refCount = count($refs);
        
        wfDebugLog('labkipack', "Found {$refCount} refs to remove for {$repoUrl}");

        // Remove each ref (this handles worktrees, packs, pages)
        foreach ($refs as $ref) {
            try {
                $this->removeRef($repoUrl, $ref->sourceRef());
            } catch (\Exception $e) {
                wfDebugLog('labkipack', "Failed to remove ref {$ref->sourceRef()}: " . $e->getMessage());
                // Continue removing other refs even if one fails
            }
        }

        // Remove bare repository from filesystem
        $safeName = $this->generateRepoDirName($repoUrl);
        $barePath = "{$this->cloneBasePath}/cache/{$safeName}.git";
        
        if (is_dir($barePath)) {
            $this->removeDirectory($barePath);
            wfDebugLog('labkipack', "Removed bare repository directory: {$barePath}");
        } else {
            wfDebugLog('labkipack', "Bare repository directory not found: {$barePath}");
        }

        // Finally, remove repository entry from database
        $this->repoRegistry->deleteRepo($repoId);
        wfDebugLog('labkipack', "Deleted repository entry from database (repoId={$repoId})");

        wfDebugLog('labkipack', "Successfully removed repository {$repoUrl} ({$refCount} refs)");

        return $refCount;
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * @param string $dir Directory path to remove
     * @throws RuntimeException If removal fails
     */
    private function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        // Use iterator for safe recursive deletion
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        // Remove the directory itself
        if (!rmdir($dir)) {
            throw new RuntimeException("Failed to remove directory: {$dir}");
        }
    }
}
