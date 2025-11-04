<?php

declare(strict_types=1);

namespace LabkiPackManager\Util;

/**
 * UrlResolver
 *
 * Utility class for normalizing repository URLs into canonical base forms.
 * 
 * Key behaviors:
 * - Strips /tree/*, /blob/*, and file paths from GitHub URLs
 * - Converts raw.githubusercontent.com and raw.fastgit.org to github.com
 * - Removes trailing slashes and .git suffixes
 * - Normalizes www.github.com to github.com
 * - Always returns HTTPS for GitHub URLs
 * - Preserves original scheme for non-GitHub hosts
 * - Returns empty string for empty input
 */
final class UrlResolver {

    /**
     * Normalize a repository URL into a canonical base form.
     *
     * GitHub URLs are normalized to https://github.com/{owner}/{repo} format.
     * Raw GitHub URLs are converted to standard GitHub URLs.
     * Non-GitHub URLs are cleaned up but otherwise preserved.
     *
     * Examples:
     *   Input:  https://github.com/Aharoni-Lab/labki-packs
     *   Output: https://github.com/Aharoni-Lab/labki-packs
     *
     *   Input:  https://github.com/Aharoni-Lab/labki-packs/tree/main
     *   Output: https://github.com/Aharoni-Lab/labki-packs
     *
     *   Input:  https://raw.githubusercontent.com/Aharoni-Lab/labki-packs/main/manifest.yml
     *   Output: https://github.com/Aharoni-Lab/labki-packs
     *
     *   Input:  https://github.com/Aharoni-Lab/labki-packs.git
     *   Output: https://github.com/Aharoni-Lab/labki-packs
     *
     *   Input:  https://gitlab.com/user/repo
     *   Output: https://gitlab.com/user/repo
     *
     * @param string $repoUrl Repository URL to normalize
     * @return string Normalized canonical base URL (without .git suffix)
     */
    public static function resolveContentRepoUrl(string $repoUrl): string {
        // Trim whitespace
        $trimRepoUrl = trim($repoUrl);
        if ($trimRepoUrl === '') {
            return '';
        }

        // Parse URL components (suppress warnings for malformed URLs)
        $parts = @parse_url($trimRepoUrl) ?: [];
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        // Clean up path: remove trailing slashes and .git suffix
        $path = preg_replace(['#/$#', '/\.git$/i'], '', $path ?? '');

        // --- Normalize GitHub URLs ---
        // Handles: github.com, www.github.com
        // Strips: /tree/*, /blob/*, and any path segments beyond owner/repo
        if (in_array($host, ['github.com', 'www.github.com'], true)) {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            if (count($segments) >= 2) {
                // Extract owner and repo (first two segments)
                [$owner, $repo] = [$segments[0], $segments[1]];
                return "https://github.com/{$owner}/{$repo}";
            }
            // Not enough segments (e.g., just /user), return cleaned URL
            $cleanPath = $path ? '/' . ltrim($path, '/') : '';
            return "https://github.com{$cleanPath}";
        }

        // --- Normalize raw.githubusercontent.com / fastgit.org URLs ---
        // Converts raw content URLs back to standard GitHub repo URLs
        if (in_array($host, ['raw.githubusercontent.com', 'raw.fastgit.org'], true)) {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            if (count($segments) >= 2) {
                // Extract owner and repo (first two segments, ref and file path are ignored)
                [$owner, $repo] = [$segments[0], $segments[1]];
                return "https://github.com/{$owner}/{$repo}";
            }
        }

        // --- Fallback: return cleaned-up version for non-GitHub hosts ---
        // Preserves original scheme and host, cleans up path
        $scheme = $parts['scheme'] ?? 'https';
        $cleanHost = $parts['host'] ?? '';
        $cleanPath = $path ? '/' . ltrim($path, '/') : '';

        return "{$scheme}://{$cleanHost}{$cleanPath}";
    }
}
