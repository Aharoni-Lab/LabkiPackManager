<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use WANObjectCache;

/**
 * ManifestStore
 *
 * Handles short-term caching of manifest data fetched from remote Labki content repositories.
 * Used to avoid redundant network requests and speed up page loads.
 *
 * Data stored here is ephemeral (cache only, not persistent DB state).
 *
 * Typical payload structure:
 * [
 *   'schemaVersion' => '1.0.0',
 *   'manifestUrl'   => 'https://example.com/manifest.yml',
 *   'fetchedAt'     => 1728345000,
 *   'packs'         => [ ... ]
 * ]
 */
final class ManifestStore {
    /** @var string Cache key for the manifest */
    private string $cacheKey;

    /** @var WANObjectCache|object|null */
    private $cache;

    /** @var int Cache time-to-live (seconds) */
    private int $ttlSeconds;

    /**
     * @param string $manifestUrl  URL identifying the manifest.
     * @param WANObjectCache|null $wanObjectCache Optional MediaWiki cache instance.
     * @param int|null $ttlSeconds Optional TTL override in seconds (default 1 day).
     */
    public function __construct(string $manifestUrl, $wanObjectCache = null, ?int $ttlSeconds = null) {
        $this->cacheKey = $this->buildCacheKey($manifestUrl);
        $this->cache = $wanObjectCache ?? $this->resolveCache();
        $this->ttlSeconds = $ttlSeconds ?? (24 * 60 * 60);
    }

    /**
     * Generate a stable cache key for the manifest.
     */
    private function buildCacheKey(string $url): string {
        return 'labki:manifest:' . sha1($url);
    }

    /**
     * Return cached manifest data if available.
     *
     * @return array|null Manifest payload or null if not cached.
     */
    public function getManifestOrNull(): ?array {
        $val = $this->cache->get($this->cacheKey);
        if (!is_array($val)) {
            return null;
        }
        // Validate expected payload structure
        if (!isset($val['packs']) || !is_array($val['packs'])) {
            return null;
        }
        return $val;
    }

    /**
     * Save manifest data to cache.
     *
     * @param array $packs  Array of pack metadata.
     * @param array|null $meta Optional meta info: ['schemaVersion', 'manifestUrl', 'fetchedAt']
     */
    public function saveManifest(array $packs, ?array $meta = null): void {
        $payload = [
            'schemaVersion' => $meta['schemaVersion'] ?? $meta['schema_version'] ?? null,
            'manifestUrl'   => $meta['manifestUrl'] ?? $meta['manifest_url'] ?? null,
            'fetchedAt'     => $meta['fetchedAt'] ?? time(),
            'packs'         => $packs,
        ];
        $this->cache->set($this->cacheKey, $payload, $this->ttlSeconds);
    }

    /**
     * Return only manifest metadata.
     *
     * @return array|null e.g. ['schemaVersion' => ..., 'manifestUrl' => ..., 'fetchedAt' => ...]
     */
    public function getMetaOrNull(): ?array {
        $val = $this->cache->get($this->cacheKey);
        if (!is_array($val)) {
            return null;
        }
        return [
            'schemaVersion' => $val['schemaVersion'] ?? $val['schema_version'] ?? null,
            'manifestUrl'   => $val['manifestUrl'] ?? $val['manifest_url'] ?? null,
            'fetchedAt'     => $val['fetchedAt'] ?? null,
        ];
    }

    /**
     * Clear manifest cache entry.
     */
    public function clear(): void {
        $this->cache->delete($this->cacheKey);
    }

    /**
     * Resolve MediaWiki WAN cache if possible, else fallback to in-memory cache.
     */
    private function resolveCache() {
        if (class_exists(MediaWikiServices::class)) {
            try {
                return MediaWikiServices::getInstance()->getMainWANObjectCache();
            } catch (\Throwable $e) {
                // Fallback to local memory cache if MW cache is unavailable.
            }
        }

        // Simple in-memory fallback cache for tests or non-MW contexts.
        return new class() {
            private array $store = [];
            public function get(string $key) {
                return $this->store[$key] ?? null;
            }
            public function set(string $key, $value, int $ttl): void {
                $this->store[$key] = $value;
            }
            public function delete(string $key): void {
                unset($this->store[$key]);
            }
        };
    }
}
