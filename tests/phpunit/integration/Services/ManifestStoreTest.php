<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Services\ManifestStore;
use LabkiPackManager\Services\ManifestFetcher;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Domain\ContentRepoId;
use MediaWiki\Status\Status;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WANObjectCache;
use HashBagOStuff;

/**
 * Integration tests for ManifestStore
 *
 * Tests the manifest caching and retrieval with real MediaWiki services.
 * These tests verify that cache invalidation works correctly when repository
 * last_fetched timestamp changes.
 *
 * @covers \LabkiPackManager\Services\ManifestStore
 * @group Database
 * @group LabkiPackManager
 */
class ManifestStoreTest extends MediaWikiIntegrationTestCase {

	private LabkiRepoRegistry $repoRegistry;

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [
		'labki_content_repo',
	];

	protected function setUp(): void {
		parent::setUp();
		$this->repoRegistry = new LabkiRepoRegistry();
	}

	/**
	 * Create a test cache for isolated testing.
	 */
	private function createTestCache(): WANObjectCache {
		return new WANObjectCache( [
			'cache' => new HashBagOStuff(),
		] );
	}

	/**
	 * Create a mock ManifestFetcher that returns predefined YAML.
	 */
	private function createFetcherMock( string $yaml, bool $success = true ): MockObject {
		$mock = $this->createMock( ManifestFetcher::class );
		
		if ( $success ) {
			$status = Status::newGood( $yaml );
		} else {
			$status = Status::newFatal( 'labkipackmanager-error-manifest-missing' );
		}
		
		$mock->method( 'fetch' )
			->willReturn( $status );
		
		return $mock;
	}

	/**
	 * Test that cache is invalidated when last_fetched timestamp changes.
	 *
	 * This test verifies the fix for the bug where manifest cache was never
	 * invalidated after a repository refresh, because the cache key didn't
	 * include the last_fetched timestamp.
	 */
	public function testCacheInvalidatedWhenLastFetchedChanges(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
name: Test Manifest
packs:
  test-pack:
    version: '1.0.0'
    description: Test pack
YAML;

		$repoUrl = 'https://github.com/example/test-repo';
		$ref = 'main';
		
		// Create repository with initial last_fetched timestamp
		$repoId = $this->repoRegistry->ensureRepoEntry( $repoUrl, [
			'last_fetched' => 1000,
		] );
		
		// Verify repository was created
		$repo = $this->repoRegistry->getRepo( $repoId );
		$this->assertNotNull( $repo );
		$this->assertEquals( 1000, $repo->lastFetched() );
		
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore( $repoUrl, $ref, null, $fetcher, $this->repoRegistry );
		
		// First call - should fetch and cache (lastFetched = 1000)
		$status1 = $store->get();
		$this->assertTrue( $status1->isOK(), 'First get() should succeed' );
		$data1 = $status1->getValue();
		$this->assertFalse( $data1['from_cache'], 'First call should not be from cache' );
		$hash1 = $data1['meta']['hash'];
		
		// Second call - should return cached data (same lastFetched = 1000)
		$status2 = $store->get();
		$this->assertTrue( $status2->isOK(), 'Second get() should succeed' );
		$data2 = $status2->getValue();
		$this->assertTrue( $data2['from_cache'], 'Second call should be from cache' );
		$this->assertEquals( $hash1, $data2['meta']['hash'] );
		
		// Update last_fetched timestamp (simulating a repository refresh)
		$this->repoRegistry->updateRepoEntry( $repoId, [
			'last_fetched' => 2000,
		] );
		
		// Verify timestamp was updated
		$updatedRepo = $this->repoRegistry->getRepo( $repoId );
		$this->assertEquals( 2000, $updatedRepo->lastFetched() );
		
		// Third call - lastFetched changed to 2000, should fetch fresh data
		// (different cache key due to different lastFetched)
		$status3 = $store->get();
		$this->assertTrue( $status3->isOK(), 'Third get() should succeed' );
		$data3 = $status3->getValue();
		$this->assertFalse( 
			$data3['from_cache'], 
			'Cache should be invalidated when last_fetched timestamp changes'
		);
		$this->assertEquals( $hash1, $data3['meta']['hash'] );
	}

	/**
	 * Test that different last_fetched timestamps use different cache keys.
	 *
	 * Two ManifestStore instances for the same repo/ref but with different
	 * last_fetched timestamps should not share cache entries.
	 */
	public function testCacheKeyIncludesLastFetchedTimestamp(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$repoUrl = 'https://github.com/example/test-repo-2';
		$ref = 'main';
		
		// Create first repository with last_fetched = 1000
		$repoId1 = $this->repoRegistry->ensureRepoEntry( $repoUrl, [
			'last_fetched' => 1000,
		] );
		
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store1 = new ManifestStore( $repoUrl, $ref, null, $fetcher, $this->repoRegistry );
		
		// Cache data for store1 (lastFetched = 1000)
		$status1 = $store1->get();
		$this->assertFalse( $status1->getValue()['from_cache'] );
		
		// Update last_fetched to 2000
		$this->repoRegistry->updateRepoEntry( $repoId1, [
			'last_fetched' => 2000,
		] );
		
		// Create a new store instance (will see updated last_fetched = 2000)
		$store2 = new ManifestStore( $repoUrl, $ref, null, $fetcher, $this->repoRegistry );
		
		// store2 should NOT have cached data because it uses a different cache key
		// (lastFetched = 2000 vs 1000)
		$status2 = $store2->get();
		$this->assertFalse( 
			$status2->getValue()['from_cache'],
			'Different last_fetched timestamps should use different cache keys'
		);
	}

