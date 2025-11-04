<?php

declare(strict_types=1);

namespace LabkiPackManager\Util;

/**
 * ContentSourcesUtil
 *
 * Utility class for accessing and normalizing content repository sources from
 * the $wgLabkiContentSources MediaWiki configuration.
 *
 * This class provides a single point of access for content sources throughout
 * the application, ensuring all repository URLs are properly normalized via
 * UrlResolver and that refs are properly validated.
 *
 * Configuration Format:
 *   $wgLabkiContentSources = [
 *     'https://github.com/user/repo',                        // Simple string (uses 'main' ref)
 *     ['url' => 'https://github.com/user/repo2'],            // Array without refs (uses 'main')
 *     ['url' => 'https://github.com/user/repo3', 'refs' => ['main', 'dev']], // With refs
 *   ];
 *
 * Key behaviors:
 * - All URLs are normalized via UrlResolver::resolveContentRepoUrl()
 * - Missing refs default to ['main']
 * - Empty or invalid URLs are skipped with debug logging
 * - Invalid entries are logged and skipped
 * - Returns empty array if $wgLabkiContentSources is not set or invalid
 */
final class ContentSourcesUtil {

    /**
     * Get resolved content sources from $wgLabkiContentSources.
     *
     * Parses the global configuration, normalizes URLs, validates refs,
     * and returns a structured array of content sources.
     *
     * Each entry in the returned array has the format:
     * [
     *   'url' => string,      // Normalized repository URL
     *   'refs' => string[],   // Array of branch/tag names
     *   'original' => mixed   // Original configuration entry (for debugging)
     * ]
     *
     * @return array<int, array{url: string, refs: string[], original: mixed}> Array of resolved content sources
     */
    public static function getResolvedContentSources(): array {
        global $wgLabkiContentSources;

        // If no content sources are configured, return empty array
        if (!isset($wgLabkiContentSources) || !is_array($wgLabkiContentSources)) {
            wfDebugLog('labkipack', 'No LabkiContentSources configured');
            return [];
        }

        // Parse the content sources
        $parsedSources = self::parseSources($wgLabkiContentSources);
        
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
     * Convenience method that extracts only the normalized URLs from
     * the resolved content sources, discarding refs and original data.
     *
     * @return string[] Array of normalized repository URLs
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
     * Returns a simplified structure containing only URLs and refs,
     * without the original configuration data.
     *
     * @return array<int, array{url: string, refs: string[]}> Array of URL and refs pairs
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
     * Check if any content sources are configured and valid.
     *
     * Returns true if at least one valid content source exists after
     * parsing and URL resolution.
     *
     * @return bool True if content sources are configured and valid
     */
    public static function hasContentSources(): bool {
        return !empty(self::getResolvedContentSources());
    }

    /**
     * Get the original (unresolved) content sources configuration.
     *
     * Returns the raw $wgLabkiContentSources configuration without any
     * parsing or URL resolution. This should only be used for display
     * purposes or when clarity about the original configuration is needed.
     *
     * @return array|null Original $wgLabkiContentSources or null if not configured
     */
    public static function getOriginalContentSources(): ?array {
        global $wgLabkiContentSources;
        return $wgLabkiContentSources ?? null;
    }

    /**
     * Parse and normalize raw $wgLabkiContentSources entries.
     *
     * Accepts two formats:
     *   1. String URLs: 'https://github.com/user/repo'
     *   2. Array format: ['url' => '...', 'refs' => ['main', 'dev']]
     *
     * For string entries, defaults to ['main'] ref.
     * For array entries without refs, defaults to ['main'] ref.
     * For array entries with refs, validates and trims each ref.
     *
     * Invalid entries (non-string, non-array, or array without 'url' key)
     * are logged and skipped.
     *
     * @param array $sources Raw content sources from configuration
     * @return array<int, array{url: string, refs: string[], original: mixed}> Parsed sources
     */
    private static function parseSources(array $sources): array {
        $out = [];

        foreach ($sources as $entry) {
            // Handle simple string URL format
            if (is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed === '') {
                    wfDebugLog('labkipack', 'Skipping empty string content source entry');
                    continue;
                }
                $out[] = [
                    'url' => $trimmed,
                    'refs' => ['main'],
                    'original' => $entry,
                ];
            }
            // Handle array format with 'url' key
            elseif (is_array($entry) && isset($entry['url'])) {
                $url = trim((string)$entry['url']);
                if ($url === '') {
                    wfDebugLog('labkipack', 'Skipping content source entry with empty URL');
                    continue;
                }

                // Parse and validate refs
                $refs = isset($entry['refs']) ? (array)$entry['refs'] : ['main'];
                $refs = array_filter(array_map('trim', $refs));

                // If all refs were empty/invalid, default to ['main']
                if (empty($refs)) {
                    $refs = ['main'];
                }

                $out[] = [
                    'url' => $url,
                    'refs' => $refs,
                    'original' => $entry,
                ];
            }
            // Invalid entry format
            else {
                wfDebugLog('labkipack', 'Skipping invalid content source entry: ' . json_encode($entry));
            }
        }

        return $out;
    }
}
