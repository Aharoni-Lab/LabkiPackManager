<?php

declare(strict_types=1);

// Base test class is provided by MediaWiki's test harness
use LabkiPackManager\Import\PackImporter;

/**
 * @group Database
 * @covers \LabkiPackManager\Import\PackImporter::importPack
 */
final class PackImporterTest extends \MediaWikiIntegrationTestCase {
    protected static $tablesUsed = [ 'page', 'revision', 'page_props', 'labki_pack_registry', 'labki_pack_pages' ];
    public function testImportPackWritesPropsAndRegistry(): void {
        $title = $this->getServiceContainer()->getTitleFactory()->makeTitle( NS_MAIN, 'LPM Test Page' );
        $revRec = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title )
            ->newPageUpdater( $this->getTestUser()->getUser() )
            ->setContent( \MediaWiki\Revision\SlotRecord::MAIN, \ContentHandler::makeContent( 'orig', $title ) )
            ->saveRevision( \MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment( 'seed' ) );

        $importer = new PackImporter();
        $res = $importer->importPack( 'publication', '1.0.0', [ [ 'title' => 'LPM Test Page', 'namespace' => NS_MAIN, 'text' => 'new content', 'page_key' => 'Main:LPM Test Page' ] ], [ 'source_repo' => 'test' ], $this->getTestUser()->getUserIdentity() );
        $this->assertIsArray($res);

        $dbr = $this->getDb();
        $row = $dbr->newSelectQueryBuilder()->select([ 'version' ])->from('labki_pack_registry')->where([ 'pack_id' => 'publication' ])->fetchRow();
        $this->assertNotFalse($row);
        $this->assertSame('1.0.0', (string)$row->version);

        $row2 = $dbr->newSelectQueryBuilder()->select([ 'pack_id','page_title','content_hash' ])->from('labki_pack_pages')->where([ 'pack_id' => 'publication', 'page_title' => 'LPM Test Page' ])->fetchRow();
        $this->assertNotFalse($row2);
        $this->assertSame('publication', (string)$row2->pack_id);
    }
}