	/**
	 * Test that cache works correctly when last_fetched is null.
	 *
	 * When a repository has no last_fetched timestamp (null), the cache key
	 * should still be computed correctly (using 0 as the value).
	 */
	public function testCacheWorksWithNullLastFetched(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$repoUrl = 'https://github.com/example/test-repo-3';
		$ref = 'main';
		
		// Create repository without last_fetched (null)
		$repoId = $this->repoRegistry->ensureRepoEntry( $repoUrl );
		
		$repo = $this->repoRegistry->getRepo( $repoId );
		$this->assertNull( $repo->lastFetched(), 'Repository should have null last_fetched' );
		
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore( $repoUrl, $ref, null, $fetcher, $this->repoRegistry );
		
		// First call - should fetch and cache
		$status1 = $store->get();
		$this->assertTrue( $status1->isOK() );
		$this->assertFalse( $status1->getValue()['from_cache'] );
		
		// Second call - should return cached data
		$status2 = $store->get();
		$this->assertTrue( $status2->isOK() );
		$this->assertTrue( $status2->getValue()['from_cache'] );
		
		// Set last_fetched to a value
		$this->repoRegistry->updateRepoEntry( $repoId, [
			'last_fetched' => 1000,
		] );
		
		// Third call - should fetch fresh data (cache key changed from null/0 to 1000)
		$status3 = $store->get();
		$this->assertTrue( $status3->isOK() );
		$this->assertFalse( 
			$status3->getValue()['from_cache'],
			'Cache should be invalidated when last_fetched changes from null to a value'
		);
	}

	/**
	 * Test that cache persists across multiple get() calls with same last_fetched.
	 *
	 * Multiple calls to get() with the same last_fetched timestamp should
	 * return cached data after the first call.
	 */
	public function testCachePersistsWithSameLastFetched(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$repoUrl = 'https://github.com/example/test-repo-4';
		$ref = 'main';
		
		$repoId = $this->repoRegistry->ensureRepoEntry( $repoUrl, [
			'last_fetched' => 1000,
		] );
		
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore( $repoUrl, $ref, null, $fetcher, $this->repoRegistry );
		
		// First call - fetch and cache
		$status1 = $store->get();
		$this->assertFalse( $status1->getValue()['from_cache'] );
		
		// Multiple subsequent calls - all should return cached data
		for ( $i = 0; $i < 3; $i++ ) {
			$status = $store->get();
			$this->assertTrue( $status->isOK() );
			$this->assertTrue( 
				$status->getValue()['from_cache'],
				"Call " . ( $i + 2 ) . " should be from cache"
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// Basic functionality tests (moved from unit tests)
	// ─────────────────────────────────────────────────────────────────────────────

	public function testGetReturnsManifestOnFirstCall(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
name: Test Manifest
packs:
  test-pack:
    version: '1.0.0'
    description: Test pack
    pages: ['Page1', 'Page2']
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$status = $store->get();
		
		$this->assertTrue( $status->isOK() );
		
		$data = $status->getValue();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'manifest', $data );
		$this->assertArrayHasKey( 'derived', $data );
		$this->assertFalse( $data['from_cache'] );
		
		// Check meta
		$this->assertEquals( 1, $data['meta']['schema_version'] );
		$this->assertEquals( 'https://github.com/example/repo', $data['meta']['repo_url'] );
		$this->assertEquals( 'main', $data['meta']['ref'] );
		$this->assertIsString( $data['meta']['hash'] );
		$this->assertNotEmpty( $data['meta']['parsed_at'] );
		
		// Check manifest
		$this->assertArrayHasKey( 'packs', $data['manifest'] );
		$this->assertArrayHasKey( 'test-pack', $data['manifest']['packs'] );
		
		// Check derived data
		$this->assertArrayHasKey( 'hierarchy', $data['derived'] );
		$this->assertArrayHasKey( 'graph', $data['derived'] );
		$this->assertArrayHasKey( 'stats', $data['derived'] );
		$this->assertEquals( 1, $data['derived']['stats']['pack_count'] );
		$this->assertEquals( 0, $data['derived']['stats']['page_count'] );
	}

	public function testGetReturnsCachedDataOnSecondCall(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		// First call - fetch and cache
		$status1 = $store->get();
		$this->assertTrue( $status1->isOK() );
		$data1 = $status1->getValue();
		$this->assertFalse( $data1['from_cache'] );
		$hash1 = $data1['meta']['hash'];
		
		// Second call - should return cached data
		$status2 = $store->get();
		$this->assertTrue( $status2->isOK() );
		$data2 = $status2->getValue();
		$this->assertTrue( $data2['from_cache'] );
		$this->assertEquals( $hash1, $data2['meta']['hash'] );
	}

	public function testGetWithRefreshBypassesCache(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		// First call - cache it
		$status1 = $store->get();
		$this->assertFalse( $status1->getValue()['from_cache'] );
		
		// Second call with refresh=true - should bypass cache
		$status2 = $store->get( true );
		$this->assertTrue( $status2->isOK() );
		$this->assertFalse( $status2->getValue()['from_cache'] );
	}

	public function testGetReturnsErrorWhenFetchFails(): void {
		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( '', false );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$status = $store->get();
		
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( 'labkipackmanager-error-manifest-missing' ) );
	}

	public function testGetReturnsErrorWhenParsingFails(): void {
		$invalidYaml = ":::: not valid yaml ::::";
		
		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $invalidYaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$status = $store->get();
		
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( 'labkipackmanager-error-parse' ) );
	}

