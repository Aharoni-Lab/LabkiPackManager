<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for LabkiPackRegistry
 *
 * Tests the pack-level registry service for the labki_pack table.
 * These tests use the actual MediaWiki database.
 *
 * @covers \LabkiPackManager\Services\LabkiPackRegistry
 * @group Database
 */
class LabkiPackRegistryTest extends MediaWikiIntegrationTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;

	protected function setUp(): void {
		parent::setUp();
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
	}

	/**
	 * Helper to create a test ref (which creates a repo)
	 */
	private function createTestRef( string $url = 'https://example.com/test-packs', string $ref = 'main' ): ContentRefId {
		$repoId = $this->repoRegistry->ensureRepoEntry( $url );
		return $this->refRegistry->ensureRefEntry( $repoId, $ref );
	}

	private function newRegistry(): LabkiPackRegistry {
		return new LabkiPackRegistry();
	}

	public function testAddPack_CreatesNewPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'TestPack', [ 'version' => '1.0.0' ] );
		
		$this->assertInstanceOf( PackId::class, $packId );
		$this->assertGreaterThan( 0, $packId->toInt() );
	}

	public function testAddPack_WithDefaultFields_SetsDefaults(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'DefaultPack', [] );
		$pack = $registry->getPack( $packId );
		
		$this->assertNotNull( $pack );
		$this->assertSame( 'DefaultPack', $pack->name() );
		$this->assertNull( $pack->version() );
		$this->assertNull( $pack->sourceCommit() );
		$this->assertNotNull( $pack->installedAt() );
		$this->assertNull( $pack->installedBy() );
		$this->assertSame( 'installed', $pack->status() );
		$this->assertNotNull( $pack->updatedAt() );
	}

	public function testAddPack_WithExtraFields_OverridesDefaults(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'CustomPack', [
			'version' => '2.0.0',
			'source_commit' => 'abc123',
			'installed_by' => 999,
			'status' => 'removed',
		] );
		
		$pack = $registry->getPack( $packId );
		
		$this->assertNotNull( $pack );
		$this->assertSame( '2.0.0', $pack->version() );
		$this->assertSame( 'abc123', $pack->sourceCommit() );
		$this->assertSame( 999, $pack->installedBy() );
		$this->assertSame( 'removed', $pack->status() );
	}

	public function testAddPack_WhenExists_ReturnsExistingId(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId1 = $registry->addPack( $refId, 'ExistingPack', [ 'version' => '1.0.0' ] );
		$packId2 = $registry->addPack( $refId, 'ExistingPack', [ 'version' => '2.0.0' ] );
		
		$this->assertSame( $packId1->toInt(), $packId2->toInt() );
	}

	public function testGetPackIdByName_WithIntRefId_FindsPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId->toInt(), 'FindMe', [ 'version' => '1.0.0' ] );
		$foundId = $registry->getPackIdByName( $refId->toInt(), 'FindMe' );
		
		$this->assertNotNull( $foundId );
		$this->assertSame( $packId->toInt(), $foundId->toInt() );
	}

	public function testGetPackIdByName_WithContentRefId_FindsPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'FindMe2', [ 'version' => '1.0.0' ] );
		$foundId = $registry->getPackIdByName( $refId, 'FindMe2' );
		
		$this->assertNotNull( $foundId );
		$this->assertSame( $packId->toInt(), $foundId->toInt() );
	}

	public function testGetPackIdByName_IgnoresVersion(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();
		
		// Pack uniqueness is by (ref_id, name), not version
		$packId = $registry->addPack( $refId, 'VersionTest', [ 'version' => '1.0.0' ] );

		// Should find same pack regardless of version parameter
		$found1 = $registry->getPackIdByName( $refId, 'VersionTest', '1.0.0' );
		$found2 = $registry->getPackIdByName( $refId, 'VersionTest', '2.0.0' );
		$found3 = $registry->getPackIdByName( $refId, 'VersionTest', null );
		
		$this->assertSame( $packId->toInt(), $found1->toInt() );
		$this->assertSame( $packId->toInt(), $found2->toInt() );
		$this->assertSame( $packId->toInt(), $found3->toInt() );
	}

	public function testGetPackIdByName_WhenNotExists_ReturnsNull(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$result = $registry->getPackIdByName( $refId, 'NonExistent' );
		
		$this->assertNull( $result );
	}

	public function testGetPack_WithIntId_ReturnsPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'GetTest', [ 'version' => '1.0.0' ] );
		$pack = $registry->getPack( $packId->toInt() );
		
		$this->assertNotNull( $pack );
		$this->assertInstanceOf( Pack::class, $pack );
		$this->assertSame( 'GetTest', $pack->name() );
		$this->assertSame( $packId->toInt(), $pack->id()->toInt() );
	}

	public function testGetPack_WithPackId_ReturnsPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'GetTest2', [ 'version' => '1.0.0' ] );
		$pack = $registry->getPack( $packId );
		
		$this->assertNotNull( $pack );
		$this->assertInstanceOf( Pack::class, $pack );
		$this->assertSame( 'GetTest2', $pack->name() );
		$this->assertTrue( $packId->equals( $pack->id() ) );
	}

	public function testGetPack_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();

		$result = $registry->getPack( 999999 );
		
		$this->assertNull( $result );
	}

	public function testGetPackInfo_IsAliasForGetPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'InfoTest', [ 'version' => '1.0.0' ] );
		
		$pack1 = $registry->getPack( $packId );
		$pack2 = $registry->getPackInfo( $packId );
		
		$this->assertNotNull( $pack1 );
		$this->assertNotNull( $pack2 );
		$this->assertSame( $pack1->name(), $pack2->name() );
		$this->assertSame( $pack1->id()->toInt(), $pack2->id()->toInt() );
	}

	public function testListPacksByRef_WithIntRefId_ReturnsAllPacks(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$registry->addPack( $refId->toInt(), 'Pack1', [] );
		$registry->addPack( $refId->toInt(), 'Pack2', [] );
		$registry->addPack( $refId->toInt(), 'Pack3', [] );

		$packs = $registry->listPacksByRef( $refId->toInt() );
		
		$this->assertIsArray( $packs );
		$this->assertGreaterThanOrEqual( 3, count( $packs ) );
		
		foreach ( $packs as $pack ) {
			$this->assertInstanceOf( Pack::class, $pack );
		}
		
		$names = array_map( fn( $pack ) => $pack->name(), $packs );
		$this->assertContains( 'Pack1', $names );
		$this->assertContains( 'Pack2', $names );
		$this->assertContains( 'Pack3', $names );
	}

	public function testListPacksByRef_WithContentRefId_ReturnsAllPacks(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$registry->addPack( $refId, 'PackA', [] );
		$registry->addPack( $refId, 'PackB', [] );

		$packs = $registry->listPacksByRef( $refId );
		
		$this->assertGreaterThanOrEqual( 2, count( $packs ) );
		
		$names = array_map( fn( $pack ) => $pack->name(), $packs );
		$this->assertContains( 'PackA', $names );
		$this->assertContains( 'PackB', $names );
	}

	public function testListPacksByRef_OrderedByPackId(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$registry->addPack( $refId, 'Z_Pack', [] );
		$registry->addPack( $refId, 'A_Pack', [] );
		$registry->addPack( $refId, 'M_Pack', [] );

		$packs = $registry->listPacksByRef( $refId );
		
		$this->assertGreaterThanOrEqual( 3, count( $packs ) );
		
		// Verify ordering by ID (not name)
		$prevId = 0;
		foreach ( $packs as $pack ) {
			$this->assertGreaterThan( $prevId, $pack->id()->toInt() );
			$prevId = $pack->id()->toInt();
		}
	}

	public function testUpdatePack_WithIntId_UpdatesFields(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'UpdateTest', [ 'version' => '1.0.0' ] );

		$registry->updatePack( $packId->toInt(), [
			'version' => '2.0.0',
			'status' => 'removed',
		] );
		
		$pack = $registry->getPack( $packId );
		$this->assertNotNull( $pack );
		$this->assertSame( '2.0.0', $pack->version() );
		$this->assertSame( 'removed', $pack->status() );
	}

	public function testUpdatePack_WithPackId_UpdatesFields(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'UpdateTest2', [ 'version' => '1.0.0' ] );

		$registry->updatePack( $packId, [
			'source_commit' => 'new_commit',
		] );
		
		$pack = $registry->getPack( $packId );
		$this->assertNotNull( $pack );
		$this->assertSame( 'new_commit', $pack->sourceCommit() );
	}

	public function testUpdatePack_AutomaticallyUpdatesTimestamp(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'TimestampTest', [] );
		$before = $registry->getPack( $packId );
		$this->assertNotNull( $before );
		$beforeUpdated = $before->updatedAt();

		usleep( 10000 ); // 10ms delay

		$registry->updatePack( $packId, [ 'version' => '1.1.0' ] );
		
		$after = $registry->getPack( $packId );
		$this->assertNotNull( $after );
		$this->assertNotNull( $after->updatedAt() );
		
		if ( $beforeUpdated !== null ) {
			$this->assertGreaterThanOrEqual( $beforeUpdated, $after->updatedAt() );
		}
	}

	public function testRegisterPack_WhenNew_CreatesPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->registerPack( $refId, 'NewRegister', '1.0.0', 123 );
		
		$this->assertInstanceOf( PackId::class, $packId );
		
		$pack = $registry->getPack( $packId );
		$this->assertNotNull( $pack );
		$this->assertSame( 'NewRegister', $pack->name() );
		$this->assertSame( '1.0.0', $pack->version() );
		$this->assertSame( 123, $pack->installedBy() );
		$this->assertSame( 'installed', $pack->status() );
	}

	public function testRegisterPack_WhenExists_UpdatesFields(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		// Create initial pack
		$packId1 = $registry->addPack( $refId, 'ExistingRegister', [
			'version' => '1.0.0',
			'installed_by' => 100,
			'status' => 'removed',
		] );

		// Register again (should update)
		$packId2 = $registry->registerPack( $refId, 'ExistingRegister', '2.0.0', 200 );
		
		$this->assertSame( $packId1->toInt(), $packId2->toInt() );
		
		$pack = $registry->getPack( $packId2 );
		$this->assertNotNull( $pack );
		$this->assertSame( '2.0.0', $pack->version() );
		$this->assertSame( 200, $pack->installedBy() );
		$this->assertSame( 'installed', $pack->status() );
	}

	public function testRemovePack_RemovesPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'RemoveTest', [] );
		$this->assertNotNull( $registry->getPack( $packId ) );

		$registry->removePack( $packId );
		
		$this->assertNull( $registry->getPack( $packId ) );
	}

	public function testDeletePack_RemovesPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'DeleteTest', [] );
		$this->assertNotNull( $registry->getPack( $packId ) );

		$result = $registry->deletePack( $packId );
		
		$this->assertTrue( $result );
		$this->assertNull( $registry->getPack( $packId ) );
	}

	public function testRemovePackAndPages_RemovesPack(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$packId = $registry->addPack( $refId, 'RemoveWithPages', [] );
		$this->assertNotNull( $registry->getPack( $packId ) );

		$registry->removePackAndPages( $packId );
		
		$this->assertNull( $registry->getPack( $packId ) );
	}

	public function testStoreDependencies_StoresDependencies(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$pack1 = $registry->addPack( $refId, 'PackWithDeps', [] );
		$pack2 = $registry->addPack( $refId, 'Dependency1', [] );
		$pack3 = $registry->addPack( $refId, 'Dependency2', [] );

		$registry->storeDependencies( $pack1, [ $pack2, $pack3 ] );
		
		$deps = $registry->getDependencies( $pack1 );
		
		$this->assertCount( 2, $deps );
		$depIds = array_map( fn( $id ) => $id->toInt(), $deps );
		$this->assertContains( $pack2->toInt(), $depIds );
		$this->assertContains( $pack3->toInt(), $depIds );
	}

	public function testStoreDependencies_WithEmptyArray_DoesNothing(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$pack = $registry->addPack( $refId, 'NoDeps', [] );

		// Should not throw
		$registry->storeDependencies( $pack, [] );
		
		$deps = $registry->getDependencies( $pack );
		$this->assertEmpty( $deps );
	}

	public function testStoreDependencies_WithIntPackIds_StoresDependencies(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$pack1 = $registry->addPack( $refId, 'PackInt', [] );
		$pack2 = $registry->addPack( $refId, 'DepInt', [] );

		$registry->storeDependencies( $pack1->toInt(), [ $pack2->toInt() ] );
		
		$deps = $registry->getDependencies( $pack1->toInt() );
		
		$this->assertCount( 1, $deps );
		$this->assertSame( $pack2->toInt(), $deps[0]->toInt() );
	}

	public function testGetDependencies_WhenNoDependencies_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$pack = $registry->addPack( $refId, 'Standalone', [] );

		$deps = $registry->getDependencies( $pack );
		
		$this->assertIsArray( $deps );
		$this->assertEmpty( $deps );
	}

	public function testGetDependencies_WithIntPackId_ReturnsDependencies(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$pack1 = $registry->addPack( $refId, 'Parent', [] );
		$pack2 = $registry->addPack( $refId, 'Child', [] );

		$registry->storeDependencies( $pack1, [ $pack2 ] );
		
		$deps = $registry->getDependencies( $pack1->toInt() );
		
		$this->assertCount( 1, $deps );
	}

	public function testGetPacksDependingOn_FindsDependents(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$basePack = $registry->addPack( $refId, 'BasePack', [] );
		$dependent1 = $registry->addPack( $refId, 'Dependent1', [] );
		$dependent2 = $registry->addPack( $refId, 'Dependent2', [] );
		$independent = $registry->addPack( $refId, 'Independent', [] );

		// Make dependent1 and dependent2 depend on basePack
		$registry->storeDependencies( $dependent1, [ $basePack ] );
		$registry->storeDependencies( $dependent2, [ $basePack ] );

		$dependents = $registry->getPacksDependingOn( $refId, $basePack );
		
		$this->assertCount( 2, $dependents );
		
		$depNames = array_map( fn( $pack ) => $pack->name(), $dependents );
		$this->assertContains( 'Dependent1', $depNames );
		$this->assertContains( 'Dependent2', $depNames );
		$this->assertNotContains( 'Independent', $depNames );
	}

	public function testGetPacksDependingOn_WithIntPackId_FindsDependents(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$base = $registry->addPack( $refId, 'Base', [] );
		$dep = $registry->addPack( $refId, 'Dep', [] );

		$registry->storeDependencies( $dep, [ $base ] );

		$dependents = $registry->getPacksDependingOn( $refId, $base->toInt() );
		
		$this->assertCount( 1, $dependents );
		$this->assertSame( 'Dep', $dependents[0]->name() );
	}

	public function testGetPacksDependingOn_WhenNoDependents_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$pack = $registry->addPack( $refId, 'Lonely', [] );

		$dependents = $registry->getPacksDependingOn( $refId, $pack );
		
		$this->assertIsArray( $dependents );
		$this->assertEmpty( $dependents );
	}

	public function testRemoveDependencies_RemovesDependencies(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$pack1 = $registry->addPack( $refId, 'PackRemoveDeps', [] );
		$pack2 = $registry->addPack( $refId, 'DepRemove', [] );

		$registry->storeDependencies( $pack1, [ $pack2 ] );
		
		// Verify dependency exists
		$deps = $registry->getDependencies( $pack1 );
		$this->assertCount( 1, $deps );

		// Remove dependencies
		$registry->removeDependencies( $pack1 );
		
		// Verify dependencies removed
		$depsAfter = $registry->getDependencies( $pack1 );
		$this->assertEmpty( $depsAfter );
	}

	public function testRemoveDependencies_WithIntPackId_RemovesDependencies(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$pack1 = $registry->addPack( $refId, 'PackRemoveInt', [] );
		$pack2 = $registry->addPack( $refId, 'DepRemoveInt', [] );

		$registry->storeDependencies( $pack1, [ $pack2 ] );
		
		$registry->removeDependencies( $pack1->toInt() );
		
		$depsAfter = $registry->getDependencies( $pack1 );
		$this->assertEmpty( $depsAfter );
	}

	/**
	 * Test that packs in different refs are independent
	 */
	public function testPacksInDifferentRefs_AreIndependent(): void {
		$ref1 = $this->createTestRef( 'https://example.com/repo1', 'main' );
		$ref2 = $this->createTestRef( 'https://example.com/repo2', 'main' );
		$registry = $this->newRegistry();

		// Same pack name in different refs
		$pack1 = $registry->addPack( $ref1, 'SameName', [ 'version' => '1.0.0' ] );
		$pack2 = $registry->addPack( $ref2, 'SameName', [ 'version' => '2.0.0' ] );
		
		$this->assertNotSame( $pack1->toInt(), $pack2->toInt() );
		
		$p1 = $registry->getPack( $pack1 );
		$p2 = $registry->getPack( $pack2 );
		
		$this->assertSame( '1.0.0', $p1->version() );
		$this->assertSame( '2.0.0', $p2->version() );
		$this->assertSame( $ref1->toInt(), $p1->contentRefId()->toInt() );
		$this->assertSame( $ref2->toInt(), $p2->contentRefId()->toInt() );
	}

	/**
	 * Test that dependencies are scoped to a ref
	 */
	public function testGetPacksDependingOn_OnlyFindsWithinSameRef(): void {
		$ref1 = $this->createTestRef( 'https://example.com/repo-dep1', 'main' );
		$ref2 = $this->createTestRef( 'https://example.com/repo-dep2', 'main' );
		$registry = $this->newRegistry();

		$base1 = $registry->addPack( $ref1, 'Base', [] );
		$dep1 = $registry->addPack( $ref1, 'Dependent', [] );
		
		$base2 = $registry->addPack( $ref2, 'Base', [] );
		$dep2 = $registry->addPack( $ref2, 'Dependent', [] );

		$registry->storeDependencies( $dep1, [ $base1 ] );
		$registry->storeDependencies( $dep2, [ $base2 ] );

		// Query ref1 - should only find dep1
		$dependents1 = $registry->getPacksDependingOn( $ref1, $base1 );
		$this->assertCount( 1, $dependents1 );
		$this->assertSame( $dep1->toInt(), $dependents1[0]->id()->toInt() );

		// Query ref2 - should only find dep2
		$dependents2 = $registry->getPacksDependingOn( $ref2, $base2 );
		$this->assertCount( 1, $dependents2 );
		$this->assertSame( $dep2->toInt(), $dependents2[0]->id()->toInt() );
	}

	/**
	 * Test timestamps are set correctly on creation
	 */
	public function testTimestamps_SetOnCreation(): void {
		$refId = $this->createTestRef();
		$registry = $this->newRegistry();

		$beforeTime = wfTimestampNow();
		$packId = $registry->addPack( $refId, 'TimestampPack', [] );
		$afterTime = wfTimestampNow();
		
		$pack = $registry->getPack( $packId );
		$this->assertNotNull( $pack );
		$this->assertNotNull( $pack->installedAt() );
		$this->assertNotNull( $pack->updatedAt() );
		
		// Timestamps should be between before and after
		$this->assertGreaterThanOrEqual( (int)$beforeTime, $pack->installedAt() );
		$this->assertLessThanOrEqual( (int)$afterTime, $pack->installedAt() );
	}
}
