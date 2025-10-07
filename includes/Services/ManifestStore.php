<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\StatusValue;
use WANObjectCache;
use LabkiPackManager\Parser\ManifestParser;
use LabkiPackManager\Services\HierarchyBuilder;
use LabkiPackManager\Services\GraphBuilder;

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
 *       'fetchedAt'     => int
 *   ]
 * ]
 */
final class ManifestStore {

    private string $manifestUrl;
    private string $cacheKey;
    private WANObjectCache|object $cache;
    private ManifestFetcher $fetcher;

    public function __construct(
        string $manifestUrl,
        ?WANObjectCache $wanObjectCache = null,
        ?ManifestFetcher $fetcher = null
    ) {
        $this->manifestUrl = $manifestUrl;
        $this->cacheKey = 'labki:manifest:' . sha1($manifestUrl);
        $this->cache = $wanObjectCache ?? $this->resolveCache();
        $this->fetcher = $fetcher ?? new ManifestFetcher();
    }

    /**
     * Retrieve structured manifest data, refreshing only when changed or forced.
     */
    public function get(bool $forceRefresh = false): StatusValue {
        $cached = $this->getCached();

        // --- Check if repo has changed ---
        $remoteHash = $this->fetcher->headHash($this->manifestUrl);
        $hasChanged = $remoteHash && $cached && ($remoteHash !== ($cached['hash'] ?? ''));

        if (!$forceRefresh && !$hasChanged && $cached !== null) {
            return StatusValue::newGood($cached + ['from_cache' => true]);
        }

        // --- Fetch new manifest ---
        $fetched = $this->fetcher->fetch($this->manifestUrl);
        if (!$fetched->isOK()) {
            // Return stale cache if available
            if ($cached !== null) {
                return StatusValue::newGood($cached + ['stale' => true]);
            }
            return $fetched;
        }

        $manifestYaml = $fetched->getValue();
        $manifestHash = $remoteHash ?: sha1($manifestYaml);

        // --- Parse and structure ---
        $parser = new ManifestParser();
        try {
            $manifestData = $parser->parse($manifestYaml);
        } catch (\Throwable $e) {
            return StatusValue::newFatal('labkipackmanager-error-parse');
        }

        $packs = $manifestData['packs'] ?? [];
        $hierarchy = (new HierarchyBuilder())->buildViewModel($packs);
        $graph = (new GraphBuilder())->build($packs);

        $data = [
            'hash' => $manifestHash,
            'manifest' => $manifestData,
            'hierarchy' => $hierarchy,
            'graph' => $graph,
            '_meta' => [
                'schemaVersion' => 1,
                'manifestUrl' => $this->manifestUrl,
                'fetchedAt' => time(),
            ],
        ];

        $this->save($data);
        return StatusValue::newGood($data + ['from_cache' => false]);
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

    private function resolveCache(): WANObjectCache|object {
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
