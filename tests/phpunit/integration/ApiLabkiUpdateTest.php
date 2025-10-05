<?php

declare(strict_types=1);

/**
 * @group API
 * @group Database
 * @covers \LabkiPackManager\API\ApiLabkiUpdate
 */
final class ApiLabkiUpdateTest extends \MediaWikiIntegrationTestCase {
    protected static $tablesUsed = [ 'page', 'labki_content_repo', 'labki_pack', 'labki_page', 'user' ];

    public function testInstallPackAndRecordPage(): void {
        // Create a sysop user with the right
        $user = $this->getTestSysop()->getUser();
        $this->setUser( $user );

        $req1 = new \MediaWiki\Request\FauxRequest( [
            'action' => 'labkiupdate',
            'format' => 'json',
            'actionType' => 'installPack',
            'repoUrl' => 'https://example.com/repoE/manifest.yml',
            'packName' => 'biology',
            'version' => '2.0.0',
        ] );
        $api1 = new \ApiMain( $req1 );
        $api1->execute();
        $data1 = $api1->getResult()->getResultData( null );
        $out1 = $data1['labkiupdate'] ?? $data1;
        $this->assertTrue( (bool)( $out1['success'] ?? false ) );
        $packId = (int)( $out1['packId'] ?? 0 );
        $this->assertGreaterThan( 0, $packId );
        $this->assertIsArray( $out1['pack'] ?? null );

        // Verify pack exists in DB via read API
        $reqRead = new \MediaWiki\Request\FauxRequest( [ 'action' => 'labkiquery', 'format' => 'json', 'repo' => 'https://example.com/repoE/manifest.yml' ] );
        $apiRead = new \ApiMain( $reqRead );
        $apiRead->execute();
        $dataRead = $apiRead->getResult()->getResultData( null );
        $outRead = $dataRead['labkiquery'] ?? $dataRead;
        $this->assertArrayHasKey( 'packs', $outRead );

        // Record page install
        $req2 = new \MediaWiki\Request\FauxRequest( [
            'action' => 'labkiupdate',
            'format' => 'json',
            'actionType' => 'recordPageInstall',
            'packId' => $packId,
            'name' => 'Bio:Intro',
            'finalTitle' => 'Bio:Intro',
            'pageNamespace' => 0,
            'wikiPageId' => 123,
        ] );
        $api2 = new \ApiMain( $req2 );
        $api2->execute();
        $data2 = $api2->getResult()->getResultData( null );
        $out2 = $data2['labkiupdate'] ?? $data2;
        $this->assertTrue( (bool)( $out2['success'] ?? false ) );

        // Verify page row exists after install
        $reqPages = new \MediaWiki\Request\FauxRequest( [ 'action' => 'labkiquery', 'format' => 'json', 'repo' => 'https://example.com/repoE/manifest.yml', 'pack' => 'biology' ] );
        $apiPages = new \ApiMain( $reqPages );
        $apiPages->execute();
        $dataPages = $apiPages->getResult()->getResultData( null );
        $outPages = $dataPages['labkiquery'] ?? $dataPages;
        $this->assertArrayHasKey( 'pages', $outPages );
        $this->assertNotEmpty( $outPages['pages'] );

        // Remove pack should cascade pages
        $req3 = new \MediaWiki\Request\FauxRequest( [
            'action' => 'labkiupdate',
            'format' => 'json',
            'actionType' => 'removePack',
            'packId' => $packId,
        ] );
        $api3 = new \ApiMain( $req3 );
        $api3->execute();
        $data3 = $api3->getResult()->getResultData( null );
        $out3 = $data3['labkiupdate'] ?? $data3;
        $this->assertTrue( (bool)( $out3['success'] ?? false ) );

        // After removal, pages should be gone due to ON DELETE CASCADE
        $apiPages2 = new \ApiMain( $reqPages );
        $apiPages2->execute();
        $dataPages2 = $apiPages2->getResult()->getResultData( null );
        $outPages2 = $dataPages2['labkiquery'] ?? $dataPages2;
        $this->assertArrayHasKey( 'pages', $outPages2 );
        $this->assertSame( [], $outPages2['pages'] );
    }
}


