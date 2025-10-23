<?php

declare(strict_types=1);

namespace LabkiPackManager\Util;

/**
 * ContentSourcesUtil
 *
 * Utility class for providing resolved content sources from $wgLabkiContentSources.
 * This ensures all URLs are properly resolved through UrlResolver::resolveContentRepoUrl
 * and provides a single point of access for content sources throughout the application.
 */
final class ContentSourcesUtil {

    /**
     * Get resolved content sources from $wgLabkiContentSources.
     *
     * Returns an array of resolved content sources with normalized URLs.
     * Each entry has the format:
     * [
     *   'url' => string (resolved URL),
     *   'refs' => string[],
     *   'original' => mixed (original source entry)
     * ]
     *
     * @return array Array of resolved content sources
     */
    public static function getResolvedContentSources(): array {
        global $wgLabkiContentSources;

        // If no content sources are configured, return empty array
        if (!isset($wgLabkiContentSources) || !is_array($wgLabkiContentSources)) {
            wfDebugLog('labkipack', 'No LabkiContentSources configured');
            return [];
        }

        // Parse the content sources
        $parsedSources = ContentSourcesParser::parse($wgLabkiContentSources);
        
        // If no valid content sources are found, return empty array
        if (empty($parsedSources)) {
            wfDebugLog('labkipack', 'No valid LabkiContentSources found after parsing');
            return [];
        }

        // Resolve URLs for all sources
        $resolvedSources = [];
        foreach ($parsedSources as $source) {
            $resolvedUrl = UrlResolver::resolveContentRepoUrl($source['url']);
            if ($resolvedUrl) {
                $resolvedSources[] = [
                    'url' => $resolvedUrl,
                    'refs' => $source['refs'],
                    'original' => $source['original']
                ];
            } else {
                wfDebugLog('labkipack', "Failed to resolve URL: {$source['url']}");
            }
        }

        return $resolvedSources;
    }

    /**
     * Get just the resolved URLs from content sources.
     *
     * @return array Array of resolved URLs
     */
    public static function getResolvedUrls(): array {
        return array_map(
            fn($source) => $source['url'], 
            self::getResolvedContentSources()
        );
    }

    /**
     * Get resolved URLs with their associated refs.
     *
     * @return array Array of ['url' => string, 'refs' => string[]] entries
     */
    public static function getResolvedUrlsWithRefs(): array {
        return array_map(
            fn($source) => [
                'url' => $source['url'],
                'refs' => $source['refs']
            ],
            self::getResolvedContentSources()
        );
    }

    /**
     * Check if any content sources are configured.
     *
     * @return bool True if content sources are configured and valid
     */
    public static function hasContentSources(): bool {
        return !empty(self::getResolvedContentSources());
    }

    /**
     * Get the original (unresolved) content sources configuration.
     * This should only be used for display purposes or when clarity about
     * the original configuration is needed.
     *
     * @return array|null Original $wgLabkiContentSources or null if not configured
     */
    public static function getOriginalContentSources(): ?array {
        global $wgLabkiContentSources;
        return $wgLabkiContentSources ?? null;
    }
}
