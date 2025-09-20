<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit;

use LabkiPackManager\Services\ManifestStore;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\TestCase;

class ManifestStoreTest extends TestCase {
    /**
     * Saving packs makes them retrievable via the same URL-based store.
     */
    public function testSaveAndGetPacks(): void {
        MediaWikiServices::resetForTests();
        $url = 'http://example.test/manifest.yml';
        $store = new ManifestStore( $url );
        $packs = [ [ 'id' => 'a', 'path' => 'p' ] ];

        $this->assertNull( $store->getPacksOrNull() );
        $store->savePacks( $packs );
        $this->assertSame( $packs, $store->getPacksOrNull() );
    }

    /**
     * Clearing removes the cached packs for that URL.
     */
    public function testClearRemovesCachedPacks(): void {
        MediaWikiServices::resetForTests();
        $store = new ManifestStore( 'http://example.test/manifest.yml' );
        $store->savePacks( [ [ 'id' => 'x', 'path' => 'y' ] ] );
        $this->assertNotNull( $store->getPacksOrNull() );

        $store->clear();
        $this->assertNull( $store->getPacksOrNull() );
    }

    /**
     * Different manifest URLs do not collide in cache.
     */
    public function testDifferentUrlsHaveSeparateEntries(): void {
        MediaWikiServices::resetForTests();
        $store1 = new ManifestStore( 'http://example.test/a.yml' );
        $store2 = new ManifestStore( 'http://example.test/b.yml' );

        $store1->savePacks( [ [ 'id' => 'a', 'path' => 'pa' ] ] );
        $this->assertNull( $store2->getPacksOrNull() );
        $this->assertNotNull( $store1->getPacksOrNull() );
    }
}


