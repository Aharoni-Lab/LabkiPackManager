<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;

class ManifestStore {
    private string $cacheKey;

    public function __construct( string $manifestUrl ) {
        $this->cacheKey = $this->buildCacheKey( $manifestUrl );
    }

    private function buildCacheKey( string $url ) : string {
        $hash = sha1( $url );
        return "labkipackmanager:manifest:" . $hash;
    }

    /**
     * @return array|null Cached packs array or null if not set
     */
    public function getPacksOrNull() : ?array {
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $val = $cache->get( $this->cacheKey );
        return is_array( $val ) ? $val : null;
    }

    /**
     * @param array $packs
     */
    public function savePacks( array $packs ) : void {
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        // Long TTL; manual refresh will overwrite
        $ttl = 365 * 24 * 60 * 60;
        $cache->set( $this->cacheKey, $packs, $ttl );
    }

    public function clear() : void {
        $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $cache->delete( $this->cacheKey );
    }
}


