<?php

declare(strict_types=1);

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;

/**
 * @group API
 * @group Database
 * @covers \LabkiPackManager\API\ApiLabkiQuery
 */
final class ApiLabkiQueryTest extends \MediaWikiIntegrationTestCase {
    protected static $tablesUsed = [ 'page', 'labki_content_repo', 'labki_pack', 'labki_page' ];

    public function testQueryReposPacksPages(): void {
        $repos = new LabkiRepoRegistry();
        $packs = new LabkiPackRegistry();
        $pages = new LabkiPageRegistry();
        $repoId = $repos->addRepo( 'https://example.com/repoD/manifest.yml', 'main' );
        $packId = $packs->addPack( $repoId, 'chemistry', [ 'version' => '0.1.0' ] );
        $pages->addPage( $packId, [ 'name' => 'Chem:Intro', 'final_title' => 'Chem:Intro', 'page_namespace' => 0, 'wiki_page_id' => 2 ] );

        // repos listing
        $req1 = new \MediaWiki\Request\FauxRequest( [ 'action' => 'labkiquery', 'format' => 'json' ] );
        $api1 = new \ApiMain( $req1 );
        $api1->execute();
        $data1 = $api1->getResult()->getResultData( null );
        $out1 = $data1['labkiquery'] ?? $data1;
        $this->assertArrayHasKey( 'repos', $out1 );

        // packs by repo (use repoId param)
        $req2 = new \MediaWiki\Request\FauxRequest( [ 'action' => 'labkiquery', 'format' => 'json', 'repo' => (string)$repoId ] );
        $api2 = new \ApiMain( $req2 );
        $api2->execute();
        $data2 = $api2->getResult()->getResultData( null );
        $out2 = $data2['labkiquery'] ?? $data2;
        $this->assertArrayHasKey( 'packs', $out2 );

        // pages by pack
        $req3 = new \MediaWiki\Request\FauxRequest( [ 'action' => 'labkiquery', 'format' => 'json', 'repo' => (string)$repoId, 'pack' => 'chemistry' ] );
        $api3 = new \ApiMain( $req3 );
        $api3->execute();
        $data3 = $api3->getResult()->getResultData( null );
        $out3 = $data3['labkiquery'] ?? $data3;
        $this->assertArrayHasKey( 'pages', $out3 );
    }
}


