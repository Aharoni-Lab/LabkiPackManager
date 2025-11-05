<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\Page;
use LabkiPackManager\Domain\PageId;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use WikitextContent;

/**
 * Integration tests for LabkiPageRegistry
 *
 * Tests the page-level registry service for the labki_page table.
 * These tests use the actual MediaWiki database.
 *
 * @covers \LabkiPackManager\Services\LabkiPageRegistry
 * @group Database
 */
class LabkiPageRegistryTest extends MediaWikiIntegrationTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private LabkiPackRegistry $packRegistry;

	protected function setUp(): void {
		parent::setUp();
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
	}

	/**
	 * Helper to create a test pack (creates repo and ref)
	 */
	private function createTestPack( string $url = 'https://example.com/test-pages-repo' ): PackId {
		$repoId = $this->repoRegistry->ensureRepoEntry( $url );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main' );
		return $this->packRegistry->addPack( $refId, 'TestPackForPages', [] );
	}

	/**
	 * Helper to create repo and return its ID
	 */
	private function createTestRepo( string $url = 'https://example.com/test-repo-id' ): ContentRepoId {
		return $this->repoRegistry->ensureRepoEntry( $url );
	}

	private function newRegistry(): LabkiPageRegistry {
		return new LabkiPageRegistry();
	}

	public function testNow_ReturnsValidTimestamp(): void {
		$registry = $this->newRegistry();
		
		$timestamp = $registry->now();
		
		$this->assertIsString( $timestamp );
		$this->assertNotEmpty( $timestamp );
		$this->assertMatchesRegularExpression( '/^\d{14}$/', $timestamp );
	}

	public function testAddPage_WithArrayFormat_CreatesPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'HomePage',
			'final_title' => 'Home',
			'page_namespace' => 0,
		] );
		
		$this->assertInstanceOf( PageId::class, $pageId );
		$this->assertGreaterThan( 0, $pageId->toInt() );
	}

	public function testAddPage_WithIndividualParams_CreatesPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, 'TestPage', 'Test Page', 0, null );
		
		$this->assertInstanceOf( PageId::class, $pageId );
		$this->assertGreaterThan( 0, $pageId->toInt() );
	}

	public function testAddPage_SetsDefaultFields(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'DefaultPage',
			'final_title' => 'Default Page',
			'page_namespace' => 0,
		] );
		
		$page = $registry->getPageById( $pageId );
		
		$this->assertNotNull( $page );
		$this->assertSame( 'DefaultPage', $page->name() );
		$this->assertSame( 'Default Page', $page->finalTitle() );
		$this->assertSame( 0, $page->namespace() );
		$this->assertNull( $page->wikiPageId() );
		$this->assertNull( $page->lastRevId() );
		$this->assertNull( $page->contentHash() );
		$this->assertNotNull( $page->createdAt() );
		$this->assertNotNull( $page->updatedAt() );
	}

	public function testAddPage_WithExtraFields_StoresMetadata(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'FullPage',
			'final_title' => 'Full Page',
			'page_namespace' => 4,
			'wiki_page_id' => 123,
			'last_rev_id' => 456,
			'content_hash' => 'abc123def456',
		] );
		
		$page = $registry->getPageById( $pageId );
		
		$this->assertNotNull( $page );
		$this->assertSame( 4, $page->namespace() );
		$this->assertSame( 123, $page->wikiPageId() );
		$this->assertSame( 456, $page->lastRevId() );
		$this->assertSame( 'abc123def456', $page->contentHash() );
	}

	public function testGetPageById_WithIntId_ReturnsPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'GetById',
			'final_title' => 'Get By Id',
			'page_namespace' => 0,
		] );
		
		$page = $registry->getPageById( $pageId->toInt() );
		
		$this->assertNotNull( $page );
		$this->assertInstanceOf( Page::class, $page );
		$this->assertSame( 'GetById', $page->name() );
	}

	public function testGetPageById_WithPageId_ReturnsPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'GetById2',
			'final_title' => 'Get By Id 2',
			'page_namespace' => 0,
		] );
		
		$page = $registry->getPageById( $pageId );
		
		$this->assertNotNull( $page );
		$this->assertTrue( $pageId->equals( $page->id() ) );
	}

	public function testGetPageById_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();

		$result = $registry->getPageById( 999999 );
		
		$this->assertNull( $result );
	}

	public function testGetPageByTitle_FindsPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'TitleTest',
			'final_title' => 'Unique Title For Search',
			'page_namespace' => 0,
		] );
		
		$page = $registry->getPageByTitle( 'Unique Title For Search' );
		
		$this->assertNotNull( $page );
		$this->assertSame( $pageId->toInt(), $page->id()->toInt() );
		$this->assertSame( 'TitleTest', $page->name() );
	}

	public function testGetPageByTitle_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();

		$result = $registry->getPageByTitle( 'NonExistent Title' );
		
		$this->assertNull( $result );
	}

	public function testGetPageByName_WithIntPackId_FindsPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [
			'name' => 'PageByName',
			'final_title' => 'Page By Name',
			'page_namespace' => 0,
		] );
		
		$page = $registry->getPageByName( $packId->toInt(), 'PageByName' );
		
		$this->assertNotNull( $page );
		$this->assertSame( 'PageByName', $page->name() );
	}

	public function testGetPageByName_WithPackId_FindsPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [
			'name' => 'PageByName2',
			'final_title' => 'Page By Name 2',
			'page_namespace' => 0,
		] );
		
		$page = $registry->getPageByName( $packId, 'PageByName2' );
		
		$this->assertNotNull( $page );
		$this->assertSame( 'PageByName2', $page->name() );
	}

	public function testGetPageByName_WhenNotExists_ReturnsNull(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$result = $registry->getPageByName( $packId, 'NonExistent' );
		
		$this->assertNull( $result );
	}

	public function testListPagesByPack_WithIntPackId_ReturnsAllPages(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId->toInt(), [ 'name' => 'Page1', 'final_title' => 'P1', 'page_namespace' => 0 ] );
		$registry->addPage( $packId->toInt(), [ 'name' => 'Page2', 'final_title' => 'P2', 'page_namespace' => 0 ] );
		$registry->addPage( $packId->toInt(), [ 'name' => 'Page3', 'final_title' => 'P3', 'page_namespace' => 0 ] );

		$pages = $registry->listPagesByPack( $packId->toInt() );
		
		$this->assertIsArray( $pages );
		$this->assertCount( 3, $pages );
		
		foreach ( $pages as $page ) {
			$this->assertInstanceOf( Page::class, $page );
		}
		
		$names = array_map( fn( $page ) => $page->name(), $pages );
		$this->assertContains( 'Page1', $names );
		$this->assertContains( 'Page2', $names );
		$this->assertContains( 'Page3', $names );
	}

	public function testListPagesByPack_WithPackId_ReturnsAllPages(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [ 'name' => 'PageA', 'final_title' => 'PA', 'page_namespace' => 0 ] );
		$registry->addPage( $packId, [ 'name' => 'PageB', 'final_title' => 'PB', 'page_namespace' => 0 ] );

		$pages = $registry->listPagesByPack( $packId );
		
		$this->assertCount( 2, $pages );
		
		$names = array_map( fn( $page ) => $page->name(), $pages );
		$this->assertContains( 'PageA', $names );
		$this->assertContains( 'PageB', $names );
	}

	public function testListPagesByPack_OrderedByPageId(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [ 'name' => 'Z', 'final_title' => 'Z', 'page_namespace' => 0 ] );
		$registry->addPage( $packId, [ 'name' => 'A', 'final_title' => 'A', 'page_namespace' => 0 ] );
		$registry->addPage( $packId, [ 'name' => 'M', 'final_title' => 'M', 'page_namespace' => 0 ] );

		$pages = $registry->listPagesByPack( $packId );
		
		// Verify ordering by ID
		$prevId = 0;
		foreach ( $pages as $page ) {
			$this->assertGreaterThan( $prevId, $page->id()->toInt() );
			$prevId = $page->id()->toInt();
		}
	}

	public function testCountPagesByPack_WithIntPackId_ReturnsCount(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId->toInt(), [ 'name' => 'C1', 'final_title' => 'C1', 'page_namespace' => 0 ] );
		$registry->addPage( $packId->toInt(), [ 'name' => 'C2', 'final_title' => 'C2', 'page_namespace' => 0 ] );

		$count = $registry->countPagesByPack( $packId->toInt() );
		
		$this->assertSame( 2, $count );
	}

	public function testCountPagesByPack_WithPackId_ReturnsCount(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [ 'name' => 'C3', 'final_title' => 'C3', 'page_namespace' => 0 ] );
		$registry->addPage( $packId, [ 'name' => 'C4', 'final_title' => 'C4', 'page_namespace' => 0 ] );
		$registry->addPage( $packId, [ 'name' => 'C5', 'final_title' => 'C5', 'page_namespace' => 0 ] );

		$count = $registry->countPagesByPack( $packId );
		
		$this->assertSame( 3, $count );
	}

	public function testCountPagesByPack_WhenNoPpages_ReturnsZero(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$count = $registry->countPagesByPack( $packId );
		
		$this->assertSame( 0, $count );
	}

	public function testUpdatePage_WithIntId_UpdatesFields(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'UpdateTest',
			'final_title' => 'Update Test',
			'page_namespace' => 0,
		] );

		$registry->updatePage( $pageId->toInt(), [
			'content_hash' => 'new_hash',
			'last_rev_id' => 789,
		] );
		
		$page = $registry->getPageById( $pageId );
		$this->assertNotNull( $page );
		$this->assertSame( 'new_hash', $page->contentHash() );
		$this->assertSame( 789, $page->lastRevId() );
	}

	public function testUpdatePage_WithPageId_UpdatesFields(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'UpdateTest2',
			'final_title' => 'Update Test 2',
			'page_namespace' => 0,
		] );

		$registry->updatePage( $pageId, [
			'wiki_page_id' => 999,
		] );
		
		$page = $registry->getPageById( $pageId );
		$this->assertNotNull( $page );
		$this->assertSame( 999, $page->wikiPageId() );
	}

	public function testUpdatePage_AutomaticallyUpdatesTimestamp(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'TimestampUpdate',
			'final_title' => 'Timestamp Update',
			'page_namespace' => 0,
		] );
		
		$before = $registry->getPageById( $pageId );
		$this->assertNotNull( $before );
		$beforeUpdated = $before->updatedAt();

		usleep( 10000 ); // 10ms delay

		$registry->updatePage( $pageId, [ 'content_hash' => 'changed' ] );
		
		$after = $registry->getPageById( $pageId );
		$this->assertNotNull( $after );
		$this->assertNotNull( $after->updatedAt() );
		
		if ( $beforeUpdated !== null ) {
			$this->assertGreaterThanOrEqual( $beforeUpdated, $after->updatedAt() );
		}
	}

	public function testRemovePageById_WithIntId_RemovesPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'RemoveById',
			'final_title' => 'Remove By Id',
			'page_namespace' => 0,
		] );
		
		$this->assertNotNull( $registry->getPageById( $pageId ) );

		$result = $registry->removePageById( $pageId->toInt() );
		
		$this->assertTrue( $result );
		$this->assertNull( $registry->getPageById( $pageId ) );
	}

	public function testRemovePageById_WithPageId_RemovesPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$pageId = $registry->addPage( $packId, [
			'name' => 'RemoveById2',
			'final_title' => 'Remove By Id 2',
			'page_namespace' => 0,
		] );
		
		$this->assertNotNull( $registry->getPageById( $pageId ) );

		$result = $registry->removePageById( $pageId );
		
		$this->assertTrue( $result );
		$this->assertNull( $registry->getPageById( $pageId ) );
	}

	public function testRemovePageByName_WithIntPackId_RemovesPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [
			'name' => 'RemoveByName',
			'final_title' => 'Remove By Name',
			'page_namespace' => 0,
		] );
		
		$this->assertNotNull( $registry->getPageByName( $packId, 'RemoveByName' ) );

		$result = $registry->removePageByName( $packId->toInt(), 'RemoveByName' );
		
		$this->assertTrue( $result );
		$this->assertNull( $registry->getPageByName( $packId, 'RemoveByName' ) );
	}

	public function testRemovePageByName_WithPackId_RemovesPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [
			'name' => 'RemoveByName2',
			'final_title' => 'Remove By Name 2',
			'page_namespace' => 0,
		] );
		
		$this->assertNotNull( $registry->getPageByName( $packId, 'RemoveByName2' ) );

		$result = $registry->removePageByName( $packId, 'RemoveByName2' );
		
		$this->assertTrue( $result );
		$this->assertNull( $registry->getPageByName( $packId, 'RemoveByName2' ) );
	}

	public function testRemovePageByFinalTitle_RemovesPage(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [
			'name' => 'RemoveByTitle',
			'final_title' => 'Remove By Title Test',
			'page_namespace' => 0,
		] );
		
		$this->assertNotNull( $registry->getPageByTitle( 'Remove By Title Test' ) );

		$result = $registry->removePageByFinalTitle( 'Remove By Title Test' );
		
		$this->assertTrue( $result );
		$this->assertNull( $registry->getPageByTitle( 'Remove By Title Test' ) );
	}

	public function testRemovePagesByPack_WithIntPackId_RemovesAllPages(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId->toInt(), [ 'name' => 'Bulk1', 'final_title' => 'B1', 'page_namespace' => 0 ] );
		$registry->addPage( $packId->toInt(), [ 'name' => 'Bulk2', 'final_title' => 'B2', 'page_namespace' => 0 ] );
		$registry->addPage( $packId->toInt(), [ 'name' => 'Bulk3', 'final_title' => 'B3', 'page_namespace' => 0 ] );

		$this->assertSame( 3, $registry->countPagesByPack( $packId ) );

		$registry->removePagesByPack( $packId->toInt() );
		
		$this->assertSame( 0, $registry->countPagesByPack( $packId ) );
	}

	public function testRemovePagesByPack_WithPackId_RemovesAllPages(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$registry->addPage( $packId, [ 'name' => 'Bulk4', 'final_title' => 'B4', 'page_namespace' => 0 ] );
		$registry->addPage( $packId, [ 'name' => 'Bulk5', 'final_title' => 'B5', 'page_namespace' => 0 ] );

		$this->assertSame( 2, $registry->countPagesByPack( $packId ) );

		$registry->removePagesByPack( $packId );
		
		$this->assertSame( 0, $registry->countPagesByPack( $packId ) );
	}

	public function testGetPageCollisions_WithEmptyArray_ReturnsEmpty(): void {
		$registry = $this->newRegistry();

		$result = $registry->getPageCollisions( [] );
		
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function testGetPageCollisions_DetectsExistingPages(): void {
		// Create a real wiki page using MediaWiki services
		$services = MediaWikiServices::getInstance();
		$titleFactory = $services->getTitleFactory();
		$wikiPageFactory = $services->getWikiPageFactory();
		
		$title = $titleFactory->newFromText( 'Collision Test Page' );
		$wikiPage = $wikiPageFactory->newFromTitle( $title );
		$user = $this->getTestUser()->getUser();
		$content = new WikitextContent( 'Test content' );
		
		$status = $wikiPage->doUserEditContent( $content, $user, 'Test edit' );
		$this->assertTrue( $status->isOK() );

		$registry = $this->newRegistry();
		$collisions = $registry->getPageCollisions( [ 'Collision Test Page', 'NonExistent Page' ] );
		
		$this->assertIsArray( $collisions );
		$this->assertArrayHasKey( 'Collision Test Page', $collisions );
		$this->assertIsInt( $collisions['Collision Test Page'] );
		$this->assertArrayNotHasKey( 'NonExistent Page', $collisions );
	}

	public function testGetRewriteMapForRepo_ReturnsMap(): void {
		$repoId = $this->createTestRepo( 'https://example.com/rewrite-test' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main' );
		$packId = $this->packRegistry->addPack( $refId, 'RewritePack', [] );
		
		$registry = $this->newRegistry();
		
		$registry->addPage( $packId, [
			'name' => 'Original Name',
			'final_title' => 'Final Title',
			'page_namespace' => 0,
		] );
		$registry->addPage( $packId, [
			'name' => 'Another Page',
			'final_title' => 'Renamed Page',
			'page_namespace' => 0,
		] );

		$map = $registry->getRewriteMapForRepo( $repoId->toInt() );
		
		$this->assertIsArray( $map );
		$this->assertArrayHasKey( 'Original_Name', $map );
		$this->assertArrayHasKey( 'Another_Page', $map );
		$this->assertSame( 'Final Title', $map['Original_Name'] );
		$this->assertSame( 'Renamed Page', $map['Another_Page'] );
	}

	public function testGetRewriteMapForRepo_WhenNoPages_ReturnsEmpty(): void {
		$repoId = $this->createTestRepo( 'https://example.com/empty-rewrite' );
		$registry = $this->newRegistry();

		$map = $registry->getRewriteMapForRepo( $repoId->toInt() );
		
		$this->assertIsArray( $map );
		$this->assertEmpty( $map );
	}

	/**
	 * Test that pages in different packs are independent
	 */
	public function testPagesInDifferentPacks_AreIndependent(): void {
		$pack1 = $this->createTestPack( 'https://example.com/pack1' );
		$pack2 = $this->createTestPack( 'https://example.com/pack2' );
		$registry = $this->newRegistry();

		$page1 = $registry->addPage( $pack1, [
			'name' => 'SameName',
			'final_title' => 'Title1',
			'page_namespace' => 0,
		] );
		$page2 = $registry->addPage( $pack2, [
			'name' => 'SameName',
			'final_title' => 'Title2',
			'page_namespace' => 0,
		] );
		
		$this->assertNotSame( $page1->toInt(), $page2->toInt() );
		
		$p1 = $registry->getPageById( $page1 );
		$p2 = $registry->getPageById( $page2 );
		
		$this->assertSame( 'Title1', $p1->finalTitle() );
		$this->assertSame( 'Title2', $p2->finalTitle() );
		$this->assertSame( $pack1->toInt(), $p1->packId()->toInt() );
		$this->assertSame( $pack2->toInt(), $p2->packId()->toInt() );
	}

	/**
	 * Test timestamps are set correctly on creation
	 */
	public function testTimestamps_SetOnCreation(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$beforeTime = wfTimestampNow();
		$pageId = $registry->addPage( $packId, [
			'name' => 'TimestampPage',
			'final_title' => 'Timestamp Page',
			'page_namespace' => 0,
		] );
		$afterTime = wfTimestampNow();
		
		$page = $registry->getPageById( $pageId );
		$this->assertNotNull( $page );
		$this->assertNotNull( $page->createdAt() );
		$this->assertNotNull( $page->updatedAt() );
		
		// Timestamps should be between before and after
		$this->assertGreaterThanOrEqual( (int)$beforeTime, $page->createdAt() );
		$this->assertLessThanOrEqual( (int)$afterTime, $page->createdAt() );
	}

	/**
	 * Test different namespaces
	 */
	public function testDifferentNamespaces_AreStored(): void {
		$packId = $this->createTestPack();
		$registry = $this->newRegistry();

		$page0 = $registry->addPage( $packId, [ 'name' => 'Main', 'final_title' => 'Main', 'page_namespace' => 0 ] );
		$page4 = $registry->addPage( $packId, [ 'name' => 'Project', 'final_title' => 'Project:Page', 'page_namespace' => 4 ] );
		$page10 = $registry->addPage( $packId, [ 'name' => 'Template', 'final_title' => 'Template:Page', 'page_namespace' => 10 ] );

		$p0 = $registry->getPageById( $page0 );
		$p4 = $registry->getPageById( $page4 );
		$p10 = $registry->getPageById( $page10 );
		
		$this->assertSame( 0, $p0->namespace() );
		$this->assertSame( 4, $p4->namespace() );
		$this->assertSame( 10, $p10->namespace() );
	}
}
