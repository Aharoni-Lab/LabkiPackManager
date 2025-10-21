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
     * - Clones or updates each repo.
     * - Ensures DB entry exists (unique per URL+ref).
     * - Parses manifest to update repo name when possible.
     */
    public static function onMediaWikiServices(): void {
        global $wgLabkiContentSources;

        if ( !isset( $wgLabkiContentSources ) || !is_array( $wgLabkiContentSources ) ) {
            wfDebugLog( 'labkipack', 'No LabkiContentSources configured' );
            return;
        }

        $parsedSources = ContentSourcesParser::parse( $wgLabkiContentSources );
        if ( empty( $parsedSources ) ) {
            wfDebugLog( 'labkipack', 'No valid LabkiContentSources found after parsing' );
            return;
        }

        $contentRepoManager = new GitContentRepoManager();
        $repoRegistry = new LabkiRepoRegistry();

        foreach ( $parsedSources as $source ) {
            $repoUrl = $source['url'];
            $refs = $source['refs'];

            if ( !$repoUrl ) {
                wfDebugLog( 'labkipack', 'Skipping invalid source: missing URL' );
                continue;
            }

            foreach ( $refs as $ref ) {
                wfDebugLog( 'labkipack', "Processing content repo: {$repoUrl}@{$ref}" );

                try {
                    // Resolve and clone
                    $gitUrl = UrlResolver::resolveContentRepoUrl( $repoUrl );
                    $localPath = $contentRepoManager->ensureClone( $gitUrl, $ref );

                    wfDebugLog( 'labkipack', "Repo cloned/updated at {$localPath}" );

                    // Currently also called in ensureClone, but we'll do it here for safety
                    $repoId = $repoRegistry->ensureRepoEntry( $gitUrl, $ref );
                    wfDebugLog( 'labkipack', "Registered repo entry: ID={$repoId->toInt()} ({$gitUrl}@{$ref})" );

                    // Try to update repo name from manifest
                    try {
                        $manifestStore = new ManifestStore( $gitUrl );
                        $manifestResult = $manifestStore->get();

                        if ( $manifestResult->isOK() ) {
                            $manifest = $manifestResult->getValue();
                            if ( isset( $manifest['manifest']['name'] ) ) {
                                $name = $manifest['manifest']['name'];
                                $repoRegistry->updateRepoEntry(
                                    $repoId,
                                    [ 'content_repo_name' => $name ]
                                );
                                wfDebugLog( 'labkipack', "Updated repo name to '{$name}' for {$gitUrl}@{$ref}" );
                            }
                        }
                    } catch ( Exception $e ) {
                        wfDebugLog( 'labkipack', "Manifest parse failed for {$gitUrl}@{$ref}: " . $e->getMessage() );
                    }

                } catch ( Exception $e ) {
                    wfDebugLog( 'labkipack', "Error initializing {$repoUrl}@{$ref}: " . $e->getMessage() );
                }
            }
        }
    }
}
