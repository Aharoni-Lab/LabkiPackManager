<?php

declare(strict_types=1);

namespace LabkiPackManager\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use LabkiPackManager\Services\GitContentManager;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Util\ContentSourcesUtil;

/**
 * InitializeContentRepos
 *
 * Maintenance script to initialize Git repositories and worktrees for Labki content packs.
 * This script is automatically run after database schema updates via SchemaHooks.
 *
 * What it does:
 *   1. Reads content sources from $wgLabkiContentSources configuration
 *   2. Creates bare Git repository mirrors under $wgCacheDirectory/labki-content-repos/cache/
 *   3. Creates worktrees for each ref under $wgCacheDirectory/labki-content-repos/worktrees/
 *   4. Registers repositories and refs in the database (labki_content_repo, labki_content_ref)
 *   5. Parses and caches manifest.yml for each ref
 *
 * Usage:
 *   php maintenance/run.php extensions/LabkiPackManager/maintenance/InitializeContentRepos.php
 *
 * Configuration example (LocalSettings.php):
 *   $wgLabkiContentSources = [
 *       ['url' => 'https://github.com/Aharoni-Lab/labki-packs', 'refs' => ['main', 'v1.0.0']],
 *       ['url' => 'https://github.com/Aharoni-Lab/labki-base-packs', 'refs' => ['main']],
 *   ];
 *
 * Requirements:
 *   - Git must be installed and accessible in PATH
 *   - $wgCacheDirectory must be configured and writable
 *   - Network access to Git repositories
 */
