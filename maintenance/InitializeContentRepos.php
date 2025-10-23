<?php

declare(strict_types=1);

namespace LabkiPackManager\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use LabkiPackManager\Services\GitContentManager;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Util\ContentSourcesUtil;

class InitializeContentRepos extends Maintenance {
    /**
     * Initialize content repositories defined in $wgLabkiContentSources.
     *
     * - Ensures each content repo has a bare mirror (shared).
     * - Ensures each requested ref has a dedicated worktree.
     * - Registers/updates both repo and ref entries in DB.
     * - Optionally updates repo name from manifest.
     */
    public function execute(): void {
        $this->output( "Initializing Labki content repositories...\n" );

        // Get resolved content sources
        $resolvedSources = ContentSourcesUtil::getResolvedContentSources();
        
        // If no valid content sources are found, return
        if (empty($resolvedSources)) {
            wfDebugLog('labkipack', 'No valid LabkiContentSources found');
            return;
        }

        wfDebugLog('labkipack', 'Initializing Labki content repositories...');

        // Initialize managers and registries
        $contentManager = new GitContentManager();   // formerly GitContentRepoManager
        $repoRegistry   = new LabkiRepoRegistry();
        $refRegistry    = new LabkiRefRegistry();

        // Process each content source
        foreach ($resolvedSources as $source) {
            $repoUrl = $source['url'];  // Already resolved
            $refs   = $source['refs'] ?? ['main'];

            if (!$repoUrl) {
                wfDebugLog('labkipack', 'Skipping invalid content source (missing URL)');
                continue;
            }

            try {
                wfDebugLog('labkipack', "Initializing content repo {$repoUrl}");

                // Ensure the bare mirror exists and is current
                $barePath = $contentManager->ensureBareRepo($repoUrl);
                wfDebugLog('labkipack', "Bare repo ready at {$barePath}");

                $repoId = $repoRegistry->getRepoIdByUrl($repoUrl);
                if ($repoId === null) {
                    throw new \RuntimeException("Repository not found in DB after ensureBareRepo: {$repoUrl}");
                }

                foreach ($refs as $ref) {
                    wfDebugLog('labkipack', "Ensuring worktree for {$repoUrl}@{$ref}");
                    $worktreePath = $contentManager->ensureWorktree($repoUrl, $ref);
                    
                    // Initial Manifest Store update happens in ensureWorktree above
                }

            } catch (\Exception $e) {
                wfDebugLog('labkipack', "Error initializing {$repoUrl}: " . $e->getMessage());
            }
        }

        wfDebugLog('labkipack', 'All Labki content repositories initialized successfully');
    }
}

$maintClass = InitializeContentRepos::class;
require_once RUN_MAINTENANCE_IF_MAIN;