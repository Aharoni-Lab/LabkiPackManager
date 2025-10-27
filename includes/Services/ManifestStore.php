<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use WANObjectCache;
use LabkiPackManager\Parser\ManifestParser;
use LabkiPackManager\Services\HierarchyBuilder;
use LabkiPackManager\Services\GraphBuilder;
use LabkiPackManager\Services\LabkiRepoRegistry;

/**
 * ManifestStore
 *
 * Caches full structured manifest data (parsed, hierarchy, and graph) from local worktrees.
 * Cache is retained indefinitely and refreshed only when explicitly requested.
 * Git synchronization is handled by GitContentManager.
 *
 * Cached payload structure:
 * [
 *   'hash' => string,              // SHA1 of manifest.yml contents
 *   'content_repo_url' => string,  // Repository URL
 *   'content_ref' => string,       // Git ref (branch/tag/commit)
 *   'content_ref_name' => string,  // Descriptive name from manifest
 *   'last_parsed_at' => int,       // Timestamp of last parse
 *   'manifest' => array,           // Full parsed manifest data
 *   'pages' => array,              // Page definitions
 *   'hierarchy' => array,          // Pack hierarchy view model
 *   'graph' => array,              // Dependency graph
 * ]
 */
abstract class ManifestStore {

    private string $repoUrl;
    private string $ref;
    private string $cacheKey;
    private $cache;
    private ManifestFetcher $fetcher;

    public function __construct(
        string $repoUrl,
        string $ref,
        ?WANObjectCache $wanObjectCache = null,
        ?ManifestFetcher $fetcher = null
    ) {
        $this->repoUrl = $repoUrl;
        $this->ref = $ref;
        $this->cacheKey = 'labki:manifest:' . sha1($repoUrl . ':' . $ref);
        $this->cache = $wanObjectCache ?? $this->resolveCache();
        $this->fetcher = $fetcher ?? new ManifestFetcher();
    }

    /**
     * Retrieve structured manifest data from cache or by parsing the local worktree manifest.
     *
     * Git synchronization (fetch/checkout) is handled by GitContentManager before calling this.
     * This method only handles parsing and caching of the manifest.yml file.
     *
     * @param bool $forceRefresh If true, bypass cache and re-parse from disk
     * @return Status containing structured manifest data or error
     */
    public function get(bool $forceRefresh = false): Status {
        $cached = $this->getCached();

        // Return cached data unless refresh is forced
        if (!$forceRefresh && $cached !== null) {
            return Status::newGood($cached + ['from_cache' => true]);
        }

        // --- Fetch raw manifest from local worktree ---
        $fetched = $this->fetcher->fetch($this->repoUrl, $this->ref);
        if (!$fetched->isOK()) {
            // Return stale cache if available
            if ($cached !== null) {
                wfDebugLog('labkipack', "ManifestStore: fetch failed, returning stale cache for {$this->repoUrl}@{$this->ref}");
                return Status::newGood($cached + ['stale' => true]);
            }
            return $fetched;
        }

        $manifestYaml = $fetched->getValue();
        $manifestHash = sha1($manifestYaml);

        // --- Parse and structure ---
        $parser = new ManifestParser();
        try {
            $manifestData = $parser->parse($manifestYaml);
        } catch (\Throwable $e) {
            wfDebugLog('labkipack', "ManifestStore: parse failed for {$this->repoUrl}@{$this->ref}: " . $e->getMessage());
            return Status::newFatal('labkipackmanager-error-parse');
        }

        $packs = $manifestData['packs'] ?? [];
        $pages = $manifestData['pages'] ?? [];
        $hierarchy = (new HierarchyBuilder())->buildViewModel($packs);
        $graph = (new GraphBuilder())->build($packs);

        $name = isset($manifestData['name']) && is_string($manifestData['name']) ? $manifestData['name'] : null;

        $data = [
            'hash' => $manifestHash,
            'content_repo_url' => $this->repoUrl,
            'content_ref' => $this->ref,
            'last_parsed_at' => \wfTimestampNow(),
            'content_ref_name' => $name,
            'manifest' => $manifestData,
            'pages' => $pages,
            'hierarchy' => $hierarchy,
            'graph' => $graph,
        ];

        $this->save($data);
        wfDebugLog('labkipack', "ManifestStore: cached new manifest for {$this->repoUrl}@{$this->ref} (hash={$manifestHash})");
        return Status::newGood($data + ['from_cache' => false]);
    }

    private function getCached(): ?array {
        $val = $this->cache->get($this->cacheKey);
        return is_array($val) && isset($val['manifest']) ? $val : null;
    }

    private function save(array $data): void {
        $this->cache->set($this->cacheKey, $data, WANObjectCache::TTL_INDEFINITE);
    }

    public function clear(): void {
        $this->cache->delete($this->cacheKey);
    }

    private function resolveCache() {
        try {
            return MediaWikiServices::getInstance()->getMainWANObjectCache();
        } catch (\Throwable $e) {
            return new class() {
                private array $store = [];
                public function get(string $key) { return $this->store[$key] ?? null; }
                public function set(string $key, $value, int $ttl): void { $this->store[$key] = $value; }
                public function delete(string $key): void { unset($this->store[$key]); }
            };
        }
    }
}
