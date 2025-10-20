<?php
namespace LabkiPackManager\Hooks;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\GitContentRepoManager;
use LabkiPackManager\Util\UrlResolver;

class LabkiPackManagerHooks {
    /**
     * Inject LabkiContentSources into mw.config for JS.
     */
    // TODO: Remove this and just pass content repo table DB info to frontend via API
    public static function onBeforePageDisplay( $out, $skin ): void {
        global $wgLabkiContentSources;
        if ( isset( $wgLabkiContentSources ) && is_array( $wgLabkiContentSources ) ) {
            $out->addJsConfigVars( 'LabkiContentSources', $wgLabkiContentSources );
        }
    }

    /**
     * Initialize the content repo registry from the content sources.
     */
    public static function onMediaWikiServices() {
        global $wgLabkiContentSources, $wgLabkiContentRepoClonePath;

        $urlResolver = new UrlResolver();
        $gitUrl = $urlResolver->resolveContentRepoUrl($wgLabkiContentRepoClonePath);
        $repoRegistry = new LabkiRepoRegistry();

        foreach ($wgLabkiContentSources as $url) {
            // try {
            //     $path = $gitUrl->ensureClone($url);
            //     $manifestPath = "$path/manifest.yml";
            //     if (file_exists($manifestPath)) {
            //         $yaml = yaml_parse_file($manifestPath);
            //         $repoName = $yaml['repo']['name'] ?? basename($url, '.git');
            //         $displayName = $yaml['repo']['display_name'] ?? $repoName;
            //         $defaultRef = $yaml['repo']['default_ref'] ?? 'main';
            //         $repoRegistry->ensureRepoEntry($url, $repoName, $displayName, $defaultRef);
            //     }
            // } catch (Exception $e) {
            //     wfDebugLog('LabkiPackManager', "Failed to initialize repo $url: " . $e->getMessage());
            // }
        }
    }
}
