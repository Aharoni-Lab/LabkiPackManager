<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Services\ManifestStore;
use LabkiPackManager\Services\ManifestFetcher;
use MediaWiki\Status\Status;
use WANObjectCache;
use HashBagOStuff;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \LabkiPackManager\Services\ManifestStore
 */
class ManifestStoreTest extends TestCase {

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
			$fetcher
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
		$this->assertNotEmpty( $data['meta']['parsed_at'] ); // wfTimestampNow() returns string
		
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
			$fetcher
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
			$fetcher
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
			$fetcher
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
			$fetcher
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
			$fetcher
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
			$fetcher
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
			$fetcher
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
			$fetcher
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
			$fetcher
		);
		
		$store2 = new ManifestStore(
			'https://github.com/example/repo2',
			'main',
			$cache,
			$fetcher
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
			$fetcher
		);
		
		$store2 = new ManifestStore(
			'https://github.com/example/repo',
			'dev',
			$cache,
			$fetcher
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
			$fetcher
		);
		
		$status = $store->get();
		$data = $status->getValue();
		
		$this->assertEquals( 2, $data['derived']['stats']['pack_count'] );
		$this->assertEquals( 3, $data['derived']['stats']['page_count'] );
	}
}

