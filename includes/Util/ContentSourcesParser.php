<?php

declare(strict_types=1);

namespace LabkiPackManager\Util;

/**
 * ContentSourceParser
 *
 * Utility class for parsing LabkiContentSources configuration.
 * Supports both legacy string format and structured format with url + ref(s).
 */
final class ContentSourcesParser {

    /**
     * Parse LabkiContentSources configuration into standardized format.
     *
     * Each entry becomes: [
     *   'url'  => string,
     *   'refs' => string[],   // always array, never null
     *   'original' => mixed
     * ]
     */
    public static function parse(array $sources): array {
        $parsed = [];

        foreach ($sources as $source) {
            $parsedSource = self::parseSource($source);
            if ($parsedSource !== null) {
                $parsed[] = $parsedSource;
            }
        }

        return $parsed;
    }

    /**
     * Parse a single source entry.
     */
    public static function parseSource(mixed $source): ?array {
        // Handle legacy string format
        if (is_string($source)) {
            return [
                'url' => $source,
                'refs' => ['main'],
                'original' => $source
            ];
        }

        // Handle new array/object format
        if (is_array($source) && isset($source['url'])) {
            $url = (string)$source['url'];
            $refs = $source['refs'] ?? ['main'];

            return [
                'url' => $url,
                'refs' => $refs,
                'original' => $source
            ];
        }

        wfDebugLog('labkipack', 'Invalid content source format: ' . json_encode($source));
        return null;
    }

    /**
     * Extract just the URLs from sources.
     */
    public static function extractUrls(array $sources): array {
        return array_map(fn($src) => $src['url'], self::parse($sources));
    }

    /**
     * Extract URLs and associated refs.
     */
    public static function extractUrlsWithRefs(array $sources): array {
        $result = [];
        foreach (self::parse($sources) as $src) {
            $result[] = [
                'url' => $src['url'],
                'refs' => $src['refs']
            ];
        }
        return $result;
    }

    /**
     * Validate overall structure.
     */
    public static function isValid(mixed $sources): bool {
        if (!is_array($sources)) {
            wfDebugLog('labkipack', 'Invalid LabkiContentSources: not an array');
            return false;
        }

        foreach ($sources as $i => $source) {
            if (!self::isValidSource($source)) {
                wfDebugLog('labkipack', "Invalid source entry at index {$i}: " . json_encode($source));
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a single entry.
     */
    public static function isValidSource(mixed $source): bool {
        if (is_string($source)) {
            return trim($source) !== '';
        }

        if (is_array($source) && isset($source['url']) && is_string($source['url'])) {
            $url = trim($source['url']);
            if ($url === '') {
                return false;
            }

            if (isset($source['ref'])) {
                return is_string($source['ref']) || is_array($source['ref']);
            }

            return true;
        }

        return false;
    }

    /**
     * Human-readable format description.
     */
    public static function getFormatDescription(): string {
        return 'LabkiContentSources supports two formats: ' .
               '1) Array of URL strings, or ' .
               '2) Array of objects with "url" (required) and "ref" (string or array) properties.';
    }
}
