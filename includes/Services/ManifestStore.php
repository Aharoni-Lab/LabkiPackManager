<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;

class ManifestStore {
    private string $cacheKey;
    /** @var object|null */
    private $wanObjectCache = null;

    public function __construct( string $manifestUrl, $wanObjectCache = null ) {
        $this->cacheKey = $this->buildCacheKey( $manifestUrl );
        $this->wanObjectCache = $wanObjectCache;
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
        return is_array( $val ) ? $val : null;
    }

    /**
     * @param array $packs
     */
    public function savePacks( array $packs ) : void {
        $cache = $this->resolveCache();
        // Long TTL; manual refresh will overwrite
        $ttl = 365 * 24 * 60 * 60;
        $cache->set( $this->cacheKey, $packs, $ttl );
    }

    public function clear() : void {
        $cache = $this->resolveCache();
        $cache->delete( $this->cacheKey );
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


