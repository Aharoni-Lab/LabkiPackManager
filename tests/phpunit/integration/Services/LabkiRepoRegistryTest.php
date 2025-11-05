<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for LabkiRepoRegistry
 *
 * Tests the repository-level registry service for the labki_content_repo table.
 * These tests use the actual MediaWiki database.
 *
 * @covers \LabkiPackManager\Services\LabkiRepoRegistry
 * @group Database
 */
class LabkiRepoRegistryTest extends MediaWikiIntegrationTestCase {

	private function newRegistry(): LabkiRepoRegistry {
		return new LabkiRepoRegistry();
	}

	public function testAddRepoEntry_CreatesNewRepo(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-repo-1';

		$id = $registry->addRepoEntry( $url );
		
		$this->assertInstanceOf( ContentRepoId::class, $id );
		$this->assertGreaterThan( 0, $id->toInt() );
	}

	/**
	 */
	public function testAddRepoEntry_WithDefaultFields_SetsDefaults(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-defaults';

		$id = $registry->addRepoEntry( $url );
		$repo = $registry->getRepo( $id );
		
		$this->assertNotNull( $repo );
		$this->assertSame( $url, $repo->url() );
		$this->assertSame( 'main', $repo->defaultRef() );
		$this->assertNull( $repo->barePath() );
		$this->assertNull( $repo->lastFetched() );
		$this->assertNotNull( $repo->createdAt() );
		$this->assertNotNull( $repo->updatedAt() );
	}

	/**
	 */
	public function testAddRepoEntry_WithExtraFields_OverridesDefaults(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-extra-fields';

		$id = $registry->addRepoEntry( $url, [
			'default_ref' => 'develop',
			'bare_path' => '/var/git/repo.git',
		] );
		
		$repo = $registry->getRepo( $id );
		
		$this->assertNotNull( $repo );
		$this->assertSame( 'develop', $repo->defaultRef() );
		$this->assertSame( '/var/git/repo.git', $repo->barePath() );
	}

	/**
	 */
	public function testGetRepoId_WithExistingUrl_ReturnsId(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-get-id';

		$id = $registry->addRepoEntry( $url );
		$fetchedId = $registry->getRepoId( $url );
		
		$this->assertNotNull( $fetchedId );
		$this->assertSame( $id->toInt(), $fetchedId->toInt() );
	}

	/**
	 */
	public function testGetRepoId_WithNonExistentUrl_ReturnsNull(): void {
		$registry = $this->newRegistry();
		
		$result = $registry->getRepoId( 'https://example.com/nonexistent' );
		
		$this->assertNull( $result );
	}

	/**
	 */
	public function testGetRepo_WithIntId_ReturnsCompleteRepoObject(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-get-int';

		$id = $registry->addRepoEntry( $url );
		$repo = $registry->getRepo( $id->toInt() );
		
		$this->assertNotNull( $repo );
		$this->assertInstanceOf( ContentRepo::class, $repo );
		$this->assertSame( $url, $repo->url() );
		$this->assertSame( 'main', $repo->defaultRef() );
		$this->assertSame( $id->toInt(), $repo->id()->toInt() );
	}

	/**
	 */
	public function testGetRepo_WithContentRepoId_ReturnsCompleteRepoObject(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-get-object';

		$id = $registry->addRepoEntry( $url );
		$repo = $registry->getRepo( $id );
		
		$this->assertNotNull( $repo );
		$this->assertInstanceOf( ContentRepo::class, $repo );
		$this->assertSame( $url, $repo->url() );
		$this->assertSame( 'main', $repo->defaultRef() );
		$this->assertTrue( $id->equals( $repo->id() ) );
	}

	/**
	 */
	public function testGetRepo_WithUrlString_ReturnsCompleteRepoObject(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-get-url';

		$id = $registry->addRepoEntry( $url );
		$repo = $registry->getRepo( $url );
		
		$this->assertNotNull( $repo );
		$this->assertInstanceOf( ContentRepo::class, $repo );
		$this->assertSame( $url, $repo->url() );
		$this->assertSame( 'main', $repo->defaultRef() );
		$this->assertSame( $id->toInt(), $repo->id()->toInt() );
	}

	/**
	 */
	public function testGetRepo_WithIntId_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();
		
		$result = $registry->getRepo( 999999 );
		