class InitializeContentRepos extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addDescription(
            'Initialize Labki content repositories from $wgLabkiContentSources. ' .
            'Creates bare repos, worktrees, and parses manifests.'
        );
        $this->addOption(
            'force',
            'Force re-fetch of all repositories (ignores 1-hour cache)',
            false,
            false
        );
    }

    /**
     * Execute the maintenance script.
     *
     * Process flow:
     *   1. Validate configuration
     *   2. Initialize services
     *   3. For each content source:
     *      a. Create/update bare repository mirror
     *      b. Create/update worktrees for each ref
     *      c. Parse and cache manifest.yml
     *   4. Report results
     */
    public function execute(): void {
        $this->output("═══════════════════════════════════════════════════════════\n");
        $this->output("  Labki Content Repository Initialization\n");
        $this->output("═══════════════════════════════════════════════════════════\n\n");

        // Validate configuration
        $resolvedSources = ContentSourcesUtil::getResolvedContentSources();
        
        if (empty($resolvedSources)) {
            $this->output("⚠️  No content sources configured in \$wgLabkiContentSources\n\n");
            $this->output("To configure content sources, add to LocalSettings.php:\n");
            $this->output("  \$wgLabkiContentSources = [\n");
            $this->output("    ['url' => 'https://github.com/Aharoni-Lab/labki-packs', 'refs' => ['main']],\n");
            $this->output("  ];\n\n");
            wfDebugLog('labkipack', 'No valid LabkiContentSources found');
            return;
        }

        $this->output("Found " . count($resolvedSources) . " content source(s) to initialize\n\n");
        wfDebugLog('labkipack', 'Initializing ' . count($resolvedSources) . ' Labki content repositories');

        // Initialize services
        try {
            $contentManager = new GitContentManager();
            $repoRegistry = new LabkiRepoRegistry();
            $refRegistry = new LabkiRefRegistry();
        } catch (\Exception $e) {
            $this->fatalError("Failed to initialize services: " . $e->getMessage() . "\n");
        }

        // Track statistics
        $stats = [
            'repos_processed' => 0,
            'repos_succeeded' => 0,
            'repos_failed' => 0,
            'refs_processed' => 0,
            'refs_succeeded' => 0,
            'refs_failed' => 0,
        ];

        // Process each content source
        foreach ($resolvedSources as $index => $source) {
            $repoUrl = $source['url'];
            $refs = $source['refs'] ?? ['main'];

            if (!$repoUrl) {
                $this->output("⚠️  Skipping source #" . ($index + 1) . ": missing URL\n");
                wfDebugLog('labkipack', 'Skipping invalid content source (missing URL)');
                continue;
            }

            $stats['repos_processed']++;
            $this->output("─────────────────────────────────────────────────────────\n");
            $this->output("📦 Repository: {$repoUrl}\n");
            $this->output("   Refs: " . implode(', ', $refs) . "\n\n");

            try {
                // Step 1: Ensure bare repository mirror
                $this->output("   [1/3] Creating/updating bare repository mirror...\n");
                wfDebugLog('labkipack', "Initializing content repo {$repoUrl}");
                
                $barePath = $contentManager->ensureBareRepo($repoUrl);
                $this->output("   ✓ Bare repo ready at: {$barePath}\n");
                wfDebugLog('labkipack', "Bare repo ready at {$barePath}");

                // Verify repository was registered
                $repoId = $repoRegistry->getRepoId($repoUrl);
                if ($repoId === null) {
                    throw new \RuntimeException("Repository not found in DB after ensureBareRepo");
                }
                $this->output("   ✓ Registered in database (ID: {$repoId->toInt()})\n\n");

                // Step 2: Process each ref
                $this->output("   [2/3] Creating/updating worktrees for refs...\n");
                $refCount = count($refs);
                $refSuccess = 0;

                foreach ($refs as $refIndex => $ref) {
                    $stats['refs_processed']++;
                    $this->output("      • {$ref} ");
                    
                    try {
                        wfDebugLog('labkipack', "Ensuring worktree for {$repoUrl}@{$ref}");
                        $worktreePath = $contentManager->ensureWorktree($repoUrl, $ref);
                        
                        $this->output("✓\n");
                        $this->output("        Worktree: {$worktreePath}\n");
                        $refSuccess++;
                        $stats['refs_succeeded']++;
                        
                    } catch (\Exception $e) {
                        $this->output("✗ FAILED\n");
                        $this->output("        Error: " . $e->getMessage() . "\n");
                        $stats['refs_failed']++;
                        wfDebugLog('labkipack', "Failed to create worktree for {$repoUrl}@{$ref}: " . $e->getMessage());
                    }
                }

                $this->output("\n   [3/3] Summary for this repository:\n");
                $this->output("      ✓ {$refSuccess}/{$refCount} refs initialized successfully\n");
                
                if ($refSuccess === $refCount) {
                    $this->output("   ✅ Repository fully initialized\n\n");
                    $stats['repos_succeeded']++;
                } else {
                    $this->output("   ⚠️  Repository partially initialized\n\n");
                    $stats['repos_failed']++;
                }

            } catch (\Exception $e) {
                $this->output("   ✗ Repository initialization failed\n");
                $this->output("   Error: " . $e->getMessage() . "\n\n");
                $stats['repos_failed']++;
                wfDebugLog('labkipack', "Error initializing {$repoUrl}: " . $e->getMessage());
            }
        }

        // Final summary
        $this->output("═══════════════════════════════════════════════════════════\n");
        $this->output("  Initialization Complete\n");
        $this->output("═══════════════════════════════════════════════════════════\n\n");
        $this->output("Repositories: {$stats['repos_succeeded']}/{$stats['repos_processed']} succeeded");
        if ($stats['repos_failed'] > 0) {
            $this->output(", {$stats['repos_failed']} failed");
        }
        $this->output("\n");
        $this->output("Refs:         {$stats['refs_succeeded']}/{$stats['refs_processed']} succeeded");
        if ($stats['refs_failed'] > 0) {
            $this->output(", {$stats['refs_failed']} failed");
        }
        $this->output("\n\n");

        if ($stats['repos_failed'] > 0 || $stats['refs_failed'] > 0) {
            $this->output("⚠️  Some operations failed. Check debug logs for details:\n");
            $this->output("   wfDebugLog('labkipack', ...)\n\n");
        } else {
            $this->output("✅ All content repositories initialized successfully!\n\n");
        }

        wfDebugLog('labkipack', 'Content repository initialization complete: ' . 
            "{$stats['repos_succeeded']}/{$stats['repos_processed']} repos, " .
            "{$stats['refs_succeeded']}/{$stats['refs_processed']} refs");
    }
}

$maintClass = InitializeContentRepos::class;
require_once RUN_MAINTENANCE_IF_MAIN;