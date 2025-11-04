<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Packs;

use ApiTestCase;
use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRef;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;

/**
 * Integration tests for ApiLabkiPacksList.
 *
 * These tests cover:
 * - Listing all packs (with and without data)
 * - Getting packs for a specific repository
 * - Getting packs for a specific ref
 * - Getting pages for a specific pack
 * - Parameter validation (mutually exclusive parameters)
 * - Error handling for invalid identifiers
 * - Response structure and metadata
 *
 * @covers \LabkiPackManager\API\Packs\ApiLabkiPacksList
 * @covers \LabkiPackManager\API\Packs\PackApiBase
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
class ApiLabkiPacksListTest extends ApiTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private LabkiPackRegistry $packRegistry;
	private LabkiPageRegistry $pageRegistry;

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [
		'labki_content_repo',
		'labki_content_ref',
		'labki_pack',
		'labki_page',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
		$this->pageRegistry = new LabkiPageRegistry();
	}

	/**
	 * Helper to create a test repository.
	 */
	private function createTestRepo( string $url = 'https://github.com/test/repo', string $defaultRef = 'main' ): ContentRepoId {
		return $this->repoRegistry->ensureRepoEntry( $url, [
			'default_ref' => $defaultRef,
		] );
	}

	/**
	 * Helper to create a test ref.
	 */
	private function createTestRef( ContentRepoId $repoId, string $ref = 'main' ): ContentRefId {
		return $this->refRegistry->ensureRefEntry(
			$repoId,
			$ref,
			[
				'worktree_path' => '/tmp/test/worktree',
				'last_commit' => 'abc123',
				'manifest_hash' => 'test-hash',
			]
		);
	}

	/**
	 * Helper to create a test pack.
	 */
	private function createTestPack( ContentRefId $refId, string $name = 'TestPack', string $version = '1.0.0' ): PackId {
		return $this->packRegistry->addPack( $refId, $name, [
			'version' => $version,
			'source_commit' => 'abc123',
			'status' => 'installed',
		] );
	}

	/**
	 * Helper to create a test page.
	 */
	private function createTestPage( PackId $packId, string $name, string $finalTitle ): void {
		$this->pageRegistry->addPage( $packId, [
			'name' => $name,
			'final_title' => $finalTitle,
			'page_namespace' => 0,
			'wiki_page_id' => null,
		] );
	}

	/**
	 * Test listing all packs when none exist.
	 */
	public function testListPacks_WhenNoPacks_ReturnsEmptyArray(): void {
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
		] );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertIsArray( $data['packs'] );
		$this->assertCount( 0, $data['packs'] );
		
		// Check metadata
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'schemaVersion', $data['meta'] );
		$this->assertArrayHasKey( 'timestamp', $data['meta'] );
		$this->assertEquals( 1, $data['meta']['schemaVersion'] );
	}

	/**
	 * Test listing all packs when some exist.
	 */
	public function testListPacks_WithMultiplePacks_ReturnsAllPacks(): void {
		// Create test data
		$repoId = $this->createTestRepo();
		$refId = $this->createTestRef( $repoId, 'main' );
		$packId1 = $this->createTestPack( $refId, 'Pack1', '1.0.0' );
		$packId2 = $this->createTestPack( $refId, 'Pack2', '2.0.0' );
		
		// Add some pages
		$this->createTestPage( $packId1, 'Page1', 'Pack1/Page1' );
		$this->createTestPage( $packId1, 'Page2', 'Pack1/Page2' );
		$this->createTestPage( $packId2, 'Page1', 'Pack2/Page1' );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
		] );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertIsArray( $data['packs'] );
		$this->assertCount( 2, $data['packs'] );
		
		// Check first pack
		$pack1 = $data['packs'][0];
		$this->assertArrayHasKey( 'pack_id', $pack1 );
		$this->assertArrayHasKey( 'name', $pack1 );
		$this->assertArrayHasKey( 'version', $pack1 );
		$this->assertArrayHasKey( 'page_count', $pack1 );
		$this->assertArrayHasKey( 'repo_id', $pack1 );
		$this->assertArrayHasKey( 'repo_url', $pack1 );
		$this->assertArrayHasKey( 'ref', $pack1 );
		
		$this->assertEquals( 'Pack1', $pack1['name'] );
		$this->assertEquals( '1.0.0', $pack1['version'] );
		$this->assertEquals( 2, $pack1['page_count'] );
		
		// Check second pack
		$pack2 = $data['packs'][1];
		$this->assertEquals( 'Pack2', $pack2['name'] );
		$this->assertEquals( '2.0.0', $pack2['version'] );
		$this->assertEquals( 1, $pack2['page_count'] );
	}

	/**
	 * Test getting packs for a specific repository.
	 */
	public function testListPacks_ByRepoId_ReturnsOnlyPacksForThatRepo(): void {
		// Create two repos with packs
		$repoId1 = $this->createTestRepo( 'https://github.com/test/repo1' );
		$repoId2 = $this->createTestRepo( 'https://github.com/test/repo2' );
		
		$refId1 = $this->createTestRef( $repoId1, 'main' );
		$refId2 = $this->createTestRef( $repoId2, 'main' );
		
		$this->createTestPack( $refId1, 'Repo1Pack' );
		$this->createTestPack( $refId2, 'Repo2Pack' );

		// Query for repo 1 only
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
			'repo_id' => $repoId1->toInt(),
		] );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertCount( 1, $data['packs'] );
		$this->assertEquals( 'Repo1Pack', $data['packs'][0]['name'] );
	}

	/**
	 * Test getting packs for a specific ref.
	 */
	public function testListPacks_ByRef_ReturnsOnlyPacksForThatRef(): void {
		// Create repo with two refs
		$repoId = $this->createTestRepo();
		$refId1 = $this->createTestRef( $repoId, 'main' );
		$refId2 = $this->createTestRef( $repoId, 'develop' );
		
		$this->createTestPack( $refId1, 'MainPack' );
		$this->createTestPack( $refId2, 'DevelopPack' );

		// Query for main ref only
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
		] );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertCount( 1, $data['packs'] );
		$this->assertEquals( 'MainPack', $data['packs'][0]['name'] );
	}

	/**
	 * Test getting a specific pack (without pages).
	 */
	public function testListPacks_ByPackId_ReturnsSinglePackWithoutPages(): void {
		// Create pack with pages
		$repoId = $this->createTestRepo();
		$refId = $this->createTestRef( $repoId, 'main' );
		$packId = $this->createTestPack( $refId, 'TestPack' );
		
		$this->createTestPage( $packId, 'Page1', 'TestPack/Page1' );
		$this->createTestPage( $packId, 'Page2', 'TestPack/Page2' );

		// Query for pack (without pages)
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
			'pack_id' => $packId->toInt(),
		] );

		$data = $result[0];
		
		// Should have consistent packs array structure
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertIsArray( $data['packs'] );
		$this->assertCount( 1, $data['packs'] );
		
		// Check pack data
		$pack = $data['packs'][0];
		$this->assertEquals( 'TestPack', $pack['name'] );
		$this->assertEquals( 2, $pack['page_count'] );
		
		// Pages should NOT be included by default
		$this->assertArrayNotHasKey( 'pages', $pack );
	}

	/**
	 * Test getting a specific pack with pages.
	 */
	public function testListPacks_ByPackIdWithPages_ReturnsPackWithNestedPages(): void {
		// Create pack with pages
		$repoId = $this->createTestRepo();
		$refId = $this->createTestRef( $repoId, 'main' );
		$packId = $this->createTestPack( $refId, 'TestPack' );
		
		$this->createTestPage( $packId, 'Page1', 'TestPack/Page1' );
		$this->createTestPage( $packId, 'Page2', 'TestPack/Page2' );

		// Query for pack with pages
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
			'pack_id' => $packId->toInt(),
			'include_pages' => true,
		] );

		$data = $result[0];
		
		// Should have consistent packs array structure
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertIsArray( $data['packs'] );
		$this->assertCount( 1, $data['packs'] );
		
		// Check pack data
		$pack = $data['packs'][0];
		$this->assertEquals( 'TestPack', $pack['name'] );
		$this->assertEquals( 2, $pack['page_count'] );
		
		// Pages should be nested within pack
		$this->assertArrayHasKey( 'pages', $pack );
		$this->assertIsArray( $pack['pages'] );
		$this->assertCount( 2, $pack['pages'] );
		
		$page1 = $pack['pages'][0];
		$this->assertArrayHasKey( 'page_id', $page1 );
		$this->assertArrayHasKey( 'name', $page1 );
		$this->assertArrayHasKey( 'final_title', $page1 );
		$this->assertEquals( 'Page1', $page1['name'] );
		$this->assertEquals( 'TestPack/Page1', $page1['final_title'] );
	}

	/**
	 * Test getting a specific pack by name with pages.
	 */
	public function testListPacks_ByPackNameWithPages_ReturnsPackWithNestedPages(): void {
		// Create pack with pages
		$repoId = $this->createTestRepo();
		$refId = $this->createTestRef( $repoId, 'main' );
		$packId = $this->createTestPack( $refId, 'TestPack' );
		
		$this->createTestPage( $packId, 'Page1', 'TestPack/Page1' );

		// Query for pack by name with pages
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack' => 'TestPack',
			'include_pages' => true,
		] );

		$data = $result[0];
		
		// Should have consistent structure
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertCount( 1, $data['packs'] );
		
		$pack = $data['packs'][0];
		$this->assertEquals( 'TestPack', $pack['name'] );
		$this->assertArrayHasKey( 'pages', $pack );
		$this->assertCount( 1, $pack['pages'] );
	}

	/**
	 * Test error when both repo_id and repo_url are provided.
	 */
	public function testListPacks_WithBothRepoIdAndRepoUrl_ReturnsError(): void {
		$this->expectApiErrorCode( 'multiple_identifiers' );
		
		$this->doApiRequest( [
			'action' => 'labkiPacksList',
			'repo_id' => 1,
			'repo_url' => 'https://github.com/test/repo',
		] );
	}

	/**
	 * Test error when both ref_id and ref are provided.
	 */
	public function testListPacks_WithBothRefIdAndRef_ReturnsError(): void {
		$this->expectApiErrorCode( 'multiple_identifiers' );
		
		$this->doApiRequest( [
			'action' => 'labkiPacksList',
			'repo_id' => 1,
			'ref_id' => 1,
			'ref' => 'main',
		] );
	}

	/**
	 * Test error when both pack_id and pack are provided.
	 */
	public function testListPacks_WithBothPackIdAndPack_ReturnsError(): void {
		$this->expectApiErrorCode( 'multiple_identifiers' );
		
		$this->doApiRequest( [
			'action' => 'labkiPacksList',
			'repo_id' => 1,
			'ref' => 'main',
			'pack_id' => 1,
			'pack' => 'TestPack',
		] );
	}

	/**
	 * Test error when ref is specified without repo.
	 */
	public function testListPacks_WithRefButNoRepo_ReturnsError(): void {
		$this->expectApiErrorCode( 'missing_repo' );
		
		$this->doApiRequest( [
			'action' => 'labkiPacksList',
			'ref' => 'main',
		] );
	}

	/**
	 * Test error when pack name is specified without ref.
	 */
	public function testListPacks_WithPackNameButNoRef_ReturnsError(): void {
		$this->expectApiErrorCode( 'missing_ref' );
		
		$this->doApiRequest( [
			'action' => 'labkiPacksList',
			'repo_id' => 1,
			'pack' => 'TestPack',
		] );
	}

	/**
	 * Test error when requesting non-existent pack.
	 */
	public function testListPacks_WithNonExistentPackId_ReturnsError(): void {
		$this->expectApiErrorCode( 'pack_not_found' );
		
		$this->doApiRequest( [
			'action' => 'labkiPacksList',
			'pack_id' => 99999,
		] );
	}

	/**
	 * Test getting packs by repo URL.
	 */
	public function testListPacks_ByRepoUrl_ReturnsPacksForThatRepo(): void {
		$repoUrl = 'https://github.com/test/repo';
		$repoId = $this->createTestRepo( $repoUrl );
		$refId = $this->createTestRef( $repoId, 'main' );
		$this->createTestPack( $refId, 'TestPack' );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
			'repo_url' => $repoUrl,
		] );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertCount( 1, $data['packs'] );
		$this->assertEquals( 'TestPack', $data['packs'][0]['name'] );
		$this->assertEquals( $repoUrl, $data['packs'][0]['repo_url'] );
	}

	/**
	 * Test include_pages parameter with multiple packs.
	 */
	public function testListPacks_WithIncludePages_IncludesNestedPageData(): void {
		// Create multiple packs with pages
		$repoId = $this->createTestRepo();
		$refId = $this->createTestRef( $repoId, 'main' );
		$packId1 = $this->createTestPack( $refId, 'Pack1' );
		$packId2 = $this->createTestPack( $refId, 'Pack2' );
		
		$this->createTestPage( $packId1, 'Page1', 'Pack1/Page1' );
		$this->createTestPage( $packId1, 'Page2', 'Pack1/Page2' );
		$this->createTestPage( $packId2, 'PageA', 'Pack2/PageA' );

		// Query with include_pages=true
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
			'include_pages' => true,
		] );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertCount( 2, $data['packs'] );
		
		// Both packs should have pages nested
		foreach ( $data['packs'] as $pack ) {
			$this->assertArrayHasKey( 'pages', $pack );
			$this->assertIsArray( $pack['pages'] );
			$this->assertGreaterThan( 0, count( $pack['pages'] ) );
		}
	}

	/**
	 * Test default behavior (without include_pages) excludes pages.
	 */
	public function testListPacks_WithoutIncludePages_ExcludesPageData(): void {
		// Create pack with pages
		$repoId = $this->createTestRepo();
		$refId = $this->createTestRef( $repoId, 'main' );
		$packId = $this->createTestPack( $refId, 'TestPack' );
		
		$this->createTestPage( $packId, 'Page1', 'TestPack/Page1' );

		// Query without include_pages parameter
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksList',
		] );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertCount( 1, $data['packs'] );
		
		// Pack should NOT have pages
		$pack = $data['packs'][0];
		$this->assertArrayNotHasKey( 'pages', $pack );
		
		// But should still have page_count
		$this->assertArrayHasKey( 'page_count', $pack );
		$this->assertEquals( 1, $pack['page_count'] );
	}
}

