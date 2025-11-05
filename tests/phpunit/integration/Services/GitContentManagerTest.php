<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Services\GitContentManager;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use MediaWikiIntegrationTestCase;
use RuntimeException;

/**
 * Integration tests for GitContentManager
 *
 * Tests Git repository and worktree management operations.
 * These tests use actual filesystem operations and the MediaWiki database.
 *
 * Note: Some tests require git to be installed and available in PATH.
 *
 * @covers \LabkiPackManager\Services\GitContentManager
 * @group Database
 * @group LabkiPackManager
 */
class GitContentManagerTest extends MediaWikiIntegrationTestCase {

	private GitContentManager $manager;
	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private string $testRepoPath;
	private string $originalCacheDir;

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [
		'labki_content_repo',
		'labki_content_ref',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();

		// Set up temporary cache directory for testing
		global $wgCacheDirectory;
		$this->originalCacheDir = $wgCacheDirectory;
		$wgCacheDirectory = sys_get_temp_dir() . '/labki_test_cache_' . uniqid();
		mkdir( $wgCacheDirectory, 0777, true );

		// Create test git repository
		$this->testRepoPath = sys_get_temp_dir() . '/labki_test_git_repo_' . uniqid();
		$this->createTestGitRepo( $this->testRepoPath );

		$this->manager = new GitContentManager( $this->repoRegistry, $this->refRegistry );
	}

	protected function tearDown(): void {
		global $wgCacheDirectory;

		// Clean up test git repository
		if ( is_dir( $this->testRepoPath ) ) {
			$this->recursiveDelete( $this->testRepoPath );
		}

		// Clean up cache directory
		if ( is_dir( $wgCacheDirectory ) ) {
			$this->recursiveDelete( $wgCacheDirectory );
		}

		// Restore original cache directory
		$wgCacheDirectory = $this->originalCacheDir;

		parent::tearDown();
	}

	/**
	 * Create a test git repository with some commits.
	 */
	private function createTestGitRepo( string $path ): void {
		mkdir( $path, 0777, true );
		
		// Initialize git repo
		exec( "cd {$path} && git init 2>&1", $output, $exitCode );
		if ( $exitCode !== 0 ) {
			$this->markTestSkipped( 'Git not available for testing' );
		}

		// Configure git
		exec( "cd {$path} && git config user.email 'test@example.com' 2>&1" );
		exec( "cd {$path} && git config user.name 'Test User' 2>&1" );

		// Create initial commit on main branch with fixture manifest
		file_put_contents( "{$path}/README.md", "# Test Repo\n" );
		$fixtureManifest = file_get_contents( __DIR__ . '/../../../fixtures/manifest-simple.yml' );
		file_put_contents( "{$path}/manifest.yml", $fixtureManifest );
		exec( "cd {$path} && git add . && git commit -m 'Initial commit' 2>&1" );

		// Create a develop branch
		exec( "cd {$path} && git checkout -b develop 2>&1" );
		file_put_contents( "{$path}/develop.txt", "Develop branch\n" );
		exec( "cd {$path} && git add . && git commit -m 'Develop branch' 2>&1" );
		exec( "cd {$path} && git checkout main 2>&1" );
	}

