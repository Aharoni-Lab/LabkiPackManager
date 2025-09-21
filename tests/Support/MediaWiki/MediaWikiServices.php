<?php

namespace MediaWiki;

use MediaWiki\Services\HttpRequestFactory;
use MediaWiki\Cache\WANObjectCache;

class MediaWikiServices {
    private static ?MediaWikiServices $instance = null;

    private array $config = [];
    private HttpRequestFactory $httpRequestFactory;
    private WANObjectCache $wanObjectCache;

    private function __construct() {
        $this->httpRequestFactory = new HttpRequestFactory();
        $this->wanObjectCache = new WANObjectCache();
    }

    public static function getInstance() : MediaWikiServices {
        if ( self::$instance === null ) {
            self::$instance = new MediaWikiServices();
        }
        return self::$instance;
    }

    public static function resetForTests() : void {
        self::$instance = new MediaWikiServices();
    }

    public function getMainConfig() : MediaWikiTestConfig {
        return new MediaWikiTestConfig( $this->config );
    }

    public function setConfigForTests( array $config ) : void {
        $this->config = $config;
    }

    public function getHttpRequestFactory() : HttpRequestFactory {
        return $this->httpRequestFactory;
    }

    public function getMainWANObjectCache() : WANObjectCache {
        return $this->wanObjectCache;
    }
}

class MediaWikiTestConfig {
    private array $config;

    public function __construct( array $config ) {
        $this->config = $config;
    }

    public function get( string $key ) {
        return $this->config[$key] ?? null;
    }
}


