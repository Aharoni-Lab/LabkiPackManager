<?php

declare(strict_types=1);

/**
 * @group Database
 */
final class PreflightPlannerTest extends \MediaWikiIntegrationTestCase {
    protected static $tablesUsed = [ 'page', 'revision', 'page_props' ];
    /**
     * @group Database
     * @covers \LabkiPackManager\Services\PreflightPlanner::plan
     */
    public function testCategorizesCreatesUpdatesAndExternal(): void {
        $titleFactory = $this->getServiceContainer()->getTitleFactory();
        $wikiPageFactory = $this->getServiceContainer()->getWikiPageFactory();

        // Seed one existing external page (no Labki props)
        $tExt = $titleFactory->makeTitle( NS_MAIN, 'PF External' );
        $wikiPageFactory->newFromTitle( $tExt )
            ->newPageUpdater( $this->getTestUser()->getUser() )
            ->setContent( \MediaWiki\Revision\SlotRecord::MAIN, \ContentHandler::makeContent( 'ext', $tExt ) )
            ->saveRevision( \MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment( 'seed' ) );

        // Seed one existing Labki-owned page with same hash (unchanged)
        $tUnch = $titleFactory->makeTitle( NS_MAIN, 'PF Unchanged' );
        $wikiPageFactory->newFromTitle( $tUnch )
            ->newPageUpdater( $this->getTestUser()->getUser() )
            ->setContent( \MediaWiki\Revision\SlotRecord::MAIN, \ContentHandler::makeContent( 'same', $tUnch ) )
            ->saveRevision( \MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment( 'seed' ) );
        $pageIdUnch = (int)$tUnch->getArticleID();
        $hashUnch = hash('sha256', preg_replace("/\r\n?|\x{2028}|\x{2029}/u","\n",'same'));
        $dbw = $this->getDb();
        $dbw->newInsertQueryBuilder()->insertInto('page_props')->row(['pp_page'=>$pageIdUnch,'pp_propname'=>'labki.pack_id','pp_value'=>'pub'])->caller(__METHOD__)->execute();
        $dbw->newInsertQueryBuilder()->insertInto('page_props')->row(['pp_page'=>$pageIdUnch,'pp_propname'=>'labki.content_hash','pp_value'=>$hashUnch])->caller(__METHOD__)->execute();

        // Seed one existing Labki-owned page with modified content
        $tMod = $titleFactory->makeTitle( NS_MAIN, 'PF Modified' );
        $wikiPageFactory->newFromTitle( $tMod )
            ->newPageUpdater( $this->getTestUser()->getUser() )
            ->setContent( \MediaWiki\Revision\SlotRecord::MAIN, \ContentHandler::makeContent( 'newer', $tMod ) )
            ->saveRevision( \MediaWiki\CommentStore\CommentStoreComment::newUnsavedComment( 'seed' ) );
        $pageIdMod = (int)$tMod->getArticleID();
        $oldHash = hash('sha256', preg_replace("/\r\n?|\x{2028}|\x{2029}/u","\n",'older'));
        $dbw->newInsertQueryBuilder()->insertInto('page_props')->row(['pp_page'=>$pageIdMod,'pp_propname'=>'labki.pack_id','pp_value'=>'pub'])->caller(__METHOD__)->execute();
        $dbw->newInsertQueryBuilder()->insertInto('page_props')->row(['pp_page'=>$pageIdMod,'pp_propname'=>'labki.content_hash','pp_value'=>$oldHash])->caller(__METHOD__)->execute();

        // Plan for three pages plus one create
        $resolved = [
            'packs' => ['pub'],
            'pages' => [ $tExt->getPrefixedText(), $tUnch->getPrefixedText(), $tMod->getPrefixedText(), 'PF Create' ],
            'pageOwners' => [ $tExt->getPrefixedText() => ['pub'], $tUnch->getPrefixedText() => ['pub'], $tMod->getPrefixedText() => ['pub'] ],
        ];
        $planner = new \LabkiPackManager\Services\PreflightPlanner();
        $pf = $planner->plan( $resolved );
        $this->assertSame(1, $pf['create']);
        $this->assertSame(1, $pf['external_collisions']);
        $this->assertSame(1, $pf['update_unchanged']);
        $this->assertSame(1, $pf['update_modified']);
        $this->assertIsArray($pf['lists']['create']);

        // Cross-repo conflict: mark existing page as from a different repo
        $dbr = $this->getDb();
        $pageIdExt = (int)$titleFactory->newFromText( 'PF External' )->getArticleID();
        // Upsert props so it looks owned by a different repo/pack
        $dbr->newDeleteQueryBuilder()->deleteFrom('page_props')->where(['pp_page'=>$pageIdExt,'pp_propname'=>'labki.source_repo'])->caller(__METHOD__)->execute();
        $dbr->newInsertQueryBuilder()->insertInto('page_props')->row(['pp_page'=>$pageIdExt,'pp_propname'=>'labki.source_repo','pp_value'=>'https://example.com/repoB/manifest.yml'])->caller(__METHOD__)->execute();
        $dbr->newDeleteQueryBuilder()->deleteFrom('page_props')->where(['pp_page'=>$pageIdExt,'pp_propname'=>'labki.pack_id'])->caller(__METHOD__)->execute();
        $dbr->newInsertQueryBuilder()->insertInto('page_props')->row(['pp_page'=>$pageIdExt,'pp_propname'=>'labki.pack_id','pp_value'=>'pub'])->caller(__METHOD__)->execute();
        $pf2 = $planner->plan( [ 'packs'=>['pub'], 'pages'=>['PF External'], 'repoUrl' => 'https://example.com/repoA/manifest.yml' ] );
        $this->assertSame(1, $pf2['pack_pack_conflicts']);
    }
}