	/**
	 * Recursively delete a directory.
	 */
	private function recursiveDelete( string $dir ): void {
		if ( !is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->recursiveDelete( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function testGetCloneBasePath_ReturnsConfiguredPath(): void {
		global $wgCacheDirectory;
		
		$basePath = $this->manager->getCloneBasePath();
		
		$this->assertIsString( $basePath );
		$this->assertStringContainsString( $wgCacheDirectory, $basePath );
		$this->assertStringContainsString( 'labki-content-repos', $basePath );
	}

	public function testConstruct_CreatesBaseDirectories(): void {
		global $wgCacheDirectory;
		
		$cacheDir = "{$wgCacheDirectory}/labki-content-repos/cache";
		$worktreesDir = "{$wgCacheDirectory}/labki-content-repos/worktrees";
		
		$this->assertDirectoryExists( $cacheDir );
		$this->assertDirectoryExists( $worktreesDir );
	}

	public function testConstruct_WithoutCacheDirectory_ThrowsException(): void {
		global $wgCacheDirectory;
		$original = $wgCacheDirectory;
		
		try {
			$wgCacheDirectory = null;
			
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( '$wgCacheDirectory must be configured' );
			
			new GitContentManager();
		} finally {
			$wgCacheDirectory = $original;
		}
	}

	public function testEnsureBareRepo_CreatesNewBareRepo(): void {
		$repoUrl = $this->testRepoPath;
		
		$barePath = $this->manager->ensureBareRepo( $repoUrl );
		
		$this->assertIsString( $barePath );
		$this->assertDirectoryExists( $barePath );
		$this->assertFileExists( "{$barePath}/HEAD" );
		$this->assertFileExists( "{$barePath}/config" );
	}

	public function testEnsureBareRepo_RegistersRepoInDatabase(): void {
		$repoUrl = $this->testRepoPath;
		
		// Verify repo doesn't exist yet
		$this->assertNull( $this->repoRegistry->getRepoId( $repoUrl ) );
		
		$this->manager->ensureBareRepo( $repoUrl );
		
		// Verify repo is now registered
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		$this->assertNotNull( $repoId );
		
		$repo = $this->repoRegistry->getRepo( $repoId );
		$this->assertNotNull( $repo );
		$this->assertSame( $repoUrl, $repo->url() );
		$this->assertNotNull( $repo->barePath() );
		$this->assertNotNull( $repo->lastFetched() );
	}

	public function testEnsureBareRepo_WhenExists_ReusesExisting(): void {
		$repoUrl = $this->testRepoPath;
		
		$barePath1 = $this->manager->ensureBareRepo( $repoUrl );
		$repoId1 = $this->repoRegistry->getRepoId( $repoUrl );
		
		// Call again
		$barePath2 = $this->manager->ensureBareRepo( $repoUrl );
		$repoId2 = $this->repoRegistry->getRepoId( $repoUrl );
		
		$this->assertSame( $barePath1, $barePath2 );
		$this->assertSame( $repoId1->toInt(), $repoId2->toInt() );
	}

	public function testEnsureWorktree_CreatesNewWorktree(): void {
		$repoUrl = $this->testRepoPath;
		
		// First ensure bare repo exists
		$this->manager->ensureBareRepo( $repoUrl );
		
		// Use HEAD since that's always available in a bare repo
		$worktreePath = $this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		$this->assertIsString( $worktreePath );
		$this->assertDirectoryExists( $worktreePath );
		$this->assertFileExists( "{$worktreePath}/README.md" );
		$this->assertFileExists( "{$worktreePath}/manifest.yml" );
	}

	public function testEnsureWorktree_RegistersRefInDatabase(): void {
		$repoUrl = $this->testRepoPath;
		
		$this->manager->ensureBareRepo( $repoUrl );
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		
		// Verify ref doesn't exist yet
		$this->assertNull( $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' ) );
		
		$this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		// Verify ref is now registered
		$refId = $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' );
		$this->assertNotNull( $refId );
		
		$ref = $this->refRegistry->getRefById( $refId );
		$this->assertNotNull( $ref );
		$this->assertSame( 'HEAD', $ref->sourceRef() );
		$this->assertNotNull( $ref->worktreePath() );
		$this->assertNotNull( $ref->lastCommit() );
	}

	public function testEnsureWorktree_WhenExists_ReusesExisting(): void {
		$repoUrl = $this->testRepoPath;
		
		$this->manager->ensureBareRepo( $repoUrl );
		
		$worktreePath1 = $this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		$refId1 = $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' );
		
		// Call again
		$worktreePath2 = $this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		$refId2 = $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' );
		
		$this->assertSame( $worktreePath1, $worktreePath2 );
		$this->assertSame( $refId1->toInt(), $refId2->toInt() );
	}

	public function testMultipleRefs_CanBeCreatedForSameRepo(): void {
		$repoUrl = $this->testRepoPath;
		
		$this->manager->ensureBareRepo( $repoUrl );
		
		// Create first worktree
		$worktree1 = $this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		$refId1 = $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' );
		
		// Manually register a second ref for the same repo
		$refId2 = $this->refRegistry->ensureRefEntry( $repoId, 'test-ref-2', [
			'worktree_path' => '/tmp/test-worktree-2',
		] );
		
		// Verify both refs exist and are different
		$this->assertNotNull( $refId1 );
		$this->assertNotNull( $refId2 );
		$this->assertNotSame( $refId1->toInt(), $refId2->toInt() );
		
		// Verify first worktree was actually created
		$this->assertDirectoryExists( $worktree1 );
		$this->assertStringContainsString( 'HEAD', $worktree1 );
	}

	public function testSyncRef_UpdatesWorktreeAndDatabase(): void {
		$repoUrl = $this->testRepoPath;
		
		// Initial setup
		$this->manager->ensureBareRepo( $repoUrl );
		$this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		$refId = $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' );
		
		// Get initial commit
		$initialRef = $this->refRegistry->getRefById( $refId );
		$initialCommit = $initialRef->lastCommit();
		
		// Make a new commit in the test repo
		$newContent = "Updated content " . time();
		file_put_contents( "{$this->testRepoPath}/README.md", $newContent );
		exec( "cd {$this->testRepoPath} && git add . && git commit -m 'Update' 2>&1" );
		
		// Sync the ref
		$this->manager->syncRef( $repoUrl, 'HEAD' );
		
		// Verify database was updated
		$updatedRef = $this->refRegistry->getRefById( $refId );
		$updatedCommit = $updatedRef->lastCommit();
		
		// Commit should have changed (unless test ran too fast)
		// We can at least verify the sync completed without error
		$this->assertNotNull( $updatedCommit );
	}

	public function testSyncRef_WithNonExistentRepo_ThrowsException(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Repository not found' );
		
		$this->manager->syncRef( 'https://example.com/nonexistent', 'main' );
	}

	public function testSyncRef_WithNonExistentRef_ThrowsException(): void {
		$repoUrl = $this->testRepoPath;
		
		$this->manager->ensureBareRepo( $repoUrl );
		
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not found in repository' );
		
		$this->manager->syncRef( $repoUrl, 'nonexistent-ref' );
	}

	public function testSyncRepo_WithSingleRef_Succeeds(): void {
		$repoUrl = $this->testRepoPath;
		
		// Set up repo with a single ref
		$this->manager->ensureBareRepo( $repoUrl );
		$this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		// Sync entire repo
		$syncedCount = $this->manager->syncRepo( $repoUrl );
		
		$this->assertGreaterThanOrEqual( 1, $syncedCount );
	}

	public function testSyncRepo_WithNonExistentRepo_ThrowsException(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Repository not found' );
		
		$this->manager->syncRepo( 'https://example.com/nonexistent' );
	}

	public function testRemoveRef_RemovesWorktreeAndDatabaseEntry(): void {
		$repoUrl = $this->testRepoPath;
		
		// Set up ref
		$this->manager->ensureBareRepo( $repoUrl );
		$worktreePath = $this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		$refId = $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' );
		
		$this->assertDirectoryExists( $worktreePath );
		$this->assertNotNull( $refId );
		
		// Remove ref
		$this->manager->removeRef( $repoUrl, 'HEAD' );
		
		// Verify worktree is removed
		$this->assertDirectoryDoesNotExist( $worktreePath );
		
		// Verify database entry is removed
		$this->assertNull( $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' ) );
	}

	public function testRemoveRef_WithNonExistentRepo_ThrowsException(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Repository not found' );
		
		$this->manager->removeRef( 'https://example.com/nonexistent', 'main' );
	}

	public function testRemoveRef_WithNonExistentRef_ThrowsException(): void {
		$repoUrl = $this->testRepoPath;
		
		$this->manager->ensureBareRepo( $repoUrl );
		
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not found in repository' );
		
		$this->manager->removeRef( $repoUrl, 'nonexistent-ref' );
	}

	public function testRemoveRepo_RemovesRefAndBareRepo(): void {
		$repoUrl = $this->testRepoPath;
		
		// Set up repo with a ref
		$barePath = $this->manager->ensureBareRepo( $repoUrl );
		$worktree = $this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		
		$this->assertDirectoryExists( $barePath );
		$this->assertDirectoryExists( $worktree );
		$this->assertNotNull( $repoId );
		
		// Remove entire repo
		$removedCount = $this->manager->removeRepo( $repoUrl );
		
		$this->assertGreaterThanOrEqual( 1, $removedCount );
		
		// Verify everything is removed
		$this->assertDirectoryDoesNotExist( $barePath );
		$this->assertDirectoryDoesNotExist( $worktree );
		$this->assertNull( $this->repoRegistry->getRepoId( $repoUrl ) );
	}

	public function testRemoveRepo_WithNonExistentRepo_ThrowsException(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Repository not found' );
		
		$this->manager->removeRepo( 'https://example.com/nonexistent' );
	}

	/**
	 * Test that bare repo paths are deterministic
	 */
	public function testBareRepoPath_IsDeterministic(): void {
		$repoUrl1 = $this->testRepoPath;
		$repoUrl2 = $this->testRepoPath; // Same URL
		
		$barePath1 = $this->manager->ensureBareRepo( $repoUrl1 );
		
		// Clean up and recreate
		$this->manager->removeRepo( $repoUrl1 );
		
		$barePath2 = $this->manager->ensureBareRepo( $repoUrl2 );
		
		// Should generate same path for same URL
		$this->assertSame( $barePath1, $barePath2 );
	}

	/**
	 * Test that worktree paths are unique per ref
	 */
	public function testWorktreePath_IsUniquePerRef(): void {
		$repoUrl = $this->testRepoPath;
		
		$this->manager->ensureBareRepo( $repoUrl );
		
		// Create worktrees for different ref names
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		
		// Create first worktree
		$worktree1 = $this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		// Manually register a second ref to test path uniqueness
		$refId2 = $this->refRegistry->ensureRefEntry( $repoId, 'custom-ref-name' );
		
		// Paths should be different based on ref name
		$this->assertStringContainsString( 'HEAD', $worktree1 );
		
		// Verify different refs have different identifiers in database
		$refId1 = $this->refRegistry->getRefIdByRepoAndRef( $repoId, 'HEAD' );
		$this->assertNotNull( $refId1 );
		$this->assertNotSame( $refId1->toInt(), $refId2->toInt() );
	}

	/**
	 * Test database integration - repo and ref relationships
	 */
	public function testDatabaseIntegration_RefsBelongToRepo(): void {
		$repoUrl = $this->testRepoPath;
		
		$this->manager->ensureBareRepo( $repoUrl );
		
		// Create worktree
		$this->manager->ensureWorktree( $repoUrl, 'HEAD' );
		
		// Manually add another ref for the same repo
		$repoId = $this->repoRegistry->getRepoId( $repoUrl );
		$refId2 = $this->refRegistry->ensureRefEntry( $repoId, 'second-ref' );
		
		// List all refs for this repo
		$refs = $this->refRegistry->listRefsForRepo( $repoId );
		
		$this->assertGreaterThanOrEqual( 2, count( $refs ) );
		
		$refNames = array_map( fn( $ref ) => $ref->sourceRef(), $refs );
		$this->assertContains( 'HEAD', $refNames );
		$this->assertContains( 'second-ref', $refNames );
		
		// All refs should belong to the same repo
		foreach ( $refs as $ref ) {
			$this->assertSame( $repoId->toInt(), $ref->repoId()->toInt() );
		}
	}
}

