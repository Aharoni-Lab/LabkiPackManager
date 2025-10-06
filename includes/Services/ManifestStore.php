<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\StatusValue;
use WANObjectCache;

/**
 * ManifestStore
 *
 * Caches parsed manifest data from Labki content repositories.
 * Prevents redundant network fetches and speeds up manifest access.
 *
 * Cached payload structure:
 * [
 *   'schemaVersion' => string|null,
 *   'manifestUrl'   => string,
 *   'fetchedAt'     => int,
 *   'packs'         => array
 * ]
 */
final class ManifestStore {

    private string $manifestUrl;
    private string $cacheKey;
    private WANObjectCache|object $cache;
    private int $ttlSeconds;
    private ManifestFetcher $fetcher;

    public function __construct(
        string $manifestUrl,
        ?WANObjectCache $wanObjectCache = null,
        ?int $ttlSeconds = null,
        ?ManifestFetcher $fetcher = null
    ) {
        $this->manifestUrl = $manifestUrl;
        $this->cacheKey = 'labki:manifest:' . sha1($manifestUrl);
        $this->cache = $wanObjectCache ?? $this->resolveCache();
        $this->ttlSeconds = $ttlSeconds ?? 86400; // 1 day default
        $this->fetcher = $fetcher ?? new ManifestFetcher();
    }

    /**
     * Retrieve manifest data, refreshing if missing or forced.
     *
     * @param bool $forceRefresh Whether to bypass cache.
     * @return StatusValue::newGood(array $manifest) or newFatal(error)
     */
    public function get(bool $forceRefresh = false): StatusValue {
        $cached = $this->getCached();

        // Serve cached manifest if fresh and not forced
        if (!$forceRefresh && $cached !== null && !$this->needsRefresh($cached)) {
            return StatusValue::newGood($cached + ['from_cache' => true]);
        }

        // Fetch fresh manifest from network or local source
        $fetched = $this->fetcher->fetch($this->manifestUrl);
        if (!$fetched->isOK()) {
            // Return stale cache as fallback if available
            if ($cached !== null) {
                return StatusValue::newGood($cached + ['stale' => true]);
            }
            return $fetched;
        }

        $data = $fetched->getValue();
        $this->save($data);

        return StatusValue::newGood($data + ['from_cache' => false]);
    }

    /**
     * Retrieve cached manifest, if available.
     */
    public function getCached(): ?array {
        $val = $this->cache->get($this->cacheKey);
        return (is_array($val) && isset($val['packs']) && is_array($val['packs'])) ? $val : null;
    }

    /**
     * Determine if the cached manifest is older than its TTL.
     */
    public function needsRefresh(array $cached): bool {
        $age = time() - ((int)($cached['fetchedAt'] ?? 0));
        return $age > $this->ttlSeconds;
    }

    /**
     * Save manifest payload to cache.
     *
     * @param array $data Must contain at least 'packs' key.
     */
    public function save(array $data): void {
        $payload = [
            'schemaVersion' => $data['schemaVersion'] ?? $data['schema_version'] ?? null,
            'manifestUrl'   => $data['manifestUrl'] ?? $this->manifestUrl,
            'fetchedAt'     => $data['fetchedAt'] ?? time(),
            'packs'         => $data['packs'] ?? [],
        ];
        $this->cache->set($this->cacheKey, $payload, $this->ttlSeconds);
    }

    /**
     * Delete manifest cache entry.
     */
    public function clear(): void {
        $this->cache->delete($this->cacheKey);
    }

    /**
     * Resolve MediaWiki cache or provide local fallback.
     */
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
