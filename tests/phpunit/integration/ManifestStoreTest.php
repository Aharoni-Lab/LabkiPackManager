<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration;

use LabkiPackManager\Services\ManifestStore;

/**
 * @coversDefaultClass \LabkiPackManager\Services\ManifestStore
 */
class ManifestStoreTest extends \MediaWikiIntegrationTestCase {
    /**
     * @covers ::savePacks
     * @covers ::getPacksOrNull
     */
    public function testSaveAndGetPacks(): void {
        $url = 'http://example.test/manifest.yml';
        $store = new ManifestStore( $url );
        $packs = [ [ 'id' => 'a', 'path' => 'p' ] ];

        $this->assertNull( $store->getPacksOrNull() );
        $store->savePacks( $packs );
        $this->assertSame( $packs, $store->getPacksOrNull() );
    }

    /**
     * @covers ::clear
     */
    public function testClearRemovesCachedPacks(): void {
        $store = new ManifestStore( 'http://example.test/manifest.yml' );
        $store->savePacks( [ [ 'id' => 'x', 'path' => 'y' ] ] );
        $this->assertNotNull( $store->getPacksOrNull() );

        $store->clear();
        $this->assertNull( $store->getPacksOrNull() );
    }

    /**
     * @covers ::savePacks
     * @covers ::getPacksOrNull
     */
    public function testDifferentUrlsHaveSeparateEntries(): void {
        $store1 = new ManifestStore( 'http://example.test/a.yml' );
        $store2 = new ManifestStore( 'http://example.test/b.yml' );

        $store1->savePacks( [ [ 'id' => 'a', 'path' => 'pa' ] ] );
        $this->assertNull( $store2->getPacksOrNull() );
        $this->assertNotNull( $store1->getPacksOrNull() );
    }
}


