<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Services\PackStateStore;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use WANObjectCache;
use HashBagOStuff;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\PackStateStore
 */
class PackStateStoreTest extends TestCase {

	private function createTestCache(): WANObjectCache {
		// Use HashBagOStuff wrapped in WANObjectCache for testing
		return new WANObjectCache( [
			'cache' => new HashBagOStuff(),
		] );
	}

	public function testSaveAndGet(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;
		$packs = [
			'test pack' => [
				'selected' => true,
				'pages' => [],
			],
		];

		$state = new PackSessionState( $refId, $userId, $packs );

		$cache = $this->createTestCache();
		$store = new PackStateStore( $cache );
		
		// Save state
		$result = $store->save( $state );
		$this->assertTrue( $result );

		// Retrieve state
		$retrieved = $store->get( $userId, $refId );
		$this->assertInstanceOf( PackSessionState::class, $retrieved );
		$this->assertEquals( $state->refId()->toInt(), $retrieved->refId()->toInt() );
		$this->assertEquals( $state->userId(), $retrieved->userId() );
		$this->assertEquals( $state->hash(), $retrieved->hash() );
	}

	public function testGetReturnsStateWhenCached(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;
		$packs = [
			'test pack' => [
				'selected' => true,
			],
		];

		$originalState = new PackSessionState( $refId, $userId, $packs );
		$originalHash = $originalState->hash();

		$cache = $this->createTestCache();
		$store = new PackStateStore( $cache );
		
		// Save first
		$store->save( $originalState );

		// Then retrieve
		$state = $store->get( $userId, $refId );

		$this->assertInstanceOf( PackSessionState::class, $state );
		$this->assertEquals( 5, $state->refId()->toInt() );
		$this->assertEquals( 123, $state->userId() );
		$this->assertEquals( $originalHash, $state->hash() );
	}

	public function testGetReturnsNullWhenNotCached(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;

		$cache = $this->createTestCache();
		$store = new PackStateStore( $cache );
		
		// Don't save anything, just try to retrieve
		$state = $store->get( $userId, $refId );

		$this->assertNull( $state );
	}

	public function testGetReturnsNullWhenInvalidData(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;

		$cache = $this->createTestCache();
		$store = new PackStateStore( $cache );
		
		// Manually insert invalid data into cache
		$key = $cache->makeKey( 'labki:packstate', (string)$userId, (string)$refId->toInt() );
		$cache->set( $key, 'invalid_data', 1800 ); // Not an array

		$state = $store->get( $userId, $refId );

		$this->assertNull( $state );
	}

	public function testClear(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;
		$packs = [ 'test pack' => [ 'selected' => true ] ];

		$cache = $this->createTestCache();
		$store = new PackStateStore( $cache );
		
		// Save state first
		$state = new PackSessionState( $refId, $userId, $packs );
		$store->save( $state );

		// Verify it exists
		$this->assertNotNull( $store->get( $userId, $refId ) );

		// Clear it
		$result = $store->clear( $userId, $refId );
		$this->assertTrue( $result );

		// Verify it's gone
		$this->assertNull( $store->get( $userId, $refId ) );
	}

	public function testExists(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;
		$packs = [ 'test pack' => [ 'selected' => true ] ];

		$cache = $this->createTestCache();
		$store = new PackStateStore( $cache );

		// Initially doesn't exist
		$this->assertFalse( $store->exists( $userId, $refId ) );

		// Save state
		$state = new PackSessionState( $refId, $userId, $packs );
		$store->save( $state );

		// Now it exists
		$this->assertTrue( $store->exists( $userId, $refId ) );

		// Clear it
		$store->clear( $userId, $refId );

		// Now it doesn't exist again
		$this->assertFalse( $store->exists( $userId, $refId ) );
	}

	public function testCacheKeyFormat(): void {
		$refId1 = new ContentRefId( 5 );
		$refId2 = new ContentRefId( 10 );
		$userId1 = 123;
		$userId2 = 456;
		$packs = [ 'test pack' => [ 'selected' => true ] ];

		$cache = $this->createTestCache();
		$store = new PackStateStore( $cache );

		// Save state for user1 + ref1
		$state1 = new PackSessionState( $refId1, $userId1, $packs );
		$store->save( $state1 );

		// Save state for user2 + ref1
		$state2 = new PackSessionState( $refId1, $userId2, $packs );
		$store->save( $state2 );

		// Save state for user1 + ref2
		$state3 = new PackSessionState( $refId2, $userId1, $packs );
		$store->save( $state3 );

		// Verify they're all independent
		$retrieved1 = $store->get( $userId1, $refId1 );
		$retrieved2 = $store->get( $userId2, $refId1 );
		$retrieved3 = $store->get( $userId1, $refId2 );

		$this->assertNotNull( $retrieved1 );
		$this->assertNotNull( $retrieved2 );
		$this->assertNotNull( $retrieved3 );

		$this->assertEquals( $userId1, $retrieved1->userId() );
		$this->assertEquals( $userId2, $retrieved2->userId() );
		$this->assertEquals( $refId1->toInt(), $retrieved1->refId()->toInt() );
		$this->assertEquals( $refId2->toInt(), $retrieved3->refId()->toInt() );
	}
}

