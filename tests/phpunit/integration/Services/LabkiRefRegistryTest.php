<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRef;
use LabkiPackManager\Domain\ContentRefId;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for LabkiRefRegistry
 *
 * Tests the ref-level registry service for the labki_content_ref table.
 * These tests use the actual MediaWiki database.
 *
 * @covers \LabkiPackManager\Services\LabkiRefRegistry
 * @group Database
 */
class LabkiRefRegistryTest extends MediaWikiIntegrationTestCase {

	private LabkiRepoRegistry $repoRegistry;

	protected function setUp(): void {
		parent::setUp();
		$this->repoRegistry = new LabkiRepoRegistry();
	}

	/**
	 * Helper to create a test repository
	 */
	private function createTestRepo( string $url = 'https://example.com/test-repo' ): ContentRepoId {
		return $this->repoRegistry->ensureRepoEntry( $url );
	}

	/**
	 * Helper to create a test repository and return the ContentRepo object
	 */
	private function createTestRepoObject( string $url = 'https://example.com/test-repo-object' ): ContentRepo {
		$id = $this->repoRegistry->ensureRepoEntry( $url );
		return $this->repoRegistry->getRepo( $id );
	}

	private function newRegistry(): LabkiRefRegistry {
		return new LabkiRefRegistry();
	}

	/**
	 */
	public function testConstruct_WithoutRepoRegistry_CreatesDefault(): void {
		$registry = new LabkiRefRegistry();
		
		$this->assertInstanceOf( LabkiRefRegistry::class, $registry );
	}

	/**
	 */
	public function testConstruct_WithRepoRegistry_UsesProvided(): void {
		$repoRegistry = new LabkiRepoRegistry();
		$registry = new LabkiRefRegistry( $repoRegistry );
		
		$this->assertInstanceOf( LabkiRefRegistry::class, $registry );
	}

