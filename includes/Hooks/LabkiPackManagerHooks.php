<?php

declare(strict_types=1);

namespace LabkiPackManager\Hooks;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\GitContentRepoManager;
use LabkiPackManager\Services\ManifestStore;
use LabkiPackManager\Util\UrlResolver;
use LabkiPackManager\Util\ContentSourcesParser;
use Exception;

class LabkiPackManagerHooks {

    /**
     * Inject LabkiContentSources into mw.config for JS.
     *
     * (Legacy: planned for removal once frontend uses API.)
     */
    public static function onBeforePageDisplay( $out, $skin ): void {
        global $wgLabkiContentSources;

        if ( isset( $wgLabkiContentSources ) && is_array( $wgLabkiContentSources ) ) {
            $out->addJsConfigVars( 'LabkiContentSources', $wgLabkiContentSources );
        }
    }

     /**
     * Initialize content repositories defined in $wgLabkiContentSources.
     *
     * - Ensures each content repo has a bare mirror (shared).
     * - Ensures each requested ref has a dedicated worktree.
     * - Registers/updates both repo and ref entries in DB.
     * - Optionally updates repo name from manifest.
     */
    public static function onSetupAfterCache(): void {
        global $wgLabkiContentSources;

        // If no content sources are configured, return
        if ( !isset( $wgLabkiContentSources ) || !is_array( $wgLabkiContentSources ) ) {
            wfDebugLog('labkipack', 'No LabkiContentSources configured');
            return;
        }

        // Parse the content sources
        $parsedSources = ContentSourcesParser::parse($wgLabkiContentSources);
        // If no valid content sources are found, return
        if (empty($parsedSources)) {
            wfDebugLog('labkipack', 'No valid LabkiContentSources found after parsing');
            return;
        }

        wfDebugLog('labkipack', 'Initializing Labki content repositories...');

        // Initialize managers and registries
        $contentManager = new GitContentManager();   // formerly GitContentRepoManager
        $repoRegistry   = new LabkiRepoRegistry();
        $refRegistry    = new LabkiRefRegistry();

        // Process each content source
        foreach ($parsedSources as $source) {
            $repoUrl = $source['url'];
            $refs    = $source['refs'] ?? ['main'];

            if (!$repoUrl) {
                wfDebugLog('labkipack', 'Skipping invalid content source (missing URL)');
                continue;
            }

            try {
                // Normalize URL (removes .git, cleans up GitHub links)
                $gitUrl = UrlResolver::resolveContentRepoUrl($repoUrl);
                wfDebugLog('labkipack', "Initializing content repo {$gitUrl}");

                // Ensure the bare mirror exists and is current
                $barePath = $contentManager->ensureBareRepo($gitUrl);
                wfDebugLog('labkipack', "Bare repo ready at {$barePath}");

                $repoId = $repoRegistry->getRepoIdByUrl($gitUrl);
                if ($repoId === null) {
                    throw new \RuntimeException("Repository not found in DB after ensureBareRepo: {$gitUrl}");
                }

                foreach ($refs as $ref) {
                    wfDebugLog('labkipack', "Ensuring worktree for {$gitUrl}@{$ref}");
                    $worktreePath = $contentManager->ensureWorktree($gitUrl, $ref);
                    
                    try {
                        // Try to read manifest and update name
                        $manifestStore = new ManifestStore($worktreePath);
                        $manifestResult = $manifestStore->get();

                        if ($manifestResult->isOK()) {
                            $manifest = $manifestResult->getValue();
                            if (isset($manifest['manifest']['name'])) {
                                $name = $manifest['manifest']['name'];
                                $repoRegistry->updateRepoEntry($repoId, [
                                    'content_repo_name' => $name,
                                    'updated_at' => \wfTimestampNow(),
                                ]);
                                wfDebugLog('labkipack', "Updated repo name to '{$name}' for {$gitUrl}");
                            }
                        }
                    } catch (\Exception $e) {
                        wfDebugLog('labkipack', "Manifest parse failed for {$gitUrl}@{$ref}: " . $e->getMessage());
                    }
                }

            } catch (\Exception $e) {
                wfDebugLog('labkipack', "Error initializing {$repoUrl}: " . $e->getMessage());
            }
        }

        wfDebugLog('labkipack', 'All Labki content repositories initialized successfully');
    }

}