		$this->assertNull( $result );
	}

	/**
	 */
	public function testGetRepo_WithContentRepoId_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();
		
		$result = $registry->getRepo( new ContentRepoId( 999999 ) );
		
		$this->assertNull( $result );
	}

	/**
	 */
	public function testGetRepo_WithUrlString_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();
		
		$result = $registry->getRepo( 'https://example.com/nonexistent-url' );
		
		$this->assertNull( $result );
	}

	/**
	 */
	public function testUpdateRepoEntry_WithIntId_UpdatesFields(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-update-int';
		
		$id = $registry->addRepoEntry( $url );
		$before = $registry->getRepo( $id );
		$this->assertNotNull( $before );

		$registry->updateRepoEntry( $id->toInt(), [
			'default_ref' => 'staging',
			'bare_path' => '/updated/path',
		] );
		
		$after = $registry->getRepo( $id );
		$this->assertNotNull( $after );
		$this->assertSame( 'staging', $after->defaultRef() );
		$this->assertSame( '/updated/path', $after->barePath() );
	}

	/**
	 */
	public function testUpdateRepoEntry_WithContentRepoId_UpdatesFields(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-update-object';
		
		$id = $registry->addRepoEntry( $url );

		$registry->updateRepoEntry( $id, [
			'default_ref' => 'production',
		] );
		
		$after = $registry->getRepo( $id );
		$this->assertNotNull( $after );
		$this->assertSame( 'production', $after->defaultRef() );
	}

	/**
	 */
	public function testUpdateRepoEntry_AutomaticallyUpdatesTimestamp(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-timestamp';
		
		$id = $registry->addRepoEntry( $url );
		$before = $registry->getRepo( $id );
		$this->assertNotNull( $before );
		$beforeUpdated = $before->updatedAt();

		// Small delay to ensure timestamp changes
		usleep( 10000 ); // 10ms

		$registry->updateRepoEntry( $id, [ 'default_ref' => 'dev' ] );
		
		$after = $registry->getRepo( $id );
		$this->assertNotNull( $after );
		$this->assertNotNull( $after->updatedAt() );
		
		if ( $beforeUpdated !== null ) {
			$this->assertGreaterThanOrEqual( $beforeUpdated, $after->updatedAt() );
		}
	}

	/**
	 */
	public function testUpdateRepoEntry_WithEmptyFields_DoesNothing(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-update-empty';
		
		$id = $registry->addRepoEntry( $url );
		$before = $registry->getRepo( $id );
		
		// Should not throw an exception
		$registry->updateRepoEntry( $id, [] );
		
		$after = $registry->getRepo( $id );
		$this->assertNotNull( $after );
		$this->assertSame( $before->defaultRef(), $after->defaultRef() );
	}

	/**
	 */
	public function testEnsureRepoEntry_WhenNew_CreatesRepo(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-ensure-new';
		
		$id = $registry->ensureRepoEntry( $url );
		
		$this->assertInstanceOf( ContentRepoId::class, $id );
		$this->assertNotNull( $registry->getRepoId( $url ) );
		
		$repo = $registry->getRepo( $id );
		$this->assertNotNull( $repo );
		$this->assertSame( $url, $repo->url() );
	}

	/**
	 */
	public function testEnsureRepoEntry_WhenExists_ReturnsExistingId(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-ensure-existing';
		
		$id1 = $registry->ensureRepoEntry( $url );
		$id2 = $registry->ensureRepoEntry( $url );
		
		$this->assertSame( $id1->toInt(), $id2->toInt() );
	}

	/**
	 */
	public function testEnsureRepoEntry_WhenExists_UpdatesFields(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-ensure-update';
		
		$id1 = $registry->ensureRepoEntry( $url, [ 'bare_path' => '/path/old' ] );
		$repo1 = $registry->getRepo( $id1 );
		$this->assertSame( '/path/old', $repo1->barePath() );

		$id2 = $registry->ensureRepoEntry( $url, [ 'bare_path' => '/path/new' ] );
		
		$this->assertSame( $id1->toInt(), $id2->toInt() );
		
		$repo2 = $registry->getRepo( $id2 );
		$this->assertNotNull( $repo2 );
		$this->assertSame( '/path/new', $repo2->barePath() );
	}

	/**
	 */
	public function testEnsureRepoEntry_IsIdempotent(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-idempotent';
		
		// Call multiple times
		$id1 = $registry->ensureRepoEntry( $url, [ 'default_ref' => 'main' ] );
		$id2 = $registry->ensureRepoEntry( $url, [ 'default_ref' => 'main' ] );
		$id3 = $registry->ensureRepoEntry( $url, [ 'default_ref' => 'main' ] );
		
		$this->assertSame( $id1->toInt(), $id2->toInt() );
		$this->assertSame( $id2->toInt(), $id3->toInt() );
	}

	/**
	 */
	public function testListRepos_ReturnsAllRepos(): void {
		$registry = $this->newRegistry();
		
		$idA = $registry->addRepoEntry( 'https://example.com/test-list-a' );
		$idB = $registry->addRepoEntry( 'https://example.com/test-list-b' );

		$list = $registry->listRepos();
		
		$this->assertIsArray( $list );
		$this->assertGreaterThanOrEqual( 2, count( $list ) );
		
		// Verify all entries are ContentRepo objects
		foreach ( $list as $repo ) {
			$this->assertInstanceOf( ContentRepo::class, $repo );
		}
		
		// Verify our repos are in the list
		$urls = array_map( fn( $repo ) => $repo->url(), $list );
		$this->assertContains( 'https://example.com/test-list-a', $urls );
		$this->assertContains( 'https://example.com/test-list-b', $urls );
	}

	/**
	 */
	public function testListRepos_OrderedById(): void {
		$registry = $this->newRegistry();
		
		$registry->addRepoEntry( 'https://example.com/test-order-1' );
		$registry->addRepoEntry( 'https://example.com/test-order-2' );
		$registry->addRepoEntry( 'https://example.com/test-order-3' );

		$list = $registry->listRepos();
		
		$this->assertGreaterThanOrEqual( 3, count( $list ) );
		
		// Verify ordering by ID
		$prevId = 0;
		foreach ( $list as $repo ) {
			$this->assertGreaterThan( $prevId, $repo->id()->toInt() );
			$prevId = $repo->id()->toInt();
		}
	}

	/**
	 */
	public function testDeleteRepo_WithIntId_RemovesRepo(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-delete-int';
		
		$id = $registry->addRepoEntry( $url );
		$this->assertNotNull( $registry->getRepo( $id ) );

		$registry->deleteRepo( $id->toInt() );
		
		$this->assertNull( $registry->getRepo( $id ) );
	}

	/**
	 */
	public function testDeleteRepo_WithContentRepoId_RemovesRepo(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-delete-object';
		
		$id = $registry->addRepoEntry( $url );
		$this->assertNotNull( $registry->getRepo( $id ) );

		$registry->deleteRepo( $id );
		
		$this->assertNull( $registry->getRepo( $id ) );
	}

	/**
	 */
	public function testDeleteRepo_RemovesFromUrlLookup(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-delete-url';
		
		$id = $registry->addRepoEntry( $url );
		$this->assertNotNull( $registry->getRepoId( $url ) );

		$registry->deleteRepo( $id );
		
		$this->assertNull( $registry->getRepoId( $url ) );
	}

	/**
	 * Test that different URLs create independent entries
	 */
	public function testMultipleRepos_AreIndependent(): void {
		$registry = $this->newRegistry();
		
		$id1 = $registry->addRepoEntry( 'https://example.com/repo1', [
			'default_ref' => 'main',
		] );
		$id2 = $registry->addRepoEntry( 'https://example.com/repo2', [
			'default_ref' => 'develop',
		] );
		
		$this->assertNotSame( $id1->toInt(), $id2->toInt() );
		
		$repo1 = $registry->getRepo( $id1 );
		$repo2 = $registry->getRepo( $id2 );
		
		$this->assertSame( 'main', $repo1->defaultRef() );
		$this->assertSame( 'develop', $repo2->defaultRef() );
		
		// Updating one shouldn't affect the other
		$registry->updateRepoEntry( $id1, [ 'default_ref' => 'staging' ] );
		
		$repo1Updated = $registry->getRepo( $id1 );
		$repo2Check = $registry->getRepo( $id2 );
		
		$this->assertSame( 'staging', $repo1Updated->defaultRef() );
		$this->assertSame( 'develop', $repo2Check->defaultRef() );
	}

	/**
	 * Test timestamps are set correctly on creation
	 */
	public function testTimestamps_SetOnCreation(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-timestamps';
		
		$beforeTime = wfTimestampNow();
		$id = $registry->addRepoEntry( $url );
		$afterTime = wfTimestampNow();
		
		$repo = $registry->getRepo( $id );
		$this->assertNotNull( $repo );
		$this->assertNotNull( $repo->createdAt() );
		$this->assertNotNull( $repo->updatedAt() );
		
		// Timestamps should be between before and after
		$this->assertGreaterThanOrEqual( (int)$beforeTime, $repo->createdAt() );
		$this->assertLessThanOrEqual( (int)$afterTime, $repo->createdAt() );
	}

	/**
	 * Test that bare_path can be set and updated
	 */
	public function testBarePath_CanBeSetAndUpdated(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-bare-path';
		
		$id = $registry->addRepoEntry( $url );
		$repo = $registry->getRepo( $id );
		$this->assertNull( $repo->barePath() );
		
		$registry->updateRepoEntry( $id, [ 'bare_path' => '/var/git/test.git' ] );
		$repoUpdated = $registry->getRepo( $id );
		$this->assertSame( '/var/git/test.git', $repoUpdated->barePath() );
	}

	/**
	 * Test that last_fetched can be set and updated
	 */
	public function testLastFetched_CanBeSetAndUpdated(): void {
		$registry = $this->newRegistry();
		$url = 'https://example.com/test-last-fetched';
		
		$timestamp = (int)wfTimestampNow();
		
		$id = $registry->addRepoEntry( $url, [ 'last_fetched' => $timestamp ] );
		$repo = $registry->getRepo( $id );
		$this->assertSame( $timestamp, $repo->lastFetched() );
		
		$newTimestamp = $timestamp + 3600;
		$registry->updateRepoEntry( $id, [ 'last_fetched' => $newTimestamp ] );
		$repoUpdated = $registry->getRepo( $id );
		$this->assertSame( $newTimestamp, $repoUpdated->lastFetched() );
	}
}