	/**
	 */
	public function testAddRefEntry_CreatesNewRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		
		$this->assertInstanceOf( ContentRefId::class, $refId );
		$this->assertGreaterThan( 0, $refId->toInt() );
	}

	/**
	 */
	public function testAddRefEntry_WithDefaultFields_SetsDefaults(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$ref = $registry->getRefById( $refId );
		
		$this->assertNotNull( $ref );
		$this->assertSame( 'main', $ref->sourceRef() );
		$this->assertSame( $repoId->toInt(), $ref->repoId()->toInt() );
		$this->assertNull( $ref->lastCommit() );
		$this->assertNull( $ref->manifestHash() );
		$this->assertNull( $ref->manifestLastParsed() );
		$this->assertNull( $ref->worktreePath() );
		$this->assertNotNull( $ref->createdAt() );
		$this->assertNotNull( $ref->updatedAt() );
	}

	/**
	 */
	public function testAddRefEntry_WithExtraFields_OverridesDefaults(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'develop', [
			'worktree_path' => '/var/git/repo/worktrees/develop',
			'last_commit' => 'abc123def456',
			'manifest_hash' => 'hash789',
		] );
		
		$ref = $registry->getRefById( $refId );
		
		$this->assertNotNull( $ref );
		$this->assertSame( 'develop', $ref->sourceRef() );
		$this->assertSame( '/var/git/repo/worktrees/develop', $ref->worktreePath() );
		$this->assertSame( 'abc123def456', $ref->lastCommit() );
		$this->assertSame( 'hash789', $ref->manifestHash() );
	}

	/**
	 */
	public function testGetRefById_WithIntId_ReturnsRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$ref = $registry->getRefById( $refId->toInt() );
		
		$this->assertNotNull( $ref );
		$this->assertInstanceOf( ContentRef::class, $ref );
		$this->assertSame( 'main', $ref->sourceRef() );
		$this->assertSame( $refId->toInt(), $ref->id()->toInt() );
	}

	/**
	 */
	public function testGetRefById_WithContentRefId_ReturnsRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$ref = $registry->getRefById( $refId );
		
		$this->assertNotNull( $ref );
		$this->assertInstanceOf( ContentRef::class, $ref );
		$this->assertSame( 'main', $ref->sourceRef() );
		$this->assertTrue( $refId->equals( $ref->id() ) );
	}

	/**
	 */
	public function testGetRefById_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();
		
		$result = $registry->getRefById( 999999 );
		
		$this->assertNull( $result );
	}

	/**
	 */
	public function testGetRefIdByRepoAndRef_WithIntRepoId_FindsRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$foundId = $registry->getRefIdByRepoAndRef( $repoId->toInt(), 'main' );
		
		$this->assertNotNull( $foundId );
		$this->assertSame( $refId->toInt(), $foundId->toInt() );
	}

	/**
	 */
	public function testGetRefIdByRepoAndRef_WithContentRepoId_FindsRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$foundId = $registry->getRefIdByRepoAndRef( $repoId, 'main' );
		
		$this->assertNotNull( $foundId );
		$this->assertSame( $refId->toInt(), $foundId->toInt() );
	}

	/**
	 */
	public function testGetRefIdByRepoAndRef_WithUrlString_FindsRef(): void {
		$url = 'https://example.com/test-ref-by-url';
		$repoId = $this->createTestRepo( $url );
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$foundId = $registry->getRefIdByRepoAndRef( $url, 'main' );
		
		$this->assertNotNull( $foundId );
		$this->assertSame( $refId->toInt(), $foundId->toInt() );
	}

	/**
	 */
	public function testGetRefIdByRepoAndRef_WhenNotExists_ReturnsNull(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$result = $registry->getRefIdByRepoAndRef( $repoId, 'nonexistent' );
		
		$this->assertNull( $result );
	}

	/**
	 */
	public function testUpdateRefEntry_WithIntId_UpdatesFields(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$before = $registry->getRefById( $refId );
		$this->assertNotNull( $before );

		$registry->updateRefEntry( $refId->toInt(), [
			'last_commit' => 'new_commit_hash',
			'manifest_hash' => 'new_manifest_hash',
		] );
		
		$after = $registry->getRefById( $refId );
		$this->assertNotNull( $after );
		$this->assertSame( 'new_commit_hash', $after->lastCommit() );
		$this->assertSame( 'new_manifest_hash', $after->manifestHash() );
	}

	/**
	 */
	public function testUpdateRefEntry_WithContentRefId_UpdatesFields(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );

		$registry->updateRefEntry( $refId, [
			'worktree_path' => '/new/path',
		] );
		
		$after = $registry->getRefById( $refId );
		$this->assertNotNull( $after );
		$this->assertSame( '/new/path', $after->worktreePath() );
	}

	/**
	 */
	public function testUpdateRefEntry_AutomaticallyUpdatesTimestamp(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$before = $registry->getRefById( $refId );
		$this->assertNotNull( $before );
		$beforeUpdated = $before->updatedAt();

		// Small delay to ensure timestamp changes
		usleep( 10000 ); // 10ms

		$registry->updateRefEntry( $refId, [ 'last_commit' => 'updated_commit' ] );
		
		$after = $registry->getRefById( $refId );
		$this->assertNotNull( $after );
		$this->assertNotNull( $after->updatedAt() );
		
		if ( $beforeUpdated !== null ) {
			$this->assertGreaterThanOrEqual( $beforeUpdated, $after->updatedAt() );
		}
	}

	/**
	 */
	public function testUpdateRefEntry_WithEmptyFields_DoesNothing(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$before = $registry->getRefById( $refId );
		
		// Should not throw an exception
		$registry->updateRefEntry( $refId, [] );
		
		$after = $registry->getRefById( $refId );
		$this->assertNotNull( $after );
		$this->assertSame( $before->sourceRef(), $after->sourceRef() );
	}

	/**
	 */
	public function testEnsureRefEntry_WhenNew_CreatesRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->ensureRefEntry( $repoId, 'develop' );
		
		$this->assertInstanceOf( ContentRefId::class, $refId );
		$this->assertNotNull( $registry->getRefIdByRepoAndRef( $repoId, 'develop' ) );
		
		$ref = $registry->getRefById( $refId );
		$this->assertNotNull( $ref );
		$this->assertSame( 'develop', $ref->sourceRef() );
	}

	/**
	 */
	public function testEnsureRefEntry_WhenExists_ReturnsExistingId(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId1 = $registry->ensureRefEntry( $repoId, 'main' );
		$refId2 = $registry->ensureRefEntry( $repoId, 'main' );
		
		$this->assertSame( $refId1->toInt(), $refId2->toInt() );
	}

	/**
	 */
	public function testEnsureRefEntry_WhenExists_UpdatesFields(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId1 = $registry->ensureRefEntry( $repoId, 'main', [
			'last_commit' => 'old_commit',
		] );
		$repo1 = $registry->getRefById( $refId1 );
		$this->assertSame( 'old_commit', $repo1->lastCommit() );

		$refId2 = $registry->ensureRefEntry( $repoId, 'main', [
			'last_commit' => 'new_commit',
		] );
		
		$this->assertSame( $refId1->toInt(), $refId2->toInt() );
		
		$repo2 = $registry->getRefById( $refId2 );
		$this->assertNotNull( $repo2 );
		$this->assertSame( 'new_commit', $repo2->lastCommit() );
	}

	/**
	 */
	public function testEnsureRefEntry_IsIdempotent(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		// Call multiple times
		$refId1 = $registry->ensureRefEntry( $repoId, 'main' );
		$refId2 = $registry->ensureRefEntry( $repoId, 'main' );
		$refId3 = $registry->ensureRefEntry( $repoId, 'main' );
		
		$this->assertSame( $refId1->toInt(), $refId2->toInt() );
		$this->assertSame( $refId2->toInt(), $refId3->toInt() );
	}

	/**
	 */
	public function testEnsureRefEntry_WithUrlString_ResolvesRepo(): void {
		$url = 'https://example.com/test-ensure-url';
		$repoId = $this->createTestRepo( $url );
		$registry = $this->newRegistry();

		$refId = $registry->ensureRefEntry( $url, 'main' );
		
		$this->assertInstanceOf( ContentRefId::class, $refId );
		
		$ref = $registry->getRefById( $refId );
		$this->assertNotNull( $ref );
		$this->assertSame( $repoId->toInt(), $ref->repoId()->toInt() );
	}

	/**
	 */
	public function testListRefsForRepo_WithIntId_ReturnsAllRefs(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$registry->addRefEntry( $repoId->toInt(), 'main' );
		$registry->addRefEntry( $repoId->toInt(), 'develop' );
		$registry->addRefEntry( $repoId->toInt(), 'v1.0.0' );

		$refs = $registry->listRefsForRepo( $repoId->toInt() );
		
		$this->assertIsArray( $refs );
		$this->assertGreaterThanOrEqual( 3, count( $refs ) );
		
		// Verify all entries are ContentRef objects
		foreach ( $refs as $ref ) {
			$this->assertInstanceOf( ContentRef::class, $ref );
		}
		
		$refNames = array_map( fn( $ref ) => $ref->sourceRef(), $refs );
		$this->assertContains( 'main', $refNames );
		$this->assertContains( 'develop', $refNames );
		$this->assertContains( 'v1.0.0', $refNames );
	}

	/**
	 */
	public function testListRefsForRepo_WithContentRepoId_ReturnsAllRefs(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$registry->addRefEntry( $repoId->toInt(), 'main' );
		$registry->addRefEntry( $repoId->toInt(), 'staging' );

		$refs = $registry->listRefsForRepo( $repoId );
		
		$this->assertGreaterThanOrEqual( 2, count( $refs ) );
		
		$refNames = array_map( fn( $ref ) => $ref->sourceRef(), $refs );
		$this->assertContains( 'main', $refNames );
		$this->assertContains( 'staging', $refNames );
	}

	/**
	 */
	public function testListRefsForRepo_WithUrlString_ReturnsAllRefs(): void {
		$url = 'https://example.com/test-list-url';
		$repoId = $this->createTestRepo( $url );
		$registry = $this->newRegistry();

		$registry->addRefEntry( $repoId->toInt(), 'main' );

		$refs = $registry->listRefsForRepo( $url );
		
		$this->assertGreaterThanOrEqual( 1, count( $refs ) );
	}

	/**
	 */
	public function testListRefsForRepo_WithContentRepoObject_ReturnsAllRefs(): void {
		$repo = $this->createTestRepoObject();
		$registry = $this->newRegistry();

		$registry->addRefEntry( $repo->id()->toInt(), 'main' );

		$refs = $registry->listRefsForRepo( $repo );
		
		$this->assertGreaterThanOrEqual( 1, count( $refs ) );
	}

	/**
	 */
	public function testListRefsForRepo_OrderedBySourceRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		// Insert in non-alphabetical order
		$registry->addRefEntry( $repoId->toInt(), 'zebra' );
		$registry->addRefEntry( $repoId->toInt(), 'alpha' );
		$registry->addRefEntry( $repoId->toInt(), 'main' );

		$refs = $registry->listRefsForRepo( $repoId );
		
		$this->assertGreaterThanOrEqual( 3, count( $refs ) );
		
		// Extract just our test refs and verify alphabetical ordering
		$testRefs = array_filter( $refs, function( $ref ) {
			return in_array( $ref->sourceRef(), [ 'alpha', 'main', 'zebra' ] );
		} );
		$refNames = array_map( fn( $ref ) => $ref->sourceRef(), $testRefs );
		$sortedNames = $refNames;
		sort( $sortedNames );
		
		$this->assertSame( $sortedNames, array_values( $refNames ) );
	}

	/**
	 */
	public function testDeleteRef_WithIntId_RemovesRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$this->assertNotNull( $registry->getRefById( $refId ) );

		$registry->deleteRef( $refId->toInt() );
		
		$this->assertNull( $registry->getRefById( $refId ) );
	}

	/**
	 */
	public function testDeleteRef_WithContentRefId_RemovesRef(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$this->assertNotNull( $registry->getRefById( $refId ) );

		$registry->deleteRef( $refId );
		
		$this->assertNull( $registry->getRefById( $refId ) );
	}

	/**
	 */
	public function testDeleteRef_RemovesFromLookup(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$this->assertNotNull( $registry->getRefIdByRepoAndRef( $repoId, 'main' ) );

		$registry->deleteRef( $refId );
		
		$this->assertNull( $registry->getRefIdByRepoAndRef( $repoId, 'main' ) );
	}

	/**
	 */
	public function testGetWorktreePath_WithIntRepoId_ReturnsPath(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$registry->addRefEntry( $repoId->toInt(), 'main', [
			'worktree_path' => '/var/git/worktree/main'
		] );

		$path = $registry->getWorktreePath( $repoId->toInt(), 'main' );
		
		$this->assertSame( '/var/git/worktree/main', $path );
	}

	/**
	 */
	public function testGetWorktreePath_WithContentRepoId_ReturnsPath(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$registry->addRefEntry( $repoId->toInt(), 'develop', [
			'worktree_path' => '/var/git/worktree/develop'
		] );

		$path = $registry->getWorktreePath( $repoId, 'develop' );
		
		$this->assertSame( '/var/git/worktree/develop', $path );
	}

	/**
	 */
	public function testGetWorktreePath_WithUrlString_ReturnsPath(): void {
		$url = 'https://example.com/test-worktree-url';
		$repoId = $this->createTestRepo( $url );
		$registry = $this->newRegistry();

		$registry->addRefEntry( $repoId->toInt(), 'main', [
			'worktree_path' => '/var/git/worktree/url-test'
		] );

		$path = $registry->getWorktreePath( $url, 'main' );
		
		$this->assertSame( '/var/git/worktree/url-test', $path );
	}

	/**
	 * Test that multiple refs for the same repo are independent
	 */
	public function testMultipleRefsPerRepo_AreIndependent(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$mainId = $registry->addRefEntry( $repoId->toInt(), 'main', [
			'last_commit' => 'main_commit',
		] );
		$devId = $registry->addRefEntry( $repoId->toInt(), 'develop', [
			'last_commit' => 'dev_commit',
		] );
		
		$this->assertNotSame( $mainId->toInt(), $devId->toInt() );
		
		$mainRef = $registry->getRefById( $mainId );
		$devRef = $registry->getRefById( $devId );
		
		$this->assertSame( 'main_commit', $mainRef->lastCommit() );
		$this->assertSame( 'dev_commit', $devRef->lastCommit() );
		
		// Updating one shouldn't affect the other
		$registry->updateRefEntry( $mainId, [ 'last_commit' => 'main_updated' ] );
		
		$mainUpdated = $registry->getRefById( $mainId );
		$devCheck = $registry->getRefById( $devId );
		
		$this->assertSame( 'main_updated', $mainUpdated->lastCommit() );
		$this->assertSame( 'dev_commit', $devCheck->lastCommit() );
	}

	/**
	 * Test that refs in different repos are independent
	 */
	public function testRefsInDifferentRepos_AreIndependent(): void {
		$repoId1 = $this->createTestRepo( 'https://example.com/repo1' );
		$repoId2 = $this->createTestRepo( 'https://example.com/repo2' );
		$registry = $this->newRegistry();

		// Both repos have a 'main' ref
		$ref1Id = $registry->addRefEntry( $repoId1->toInt(), 'main', [
			'last_commit' => 'repo1_commit',
		] );
		$ref2Id = $registry->addRefEntry( $repoId2->toInt(), 'main', [
			'last_commit' => 'repo2_commit',
		] );
		
		$this->assertNotSame( $ref1Id->toInt(), $ref2Id->toInt() );
		
		$ref1 = $registry->getRefById( $ref1Id );
		$ref2 = $registry->getRefById( $ref2Id );
		
		$this->assertSame( 'repo1_commit', $ref1->lastCommit() );
		$this->assertSame( 'repo2_commit', $ref2->lastCommit() );
		$this->assertSame( $repoId1->toInt(), $ref1->repoId()->toInt() );
		$this->assertSame( $repoId2->toInt(), $ref2->repoId()->toInt() );
	}

	/**
	 * Test timestamps are set correctly on creation
	 */
	public function testTimestamps_SetOnCreation(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$beforeTime = wfTimestampNow();
		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$afterTime = wfTimestampNow();
		
		$ref = $registry->getRefById( $refId );
		$this->assertNotNull( $ref );
		$this->assertNotNull( $ref->createdAt() );
		$this->assertNotNull( $ref->updatedAt() );
		
		// Timestamps should be between before and after
		$this->assertGreaterThanOrEqual( (int)$beforeTime, $ref->createdAt() );
		$this->assertLessThanOrEqual( (int)$afterTime, $ref->createdAt() );
	}

	/**
	 * Test that manifest_hash and manifest_last_parsed can be set and updated
	 */
	public function testManifestMetadata_CanBeSetAndUpdated(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$parsedTime = (int)wfTimestampNow();
		
		$refId = $registry->addRefEntry( $repoId->toInt(), 'main', [
			'manifest_hash' => 'initial_hash',
			'manifest_last_parsed' => $parsedTime,
		] );
		$ref = $registry->getRefById( $refId );
		$this->assertSame( 'initial_hash', $ref->manifestHash() );
		$this->assertSame( $parsedTime, $ref->manifestLastParsed() );
		
		$newParsedTime = $parsedTime + 3600;
		$registry->updateRefEntry( $refId, [
			'manifest_hash' => 'updated_hash',
			'manifest_last_parsed' => $newParsedTime,
		] );
		$refUpdated = $registry->getRefById( $refId );
		$this->assertSame( 'updated_hash', $refUpdated->manifestHash() );
		$this->assertSame( $newParsedTime, $refUpdated->manifestLastParsed() );
	}

	/**
	 * Test worktree_path can be set and updated
	 */
	public function testWorktreePath_CanBeSetAndUpdated(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main' );
		$ref = $registry->getRefById( $refId );
		$this->assertNull( $ref->worktreePath() );
		
		$registry->updateRefEntry( $refId, [ 'worktree_path' => '/new/worktree/path' ] );
		$refUpdated = $registry->getRefById( $refId );
		$this->assertSame( '/new/worktree/path', $refUpdated->worktreePath() );
	}

	/**
	 * Test last_commit can be set and updated
	 */
	public function testLastCommit_CanBeSetAndUpdated(): void {
		$repoId = $this->createTestRepo();
		$registry = $this->newRegistry();

		$refId = $registry->addRefEntry( $repoId->toInt(), 'main', [
			'last_commit' => 'commit_abc123',
		] );
		$ref = $registry->getRefById( $refId );
		$this->assertSame( 'commit_abc123', $ref->lastCommit() );
		
		$registry->updateRefEntry( $refId, [ 'last_commit' => 'commit_def456' ] );
		$refUpdated = $registry->getRefById( $refId );
		$this->assertSame( 'commit_def456', $refUpdated->lastCommit() );
	}
}
