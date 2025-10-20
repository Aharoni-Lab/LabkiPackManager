<?php

declare(strict_types=1);

namespace LabkiPackManager\Util;

use MediaWiki\MediaWikiServices;

/**
 * UrlResolver
 *
 * Utility class for resolving repository URLs to git clone URLs.
 * Handles GitHub URLs and converts them to appropriate git clone URLs.
 */
final class UrlResolver {

    /**
     * Resolve a repository URL to a git clone URL.
     *
     * Supported forms:
     *  - github.com repo URLs (optionally with /tree/<ref> or /blob/<ref>/<path>)
     *    are converted to git clone URLs with appropriate refs
     *  - Already valid git clone URLs are returned as-is
     *  - Other HTTP(S) URLs are returned as-is for potential git clone operations
     *
     * @param string $repoUrl
     * @return string Git clone URL
     */
    public static function resolveContentRepoUrl(string $repoUrl): string {
        $trimRepoUrl = trim($repoUrl);

        if ($trimRepoUrl === '') {
            return $trimRepoUrl;
        }

        // Parse URL components
        $parts = @parse_url($trimRepoUrl) ?: [];
        $originalHost = $parts['host'] ?? '';
        $host = strtolower($originalHost);
        $path = $parts['path'] ?? '';

        // Helper to rebuild URL from parts
        $rebuild = function(array $p, array $originalParts = []) use ($originalHost): string {
            $scheme = $p['scheme'] ?? 'https';
            $host = $p['host'] ?? '';
            $path = $p['path'] ?? '';
            $query = isset($p['query']) ? ('?' . $p['query']) : (isset($originalParts['query']) ? ('?' . $originalParts['query']) : '');
            $fragment = isset($p['fragment']) ? ('#' . $p['fragment']) : (isset($originalParts['fragment']) ? ('#' . $originalParts['fragment']) : '');
            return $scheme . '://' . $host . $path . $query . $fragment;
        };

        // Already a git URL (ends with .git) - return as-is
        if (str_ends_with($path, '.git')) {
            return $trimRepoUrl;
        }

        // GitHub canonical URLs → git clone URLs
        if ($host === 'github.com' || $host === 'www.github.com') {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            if (count($segments) >= 2) {
                $owner = $segments[0];
                $repo = $segments[1];

                // Default branch from config (fallback to main)
                $defaultRef = 'main';
                try {
                    $cfg = MediaWikiServices::getInstance()->getMainConfig();
                    $val = (string)$cfg->get('LabkiDefaultBranch');
                    if ($val !== '') {
                        $defaultRef = $val;
                    }
                } catch (\Throwable $e) {}

                // Extract ref from URL if present
                $ref = $defaultRef;
                if (isset($segments[2]) && ($segments[2] === 'tree' || $segments[2] === 'blob')) {
                    $ref = $segments[3] ?? $defaultRef;
                }

                // Build git clone URL
                $gitUrl = [
                    'scheme' => 'https',
                    'host' => 'github.com',
                    'path' => '/' . $owner . '/' . $repo . '.git',
                ];
                return $rebuild($gitUrl, $parts);
            }
        }

        // raw.githubusercontent.com URLs → convert to github.com git URLs
        if ($host === 'raw.githubusercontent.com' || $host === 'raw.fastgit.org') {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            if (count($segments) >= 3) {
                $owner = $segments[0];
                $repo = $segments[1];
                // $segments[2] would be the ref, which we ignore for git clone
                
                $gitUrl = [
                    'scheme' => 'https',
                    'host' => 'github.com',
                    'path' => '/' . $owner . '/' . $repo . '.git',
                ];
                return $rebuild($gitUrl, $parts);
            }
        }

        // For other URLs, return as-is (they might already be valid git clone URLs)
        return $trimRepoUrl;
    }
}
