<?php

declare(strict_types=1);

namespace LabkiPackManager\Util;

use MediaWiki\MediaWikiServices;

/**
 * UrlResolver
 *
 * Utility class for resolving repository URLs into canonical base repo URLs.
 * Cleans up GitHub and raw.githubusercontent.com variants into normalized forms.
 */
final class UrlResolver {

    /**
     * Normalize a repository URL into a canonical, clone-ready form.
     *
     * Examples:
     *   https://github.com/Aharoni-Lab/labki-packs
     *   https://github.com/Aharoni-Lab/labki-packs/tree/main
     *   https://raw.githubusercontent.com/Aharoni-Lab/labki-packs/main/manifest.yml
     *
     * All resolve to:
     *   https://github.com/Aharoni-Lab/labki-packs
     *
     * @param string $repoUrl Possibly messy input URL
     * @return string Normalized canonical base URL
     */
    public static function resolveContentRepoUrl(string $repoUrl): string {
        $trimRepoUrl = trim($repoUrl);
        if ($trimRepoUrl === '') {
            return '';
        }

        $parts = @parse_url($trimRepoUrl) ?: [];
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        // Remove trailing slash or ".git" suffix
        $path = preg_replace(['#/$#', '/\.git$/i'], '', $path ?? '');

        // --- Normalize GitHub URLs ---
        if (in_array($host, ['github.com', 'www.github.com'], true)) {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            if (count($segments) >= 2) {
                [$owner, $repo] = [$segments[0], $segments[1]];
                return "https://github.com/{$owner}/{$repo}";
            }
        }

        // --- Normalize raw.githubusercontent.com / fastgit.org URLs ---
        if (in_array($host, ['raw.githubusercontent.com', 'raw.fastgit.org'], true)) {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            if (count($segments) >= 2) {
                [$owner, $repo] = [$segments[0], $segments[1]];
                return "https://github.com/{$owner}/{$repo}";
            }
        }

        // --- Fallback: return cleaned-up version ---
        $scheme = $parts['scheme'] ?? 'https';
        $cleanHost = $parts['host'] ?? '';
        $cleanPath = $path ? '/' . ltrim($path, '/') : '';

        return "{$scheme}://{$cleanHost}{$cleanPath}";
    }
}
