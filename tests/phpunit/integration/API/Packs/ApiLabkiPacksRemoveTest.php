<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Packs;

use ApiTestCase;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Domain\PackId;
use MediaWiki\MediaWikiServices;

/**
 * Integration tests for ApiLabkiPacksRemove.
 *
 * These tests verify that the API endpoint:
 * - Creates operation records in LabkiOperationRegistry
 * - Queues LabkiPackRemoveJob with correct parameters
 * - Returns appropriate responses for various scenarios
 * - Validates input parameters correctly (repo, ref, pack_ids)
 * - Validates pack dependencies before removal
 * - Handles delete_pages parameter correctly
 *
 * @covers \LabkiPackManager\API\Packs\ApiLabkiPacksRemove
 * @covers \LabkiPackManager\API\Packs\PackApiBase
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
class ApiLabkiPacksRemoveTest extends ApiTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private LabkiPackRegistry $packRegistry;
	private LabkiPageRegistry $pageRegistry;
	private LabkiOperationRegistry $operationRegistry;
	private string $testWorktreePath;

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [
		'labki_content_repo',
		'labki_content_ref',
		'labki_pack',
		'labki_page',
		'labki_operations',
		'job',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
		$this->pageRegistry = new LabkiPageRegistry();
		$this->operationRegistry = new LabkiOperationRegistry();
		
		// Grant permissions for testing
		$this->setGroupPermissions( 'user', 'labkipackmanager-manage', true );

		// Create temporary directory for test worktree
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_api_remove_test_' . uniqid();
		mkdir( $this->testWorktreePath, 0777, true );
	}

	protected function tearDown(): void {
		// Clean up test worktree
		if ( is_dir( $this->testWorktreePath ) ) {
			$this->recursiveDelete( $this->testWorktreePath );
		}
		
		parent::tearDown();
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

	/**
	 * Create a test manifest file in the worktree.
	 */
	private function createTestManifest( array $packs = [], array $pages = [] ): void {
		$yaml = "schema_version: 1\n";
		
		// Add packs
		if ( !empty( $packs ) ) {
			$yaml .= "packs:\n";
			foreach ( $packs as $packName => $packDef ) {
				$yaml .= "  {$packName}:\n";
				$yaml .= "    name: {$packName}\n";
				if ( isset( $packDef['version'] ) ) {
					$yaml .= "    version: \"{$packDef['version']}\"\n";
				}
				if ( isset( $packDef['depends_on'] ) && is_array( $packDef['depends_on'] ) ) {
					if ( empty( $packDef['depends_on'] ) ) {
						$yaml .= "    depends_on: []\n";
					} else {
						$yaml .= "    depends_on:\n";
						foreach ( $packDef['depends_on'] as $dep ) {
							$yaml .= "      - {$dep}\n";
						}
					}
				}
			}
		}
		
		// Add pages
		if ( !empty( $pages ) ) {
			$yaml .= "pages:\n";
			foreach ( $pages as $pageName => $pageDef ) {
				$yaml .= "  {$pageName}:\n";
				if ( isset( $pageDef['file'] ) ) {
					$yaml .= "    file: {$pageDef['file']}\n";
				}
			}
		}

		$manifestPath = $this->testWorktreePath . '/manifest.yml';
		file_put_contents( $manifestPath, $yaml );
	}

	/**
	 * Test successful pack removal with all parameters.
	 */
	public function testRemovePacks_WithAllParams_QueuesJobAndReturnsOperationId(): void {
		// Create test repo and ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.0.0', 'depends_on' => [] ] ],
			[ 'TestPage' => [ 'file' => 'pages/test.wiki' ] ]
		);

		// Install a pack first so we can remove it
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => json_encode( [ $packId->toInt() ] ),
			'delete_pages' => true,
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		// Check response structure
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		
		$this->assertArrayHasKey( 'operation_id', $data );
		$this->assertStringStartsWith( 'pack_remove_', $data['operation_id'] );
		
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $data['status'] );
		
		$this->assertArrayHasKey( 'message', $data );
		$this->assertStringContainsString( 'queued', strtolower( $data['message'] ) );
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertIsArray( $data['packs'] );
		$this->assertContains( 'TestPack', $data['packs'] );
		
		$this->assertArrayHasKey( 'delete_pages', $data );
		$this->assertTrue( $data['delete_pages'] );
		
		// Check metadata
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'schemaVersion', $data['meta'] );
		$this->assertSame( 1, $data['meta']['schemaVersion'] );
		
		// Verify operation was created in database
		$operationId = $data['operation_id'];
		$this->assertTrue( $this->operationRegistry->operationExists( new OperationId( $operationId ) ) );
		
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertSame( LabkiOperationRegistry::TYPE_PACK_REMOVE, $operation->type() );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $operation->status() );
		
		// Verify job was queued
		$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$this->assertGreaterThan( 0, $jobQueue->get( 'labkiPackRemove' )->getSize() );
	}

	/**
	 * Test removal fails without repo parameter.
	 */
	public function testRemovePacks_WithoutRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			// Missing repo_id and repo_url
			'ref' => 'main',
			'pack_ids' => json_encode( [ 1 ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails without ref parameter.
	 */
	public function testRemovePacks_WithoutRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			// Missing ref_id and ref
			'pack_ids' => json_encode( [ 1 ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails with both repo_id and repo_url.
	 */
	public function testRemovePacks_WithMultipleRepoIdentifiers_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'repo_url' => 'https://github.com/test/repo', // Both provided
			'ref' => 'main',
			'pack_ids' => json_encode( [ 1 ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails with both ref_id and ref.
	 */
	public function testRemovePacks_WithMultipleRefIdentifiers_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref_id' => $refId->toInt(),
			'ref' => 'main', // Both provided
			'pack_ids' => json_encode( [ 1 ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails with invalid pack_ids JSON.
	 */
	public function testRemovePacks_WithInvalidPackIdsJson_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => 'invalid json{',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails with empty pack_ids array.
	 */
	public function testRemovePacks_WithEmptyPackIds_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => json_encode( [] ), // Empty array
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails with invalid pack ID format (string instead of int).
	 */
	public function testRemovePacks_WithInvalidPackIdFormat_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => json_encode( [ 'not_an_int' ] ), // String instead of int
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails with non-existent repo.
	 */
	public function testRemovePacks_WithNonExistentRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => 99999, // Non-existent repo
			'ref' => 'main',
			'pack_ids' => json_encode( [ 1 ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails with non-existent ref.
	 */
	public function testRemovePacks_WithNonExistentRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'nonexistent', // Non-existent ref
			'pack_ids' => json_encode( [ 1 ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails with non-existent pack.
	 */
	public function testRemovePacks_WithNonExistentPack_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => json_encode( [ 99999 ] ), // Non-existent pack
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails when pack belongs to different ref.
	 */
	public function testRemovePacks_WithPackFromWrongRef_ReturnsError(): void {
		// Create two refs
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refIdMain = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$refIdDevelop = $this->refRegistry->ensureRefEntry( $repoId, 'develop', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create pack in develop ref
		$packId = $this->packRegistry->registerPack( $refIdDevelop, 'TestPack', '1.0.0', 1 );

		$this->expectException( \ApiUsageException::class );

		// Try to remove from main ref (should fail)
		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main', // Different ref
			'pack_ids' => json_encode( [ $packId->toInt() ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal fails when other packs depend on the pack being removed.
	 */
	public function testRemovePacks_WithBlockingDependencies_ReturnsError(): void {
		// Create test repo and ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest with dependencies
		$this->createTestManifest(
			[
				'BasePackage' => [ 'name' => 'BasePackage', 'depends_on' => [] ],
				'DependentPackage' => [ 'name' => 'DependentPackage', 'depends_on' => [ 'BasePackage' ] ],
			],
			[]
		);

		// Install both packs
		$basePackId = $this->packRegistry->registerPack( $refId, 'BasePackage', '1.0.0', 1 );
		$depPackId = $this->packRegistry->registerPack( $refId, 'DependentPackage', '1.0.0', 1 );

		$this->expectException( \ApiUsageException::class );

		// Try to remove BasePackage while DependentPackage still exists
		$this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => json_encode( [ $basePackId->toInt() ] ), // Only removing base
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test removal succeeds when removing both dependent and dependency together.
	 */
	public function testRemovePacks_WithBothDependentAndDependency_Success(): void {
		// Create test repo and ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest with dependencies
		$this->createTestManifest(
			[
				'BasePackage' => [ 'name' => 'BasePackage', 'depends_on' => [] ],
				'DependentPackage' => [ 'name' => 'DependentPackage', 'depends_on' => [ 'BasePackage' ] ],
			],
			[]
		);

		// Install both packs
		$basePackId = $this->packRegistry->registerPack( $refId, 'BasePackage', '1.0.0', 1 );
		$depPackId = $this->packRegistry->registerPack( $refId, 'DependentPackage', '1.0.0', 1 );

		// Remove both together (should succeed)
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => json_encode( [ $basePackId->toInt(), $depPackId->toInt() ] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
		$this->assertCount( 2, $data['packs'] );
		$this->assertContains( 'BasePackage', $data['packs'] );
		$this->assertContains( 'DependentPackage', $data['packs'] );
	}

	/**
	 * Test removal works with repo_url instead of repo_id.
	 */
	public function testRemovePacks_WithRepoUrl_Success(): void {
		// Create test repo and ref with worktree
		$repoUrl = 'https://github.com/test/repo';
		$repoId = $this->repoRegistry->ensureRepoEntry( $repoUrl );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'depends_on' => [] ] ],
			[]
		);

		// Install a pack
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_url' => $repoUrl, // Using URL instead of ID
			'ref' => 'main',
			'pack_ids' => json_encode( [ $packId->toInt() ] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
	}

	/**
	 * Test removal works with ref_id instead of ref name.
	 */
	public function testRemovePacks_WithRefId_Success(): void {
		// Create test repo and ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'depends_on' => [] ] ],
			[]
		);

		// Install a pack
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref_id' => $refId->toInt(), // Using ref_id instead of ref name
			'pack_ids' => json_encode( [ $packId->toInt() ] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
	}

	/**
	 * Test removal with delete_pages=false (default).
	 */
	public function testRemovePacks_WithDeletePagesFalse_Success(): void {
		// Create test repo and ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'depends_on' => [] ] ],
			[]
		);

		// Install a pack
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => json_encode( [ $packId->toInt() ] ),
			// Omit delete_pages to test default (false)
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'delete_pages', $data );
		$this->assertFalse( $data['delete_pages'] );
	}

	/**
	 * Test removal with multiple packs.
	 */
	public function testRemovePacks_WithMultiplePacks_Success(): void {
		// Create test repo and ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [] ],
				'Pack3' => [ 'name' => 'Pack3', 'depends_on' => [] ],
			],
			[]
		);

		// Install multiple packs
		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$pack2Id = $this->packRegistry->registerPack( $refId, 'Pack2', '1.0.0', 1 );
		$pack3Id = $this->packRegistry->registerPack( $refId, 'Pack3', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksRemove',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'pack_ids' => json_encode( [ $pack1Id->toInt(), $pack2Id->toInt(), $pack3Id->toInt() ] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertCount( 3, $data['packs'] );
		$this->assertContains( 'Pack1', $data['packs'] );
		$this->assertContains( 'Pack2', $data['packs'] );
		$this->assertContains( 'Pack3', $data['packs'] );
	}
}

