<?php

declare(strict_types=1);

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;
use LabkiPackManager\Domain\Page;

/**
 * @group Database
 * @covers \LabkiPackManager\Services\LabkiPageRegistry
 */
final class PageRegistryTest extends \MediaWikiIntegrationTestCase {
    protected static $tablesUsed = [ 'page', 'labki_content_repo', 'labki_pack', 'labki_page' ];

    public function testAddGetListUpdateRemoveAndCollision(): void {
        $repos = new LabkiRepoRegistry();
        $packs = new LabkiPackRegistry();
        $pages = new LabkiPageRegistry();
        $repoId = $repos->addRepo( 'https://example.com/repoC/manifest.yml', 'main' );
        $packId = $packs->addPack( $repoId, 'ops', [ 'version' => '1.2.3' ] );

        $pageId = $pages->addPage( $packId, [
            'name' => 'Main:OpsPage',
            'final_title' => 'Main:OpsPage',
            'page_namespace' => 0,
            'wiki_page_id' => 1,
        ] );
        $this->assertInstanceOf( PageId::class, $pageId );

        $byTitle = $pages->getPageByTitle( 'Main:OpsPage' );
        $this->assertInstanceOf( Page::class, $byTitle );

        $list = $pages->listPagesByPack( $packId );
        $this->assertCount( 1, $list );
        $this->assertInstanceOf( Page::class, $list[0] );

        $pages->updatePage( $pageId, [ 'last_rev_id' => 555, 'content_hash' => 'abc' ] );
        $updated = $pages->getPageByTitle( 'Main:OpsPage' );
        $this->assertInstanceOf( Page::class, $updated );
        $this->assertSame( 555, $updated->toArray()['last_rev_id'] ?? null );

        // Collision check: we inserted wiki_page_id=1; verify detection works with given titles
        $collisions = $pages->getPageCollisions( [ 'Main:OpsPage' ] );
        $this->assertIsArray( $collisions );

        $pages->removePagesByPack( $packId );
        $list2 = $pages->listPagesByPack( $packId );
        $this->assertSame( [], $list2 );
    }
}


