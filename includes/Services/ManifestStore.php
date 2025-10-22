<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use WANObjectCache;
use LabkiPackManager\Parser\ManifestParser;
use LabkiPackManager\Services\HierarchyBuilder;
use LabkiPackManager\Services\GraphBuilder;
use LabkiPackManager\Util\UrlResolver;

/**
 * ManifestStore
 *
 * Caches full structured manifest data (parsed, hierarchy, and graph).
 * Cache is retained indefinitely and refreshed only when the remote manifest changes
 * or when a refresh is explicitly requested.
 *
 * Cached payload structure:
 * [
 *   'hash'          => string,  // SHA1 or ETag-equivalent
 *   'manifest'      => array,
 *   'hierarchy'     => array,
 *   'graph'         => array,
 *   '_meta' => [
 *       'schemaVersion' => int,
 *       'manifestUrl'   => string,
 *       'fetchedAt'     => int,
 *       'repoName'      => string
 *   ]
 * ]
 */
final class ManifestStore {

    private string $gitUrl;
    private string $cacheKey;
    private $cache;
    private ManifestFetcher $fetcher;

    public function __construct(
        string $gitUrl,
        string $ref,
        ?WANObjectCache $wanObjectCache = null,
        ?ManifestFetcher $fetcher = null
    ) {
        $gitUrl = UrlResolver::resolveContentRepoUrl($gitUrl);
        $this->gitUrl = $gitUrl;
        $this->ref = $ref;
        $this->cacheKey = 'labki:manifest:' . sha1($gitUrl . ':' . $ref);
        $this->cache = $wanObjectCache ?? $this->resolveCache();
        $this->fetcher = $fetcher ?? new ManifestFetcher();
    }

    /**
     * Retrieve structured manifest data, refreshing only when changed or forced.
     */
    public function get(bool $forceRefresh = false): Status {
        $cached = $this->getCached();

        // We use to check if hash has changed, to determine if we need to refresh the cache
        // But now we leave that work up to the GitContentManager to handle

        // If the cache is not empty and we are not forcing a refresh, return the cached data
        if (!$forceRefresh && $cached !== null) {
            return Status::newGood($cached + ['from_cache' => true]);
        }

        // --- Fetch new manifest ---
        $fetched = $this->fetcher->fetch($this->gitUrl, $this->ref);
        if (!$fetched->isOK()) {
            // Return stale cache if available
            if ($cached !== null) {
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
            return Status::newFatal('labkipackmanager-error-parse');
        }

        $packs = $manifestData['packs'] ?? [];
        $pages = $manifestData['pages'] ?? [];
        $hierarchy = (new HierarchyBuilder())->buildViewModel($packs);
        $graph = (new GraphBuilder())->build($packs);

        $repoName = isset($manifestData['name']) && is_string($manifestData['name']) ? $manifestData['name'] : null;

        $data = [
            'hash' => $manifestHash,
            'content_repo_url' => $this->gitUrl,
            'content_ref' => $this->ref,
            'fetched_at' => time(),
            'content_repo_name' => $repoName,
            'manifest' => $manifestData,
            'pages' => $pages,
            'hierarchy' => $hierarchy,
            'graph' => $graph,
        ];

        $this->save($data);
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
