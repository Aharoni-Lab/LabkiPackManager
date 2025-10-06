<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\StatusValue;
use WANObjectCache;

/**
 * ManifestStore
 *
 * Handles short-term caching of manifest data fetched from remote Labki content repositories.
 * This avoids redundant network requests while keeping data relatively fresh.
 *
 * Payload structure:
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
     * Retrieve manifest data, using cache if valid or fetching fresh if missing/stale.
     *
     * @param bool $forceRefresh If true, bypass cache and re-fetch.
     * @return StatusValue::newGood(array $manifest) or newFatal(error)
     */
    public function get(bool $forceRefresh = false): StatusValue {
        $cached = $this->getCached();
        if ( !$forceRefresh && $cached !== null ) {
            return StatusValue::newGood($cached);
        }

        $fetched = $this->fetcher->fetch($this->manifestUrl);
        if ( !$fetched->isOK() ) {
            // Return stale cache if available
            if ( $cached !== null ) {
                return StatusValue::newGood($cached);
            }
            return $fetched;
        }

        $data = $fetched->getValue();
        $this->save($data);
        return StatusValue::newGood($data);
    }

    /**
     * Return cached manifest if available.
     */
    public function getCached(): ?array {
        $val = $this->cache->get($this->cacheKey);
        return (is_array($val) && isset($val['packs']) && is_array($val['packs'])) ? $val : null;
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
     * Clear cached manifest.
     */
    public function clear(): void {
        $this->cache->delete($this->cacheKey);
    }

    private function resolveCache(): WANObjectCache|object {
        try {
            return MediaWikiServices::getInstance()->getMainWANObjectCache();
        } catch ( \Throwable $e ) {
            // Fallback in-memory cache for dev/testing
            return new class() {
                private array $store = [];
                public function get(string $key) { return $this->store[$key] ?? null; }
                public function set(string $key, $value, int $ttl): void { $this->store[$key] = $value; }
                public function delete(string $key): void { unset($this->store[$key]); }
            };
        }
    }
}
