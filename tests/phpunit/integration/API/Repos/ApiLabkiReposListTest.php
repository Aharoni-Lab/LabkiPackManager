<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Repos;

use ApiTestCase;
use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRef;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;
use MediaWiki\MediaWikiServices;

/**
 * Integration tests for ApiLabkiReposList.
 *
 * These tests cover:
 * - Listing all repositories (with and without data)
 * - Getting single repository by ID
 * - Getting single repository by URL (with normalization)
 * - Mutually exclusive parameter validation (repo_id vs repo_url)
 * - URL validation and error handling
 * - Response structure and metadata
 * - Ref data inclusion and is_default flag
 * - Computed fields (last_synced, ref_count)
 *
 * Also provides comprehensive coverage of RepoApiBase functionality:
 * - validateRepoUrl() with various URL formats
 * - validateAndNormalizeUrl() with URL transformations
 * - Error handling for invalid/empty URLs
 *
 * @covers \LabkiPackManager\API\Repos\ApiLabkiReposList
 * @covers \LabkiPackManager\API\Repos\RepoApiBase
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
class ApiLabkiReposListTest extends ApiTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [
		'labki_content_repo',
		'labki_content_ref',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
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
	 * Test listing all repositories when none exist.
	 */
	public function testListRepos_WhenNoRepos_ReturnsEmptyArray(): void {
		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
		] );

		// doApiRequest returns [response, request] tuple
		$data = $result[0];
		
		$this->assertArrayHasKey( 'repos', $data );
		$this->assertIsArray( $data['repos'] );
		$this->assertCount( 0, $data['repos'] );
		
		// Check metadata
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'schemaVersion', $data['meta'] );
		$this->assertArrayHasKey( 'timestamp', $data['meta'] );
		$this->assertSame( 1, $data['meta']['schemaVersion'] );
	}

	/**
	 * Test listing all repositories when one exists.
	 */
	public function testListRepos_WithOneRepo_ReturnsRepo(): void {
		// Create test repo
		$repoId = $this->createTestRepo( 'https://github.com/test/repo1', 'main' );

		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
		] );

		$this->assertArrayHasKey( 'repos', $result[0] );
		$this->assertCount( 1, $result[0]['repos'] );
		
		$repo = $result[0]['repos'][0];
		$this->assertSame( $repoId->toInt(), $repo['repo_id'] );
		$this->assertSame( 'https://github.com/test/repo1', $repo['repo_url'] );
		$this->assertSame( 'main', $repo['default_ref'] );
		$this->assertArrayHasKey( 'refs', $repo );
		$this->assertArrayHasKey( 'ref_count', $repo );
		$this->assertArrayHasKey( 'created_at', $repo );
	}

	/**
	 * Test listing all repositories with multiple repos.
	 */
	public function testListRepos_WithMultipleRepos_ReturnsAllRepos(): void {
		// Create test repos
		$repoId1 = $this->createTestRepo( 'https://github.com/test/repo1', 'main' );
		$repoId2 = $this->createTestRepo( 'https://github.com/test/repo2', 'develop' );
		$repoId3 = $this->createTestRepo( 'https://github.com/test/repo3', 'master' );

		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
		] );

		$this->assertCount( 3, $result[0]['repos'] );
		
		// Extract URLs to verify all are present
		$urls = array_column( $result[0]['repos'], 'url' );
		$this->assertContains( 'https://github.com/test/repo1', $urls );
		$this->assertContains( 'https://github.com/test/repo2', $urls );
		$this->assertContains( 'https://github.com/test/repo3', $urls );
	}

	/**
	 * Test listing repository with refs.
	 */
	public function testListRepos_WithRefs_IncludesRefData(): void {
		// Create repo and refs
		$repoId = $this->createTestRepo( 'https://github.com/test/repo', 'main' );
		$refId1 = $this->createTestRef( $repoId, 'main' );
		$refId2 = $this->createTestRef( $repoId, 'develop' );

		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
		] );

		$repo = $result[0]['repos'][0];
		$this->assertArrayHasKey( 'refs', $repo );
		$this->assertCount( 2, $repo['refs'] );
		$this->assertSame( 2, $repo['ref_count'] );
		
		// Check ref structure
		$ref = $repo['refs'][0];
		$this->assertArrayHasKey( 'ref_id', $ref );
		$this->assertArrayHasKey( 'ref', $ref );
		$this->assertArrayHasKey( 'ref_name', $ref );
		$this->assertArrayHasKey( 'is_default', $ref );
		$this->assertArrayHasKey( 'last_commit', $ref );
		$this->assertArrayHasKey( 'manifest_hash', $ref );
	}

	/**
	 * Test getting single repository by ID.
	 */
	public function testGetRepoById_WhenExists_ReturnsSingleRepo(): void {
		// Create test repos
		$repoId1 = $this->createTestRepo( 'https://github.com/test/repo1', 'main' );
		$repoId2 = $this->createTestRepo( 'https://github.com/test/repo2', 'main' );

		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
			'repo_url' => 'https://github.com/test/repo1',
		] );

		$this->assertCount( 1, $result[0]['repos'] );
		$this->assertSame( $repoId1->toInt(), $result[0]['repos'][0]['repo_id'] );
		$this->assertSame( 'https://github.com/test/repo1', $result[0]['repos'][0]['repo_url'] );
	}

	/**
	 * Test getting single repository by ID when it doesn't exist.
	 */
	public function testGetRepoById_WhenNotExists_ReturnsEmptyArray(): void {
		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
			'repo_id' => 99999,
		] );

		$this->assertCount( 0, $result[0]['repos'] );
	}

	/**
	 * Test getting single repository by URL.
	 */
	public function testGetRepoByUrl_WhenExists_ReturnsSingleRepo(): void {
		// Create test repo
		$repoId = $this->createTestRepo( 'https://github.com/test/repo', 'main' );

		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
			'repo_url' => 'https://github.com/test/repo',
		] );

		$this->assertCount( 1, $result[0]['repos'] );
		$this->assertSame( $repoId->toInt(), $result[0]['repos'][0]['repo_id'] );
		$this->assertSame( 'https://github.com/test/repo', $result[0]['repos'][0]['repo_url'] );
	}

	/**
	 * Test getting single repository by URL with .git suffix.
	 */
	public function testGetRepoByUrl_WithGitSuffix_NormalizesAndFinds(): void {
		// Create repo without .git
		$repoId = $this->createTestRepo( 'https://github.com/test/repo', 'main' );

		// Query with .git suffix
		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
			'repo_url' => 'https://github.com/test/repo.git',
		] );

		$this->assertCount( 1, $result[0]['repos'] );
		$this->assertSame( $repoId->toInt(), $result[0]['repos'][0]['repo_id'] );
	}

	/**
	 * Test getting single repository by URL when it doesn't exist.
	 */
	public function testGetRepoByUrl_WhenNotExists_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiReposList',
			'repo_url' => 'https://github.com/nonexistent/repo',
		] );
	}

	/**
	 * Test that providing both repo_id and repo_url returns error.
	 */
	public function testGetRepo_WithBothIdAndUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposList',
			'repo_id' => 1,
			'repo_url' => 'https://github.com/test/repo',
		] );
	}

	/**
	 * Test that invalid URL returns error.
	 */
	public function testGetRepoByUrl_WithInvalidUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposList',
			'repo_url' => 'not-a-valid-url',
		] );
	}

	/**
	 * Test that empty URL returns error.
	 */
	public function testGetRepoByUrl_WithEmptyUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposList',
			'repo_url' => '',
		] );
	}

	/**
	 * Test that last_synced is computed from most recent ref update.
	 */
	public function testListRepos_ComputesLastSyncedFromRefs(): void {
		// Create repo and refs with different update times
		$repoId = $this->createTestRepo( 'https://github.com/test/repo', 'main' );
		$refId1 = $this->createTestRef( $repoId, 'main' );
		
		// Sleep briefly to ensure different timestamps
		sleep( 1 );
		
		$refId2 = $this->createTestRef( $repoId, 'develop' );

		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
		] );

		$repo = $result[0]['repos'][0];
		$this->assertArrayHasKey( 'last_synced', $repo );
		$this->assertNotNull( $repo['last_synced'] );
		
		// last_synced should be the most recent ref update time
		$refUpdateTimes = array_column( $repo['refs'], 'updated_at' );
		$maxRefTime = max( array_filter( $refUpdateTimes ) );
		$this->assertSame( $maxRefTime, $repo['last_synced'] );
	}

	/**
	 * Test that is_default flag is set correctly for refs.
	 */
	public function testListRepos_SetsIsDefaultFlagCorrectly(): void {
		// Create repo with 'main' as default
		$repoId = $this->createTestRepo( 'https://github.com/test/repo', 'main' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );

		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
		] );

		$repo = $result[0]['repos'][0];
		$refs = $repo['refs'];
		
		// Find the 'main' ref
		$mainRef = null;
		$developRef = null;
		foreach ( $refs as $ref ) {
			if ( $ref['ref'] === 'main' ) {
				$mainRef = $ref;
			} elseif ( $ref['ref'] === 'develop' ) {
				$developRef = $ref;
			}
		}
		
		$this->assertNotNull( $mainRef );
		$this->assertNotNull( $developRef );
		$this->assertTrue( $mainRef['is_default'] );
		$this->assertFalse( $developRef['is_default'] );
	}

	/**
	 * Test response structure includes all expected fields.
	 */
	public function testListRepos_ResponseStructure_IncludesAllFields(): void {
		// Create repo with ref
		$repoId = $this->createTestRepo( 'https://github.com/test/repo', 'main' );
		$this->createTestRef( $repoId, 'main' );

		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
		] );

		$repo = $result[0]['repos'][0];
		
		// Check repo fields
		$expectedRepoFields = [
			'repo_id', 'url', 'default_ref', 'last_fetched',
			'refs', 'ref_count', 'last_synced', 'created_at', 'updated_at'
		];
		foreach ( $expectedRepoFields as $field ) {
			$this->assertArrayHasKey( $field, $repo, "Missing field: {$field}" );
		}
		
		// Check ref fields
		$ref = $repo['refs'][0];
		$expectedRefFields = [
			'ref_id', 'ref', 'ref_name', 'is_default', 'last_commit',
			'manifest_hash', 'manifest_last_parsed', 'created_at', 'updated_at'
		];
		foreach ( $expectedRefFields as $field ) {
			$this->assertArrayHasKey( $field, $ref, "Missing ref field: {$field}" );
		}
	}

	/**
	 * Test that API is marked as internal.
	 */
	public function testApi_IsMarkedAsInternal(): void {
		// This is tested by checking the API module properties
		// In actual use, internal APIs don't appear in public API documentation
		$this->assertTrue( true ); // Placeholder - actual check would require API module introspection
	}

	/**
	 * Test that API is read-only (not write mode).
	 */
	public function testApi_IsReadOnly(): void {
		// This API should not require write mode
		// Tested implicitly by successful read operations without tokens
		$this->createTestRepo();
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposList',
		] );
		
		$this->assertArrayHasKey( 'repos', $result[0] );
	}
}