	public function testGetManifestReturnsManifestOnly(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
name: Test Manifest
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$status = $store->getManifest();
		
		$this->assertTrue( $status->isOK() );
		$data = $status->getValue();
		
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'manifest', $data );
		$this->assertArrayHasKey( 'from_cache', $data );
		
		// Should NOT have derived data
		$this->assertArrayNotHasKey( 'derived', $data );
		
		// Should have manifest content
		$this->assertArrayHasKey( 'packs', $data['manifest'] );
	}

	public function testGetHierarchyReturnsHierarchyOnly(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
    pages: ['Page1']
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$status = $store->getHierarchy();
		
		$this->assertTrue( $status->isOK() );
		$data = $status->getValue();
		
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'hierarchy', $data );
		$this->assertArrayHasKey( 'from_cache', $data );
		
		// Should NOT have manifest or graph
		$this->assertArrayNotHasKey( 'manifest', $data );
		$this->assertArrayNotHasKey( 'graph', $data );
	}

	public function testGetGraphReturnsGraphOnly(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  pack-a:
    version: '1.0.0'
    depends_on: ['pack-b']
  pack-b:
    version: '1.0.0'
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$status = $store->getGraph();
		
		$this->assertTrue( $status->isOK() );
		$data = $status->getValue();
		
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'graph', $data );
		$this->assertArrayHasKey( 'from_cache', $data );
		
		// Should NOT have manifest or hierarchy
		$this->assertArrayNotHasKey( 'manifest', $data );
		$this->assertArrayNotHasKey( 'hierarchy', $data );
	}

	public function testClearRemovesCachedData(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		// Cache data
		$status1 = $store->get();
		$this->assertFalse( $status1->getValue()['from_cache'] );
		
		// Verify it's cached
		$status2 = $store->get();
		$this->assertTrue( $status2->getValue()['from_cache'] );
		
		// Clear cache
		$store->clear();
		
		// Next get should fetch fresh data
		$status3 = $store->get();
		$this->assertFalse( $status3->getValue()['from_cache'] );
	}

	public function testDifferentRepoUrlsUseDifferentCacheKeys(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store1 = new ManifestStore(
			'https://github.com/example/repo1',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$store2 = new ManifestStore(
			'https://github.com/example/repo2',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		// Cache data for repo1
		$store1->get();
		
		// repo2 should NOT have cached data
		$status = $store2->get();
		$this->assertFalse( $status->getValue()['from_cache'] );
	}

	public function testDifferentRefsUseDifferentCacheKeys(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
packs:
  test-pack:
    version: '1.0.0'
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store1 = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$store2 = new ManifestStore(
			'https://github.com/example/repo',
			'dev',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		// Cache data for main
		$store1->get();
		
		// dev ref should NOT have cached data
		$status = $store2->get();
		$this->assertFalse( $status->getValue()['from_cache'] );
	}

	public function testStatsAreCalculatedCorrectly(): void {
		$yaml = <<<YAML
schema_version: '1.0.0'
pages:
  Page1:
    file: 'pages/Page1.wiki'
  Page2:
    file: 'pages/Page2.wiki'
  Page3:
    file: 'pages/Page3.wiki'
packs:
  pack-a:
    version: '1.0.0'
    pages: ['Page1']
  pack-b:
    version: '1.0.0'
    pages: ['Page2', 'Page3']
YAML;

		$cache = $this->createTestCache();
		/** @var ManifestFetcher&MockObject $fetcher */
		$fetcher = $this->createFetcherMock( $yaml );
		
		$store = new ManifestStore(
			'https://github.com/example/repo',
			'main',
			$cache,
			$fetcher,
			$this->repoRegistry
		);
		
		$status = $store->get();
		$data = $status->getValue();
		
		$this->assertEquals( 2, $data['derived']['stats']['pack_count'] );
		$this->assertEquals( 3, $data['derived']['stats']['page_count'] );
	}
}

