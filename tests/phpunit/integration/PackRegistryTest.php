<?php

declare(strict_types=1);

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;

/**
 * @group Database
 * @covers \LabkiPackManager\Services\LabkiPackRegistry
 */
final class PackRegistryTest extends \MediaWikiIntegrationTestCase {
    protected static $tablesUsed = [ 'labki_content_repo', 'labki_pack', 'labki_page' ];

    public function testAddGetListUpdateDelete(): void {
        $repos = new LabkiRepoRegistry();
        $packs = new LabkiPackRegistry();
        $repoId = $repos->addRepo( 'https://example.com/repoB/manifest.yml', 'main' );

        $packId = $packs->addPack( $repoId, 'publication', [ 'version' => '1.0.0', 'installed_by' => 1 ] );
        $this->assertIsInt( $packId );

        $fetchedId = $packs->getPackIdByName( $repoId, 'publication', '1.0.0' );
        $this->assertSame( $packId, $fetchedId );

        $info = $packs->getPack( $packId );
        $this->assertSame( 'publication', $info['name'] );

        $list = $packs->listPacksByRepo( $repoId );
        $this->assertNotEmpty( $list );

        $packs->updatePack( $packId, [ 'status' => 'removed' ] );
        $info2 = $packs->getPack( $packId );
        $this->assertSame( 'removed', $info2['status'] );

        // Create a page under this pack then delete pack and verify pages cascade
        $pages = new LabkiPageRegistry();
        $pageId = $pages->addPage( $packId, [ 'name' => 'Ops:Doc', 'final_title' => 'Ops:Doc', 'page_namespace' => 0, 'wiki_page_id' => 3 ] );
        $this->assertGreaterThan( 0, $pageId );

        $this->assertTrue( $packs->deletePack( $packId ) );
        $this->assertNull( $packs->getPack( $packId ) );
        $this->assertSame( [], $pages->listPagesByPack( $packId ) );
    }
}


