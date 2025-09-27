<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;

class ManifestStore {
    private string $cacheKey;
    /** @var object|null */
    private $wanObjectCache = null;
    private int $ttlSeconds;

    public function __construct( string $manifestUrl, $wanObjectCache = null, ?int $ttlSeconds = null ) {
        $this->cacheKey = $this->buildCacheKey( $manifestUrl );
        $this->wanObjectCache = $wanObjectCache;
        // Default TTL: 24 hours unless overridden
        $this->ttlSeconds = $ttlSeconds ?? ( 24 * 60 * 60 );
    }

    private function buildCacheKey( string $url ) : string {
        $hash = sha1( $url );
        return "labkipackmanager:manifest:" . $hash;
    }

    /**
     * @return array|null Cached packs array or null if not set
     */
    public function getPacksOrNull() : ?array {
        $cache = $this->resolveCache();
        $val = $cache->get( $this->cacheKey );
        if ( is_array( $val ) ) {
            // Support new payload shape { packs, schema_version?, fetched_at?, manifest_url? }
            if ( array_key_exists( 'packs', $val ) && is_array( $val['packs'] ) ) {
                return $val['packs'];
            }
            // Backward compatibility: value was stored as packs array directly
            return $val;
        }
        return null;
    }

    /**
     * @param array $packs
     */
    public function savePacks( array $packs, ?array $meta = null ) : void {
        $cache = $this->resolveCache();
        $payload = [
            'packs' => $packs,
            'schema_version' => $meta['schema_version'] ?? null,
            'fetched_at' => $meta['fetched_at'] ?? time(),
            'manifest_url' => $meta['manifest_url'] ?? null,
        ];
        $cache->set( $this->cacheKey, $payload, $this->ttlSeconds );
    }

    public function clear() : void {
        $cache = $this->resolveCache();
        $cache->delete( $this->cacheKey );
    }

    /**
     * @return array|null { schema_version?:string, fetched_at?:int, manifest_url?:string }
     */
    public function getMetaOrNull() : ?array {
        $cache = $this->resolveCache();
        $val = $cache->get( $this->cacheKey );
        if ( !is_array( $val ) ) {
            return null;
        }
        if ( array_key_exists( 'packs', $val ) ) {
            return [
                'schema_version' => $val['schema_version'] ?? null,
                'fetched_at' => $val['fetched_at'] ?? null,
                'manifest_url' => $val['manifest_url'] ?? null,
            ];
        }
        return null;
    }

    private function resolveCache() {
        if ( $this->wanObjectCache ) {
            return $this->wanObjectCache;
        }
        // Try MediaWiki cache if available and initialized
        if ( class_exists( '\\MediaWiki\\MediaWikiServices' ) ) {
            try {
                return MediaWikiServices::getInstance()->getMainWANObjectCache();
            } catch ( \LogicException $e ) {
                // Fall through to local cache
            }
        }
        // Local in-memory cache fallback for unit tests without MW container
        $this->wanObjectCache = new class() {
            private array $store = [];
            public function get( string $key ) {
                return $this->store[$key] ?? null;
            }
            public function set( string $key, $value, int $ttl ) : void {
                $this->store[$key] = $value;
            }
            public function delete( string $key ) : void {
                unset( $this->store[$key] );
            }
        };
        return $this->wanObjectCache;
    }
}


