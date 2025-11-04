<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Jobs;

use MediaWikiIntegrationTestCase;
use MediaWiki\Title\Title;
use MediaWiki\Revision\SlotRecord;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Jobs\LabkiPackApplyJob;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;

/**
 * Integration tests for LabkiPackApplyJob.
 *
 * These tests verify that the unified background job correctly:
 * - Validates parameters (ref_id, operations, operation_id)
 * - Executes mixed operations in correct order (remove → install → update)
 * - Updates operation status through the lifecycle
 * - Handles errors gracefully
 * - Reports progress and results accurately
 * - Handles partial success scenarios
 *
 * @covers \LabkiPackManager\Jobs\LabkiPackApplyJob
 * @group LabkiPackManager
 * @group Database
 * @group Jobs
 */
class LabkiPackApplyJobTest extends MediaWikiIntegrationTestCase {

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
		'labki_pack_dependency',
		'labki_operations',
		'page',
		'revision',
		'text',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
		$this->pageRegistry = new LabkiPageRegistry();
		$this->operationRegistry = new LabkiOperationRegistry();

		// Create temporary directory for test worktree
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_apply_job_test_' . uniqid();
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
	 * Create a test page file in the worktree.
	 */
	private function createTestPageFile( string $relPath, string $content ): void {
		$fullPath = $this->testWorktreePath . '/' . ltrim( $relPath, '/' );
		$dir = dirname( $fullPath );
		
		if ( !is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		
		file_put_contents( $fullPath, $content );
	}

	// ========================================
	// Parameter Validation Tests
	// ========================================

	/**
	 * Test job fails gracefully with missing ref_id.
	 */
	public function testRun_WithMissingRefId_ReturnsFalse(): void {
		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'operations' => [ [ 'action' => 'install', 'pack_name' => 'Test', 'pages' => [] ] ],
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	/**
	 * Test job fails gracefully with missing operations.
	 */
	public function testRun_WithMissingOperations_ReturnsFalse(): void {
		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => 1,
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	/**
	 * Test job fails gracefully with empty operations array.
	 */
	public function testRun_WithEmptyOperations_ReturnsFalse(): void {
		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => 1,
			'operations' => [],
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	// ========================================
	// Single Operation Tests
	// ========================================

	/**
	 * Test single install operation succeeds.
	 */
	public function testRun_SingleInstall_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.0.0', 'depends_on' => [] ] ],
			[ 'TestPage' => [ 'file' => 'pages/TestPage.wiki' ] ]
		);
		$this->createTestPageFile( 'pages/TestPage.wiki', '== Test Page ==' );

		$operationId = 'test_install_' . uniqid();
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'operations' => [
				[
					'action' => 'install',
					'pack_name' => 'TestPack',
					'version' => '1.0.0',
					'pages' => [
						[ 'name' => 'TestPage', 'finalTitle' => 'TestPack/TestPage' ],
					],
				],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify operation completed successfully
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( '1/1 operations completed', $operation->message() );

		// Verify pack was installed
		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		$this->assertCount( 1, $installedPacks );
		$this->assertEquals( 'TestPack', $installedPacks[0]->name() );
		$this->assertEquals( '1.0.0', $installedPacks[0]->version() );
	}

	/**
	 * Test single update operation succeeds.
	 */
	public function testRun_SingleUpdate_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.5.0', 'pages' => [ 'TestPage' ] ] ],
			[ 'TestPage' => [ 'file' => 'pages/test.wiki' ] ]
		);
		$this->createTestPageFile( 'pages/test.wiki', 'New content' );

		// Install old version
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$operationId = 'test_update_' . uniqid();
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'operations' => [
				[
					'action' => 'update',
					'pack_name' => 'TestPack',
					'target_version' => '1.5.0',
				],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify pack was updated
		$updatedPack = $this->packRegistry->getPack( $packId );
		$this->assertNotNull( $updatedPack );
		$this->assertEquals( '1.5.0', $updatedPack->version() );
	}

	/**
	 * Test single remove operation succeeds.
	 */
	public function testRun_SingleRemove_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Install a pack
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$operationId = 'test_remove_' . uniqid();
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'operations' => [
				[
					'action' => 'remove',
					'pack_id' => $packId->toInt(),
					'delete_pages' => false,
				],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify pack was removed
		$removedPack = $this->packRegistry->getPack( $packId );
		$this->assertNull( $removedPack );
	}

	// ========================================
	// Mixed Operations Tests
	// ========================================

	/**
	 * Test mixed operations (remove + install + update) succeed.
	 */
	public function testRun_MixedOperations_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[
				'InstallPack' => [ 'name' => 'InstallPack', 'version' => '1.0.0', 'depends_on' => [] ],
				'UpdatePack' => [ 'name' => 'UpdatePack', 'version' => '1.5.0', 'depends_on' => [] ],
			],
			[]
		);

		// Install UpdatePack (old version) and RemovePack
		$updatePackId = $this->packRegistry->registerPack( $refId, 'UpdatePack', '1.0.0', 1 );
		$removePackId = $this->packRegistry->registerPack( $refId, 'RemovePack', '1.0.0', 1 );

		$operationId = 'test_mixed_' . uniqid();
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'operations' => [
				[
					'action' => 'remove',
					'pack_id' => $removePackId->toInt(),
					'delete_pages' => false,
				],
				[
					'action' => 'install',
					'pack_name' => 'InstallPack',
					'version' => '1.0.0',
					'pages' => [],
				],
				[
					'action' => 'update',
					'pack_name' => 'UpdatePack',
					'target_version' => '1.5.0',
				],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify operation completed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );

		// Parse result data
		$resultData = json_decode( $operation->resultData(), true );
		$this->assertNotNull( $resultData );
		$this->assertTrue( $resultData['success'] );
		$this->assertEquals( 3, $resultData['operations_completed'] );
		$this->assertEquals( 0, $resultData['operations_failed'] );

		// Verify each operation type succeeded
		$this->assertCount( 1, $resultData['removes'] );
		$this->assertCount( 1, $resultData['installs'] );
		$this->assertCount( 1, $resultData['updates'] );

		// Verify database state
		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		$packNames = array_map( fn( $p ) => $p->name(), $installedPacks );
		
		$this->assertContains( 'InstallPack', $packNames );
		$this->assertContains( 'UpdatePack', $packNames );
		$this->assertNotContains( 'RemovePack', $packNames );

		// Verify UpdatePack version
		foreach ( $installedPacks as $pack ) {
			if ( $pack->name() === 'UpdatePack' ) {
				$this->assertEquals( '1.5.0', $pack->version() );
			}
		}
	}

	/**
	 * Test operations are processed in correct order (remove → install → update).
	 */
	public function testRun_OperationsInCorrectOrder_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'version' => '1.0.0', 'depends_on' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'version' => '1.0.0', 'depends_on' => [] ],
			],
			[]
		);

		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$pack2Id = $this->packRegistry->registerPack( $refId, 'Pack2', '1.0.0', 1 );

		$operationId = 'test_order_' . uniqid();
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Submit operations in mixed order (update, install, remove)
		// Job should reorder them to (remove, install, update)
		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'operations' => [
				[ 'action' => 'update', 'pack_name' => 'Pack1', 'target_version' => '1.0.0' ],
				[ 'action' => 'install', 'pack_name' => 'Pack3', 'version' => '1.0.0', 'pages' => [] ],
				[ 'action' => 'remove', 'pack_id' => $pack2Id->toInt(), 'delete_pages' => false ],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify result order in resultData (should be remove, install, update)
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$resultData = json_decode( $operation->resultData(), true );

		$this->assertCount( 1, $resultData['removes'] );
		$this->assertCount( 1, $resultData['installs'] );
		$this->assertCount( 1, $resultData['updates'] );
	}

	// ========================================
	// Partial Failure Tests
	// ========================================

	/**
	 * Test partial failure (some operations succeed, some fail).
	 */
	public function testRun_PartialFailure_ReportsCorrectly(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[
				'ValidPack' => [ 'name' => 'ValidPack', 'version' => '1.0.0', 'depends_on' => [] ],
			],
			[]
		);

		// Install a pack to remove
		$removePackId = $this->packRegistry->registerPack( $refId, 'RemovePack', '1.0.0', 1 );

		$operationId = 'test_partial_' . uniqid();
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'operations' => [
				[ 'action' => 'remove', 'pack_id' => $removePackId->toInt(), 'delete_pages' => false ],
				[ 'action' => 'install', 'pack_name' => 'ValidPack', 'version' => '1.0.0', 'pages' => [] ],
				// This will fail - trying to update non-existent pack
				[ 'action' => 'update', 'pack_name' => 'NonExistentPack', 'target_version' => '1.0.0' ],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result ); // Overall failure due to one failed operation

		// Verify operation status
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertEquals( LabkiOperationRegistry::STATUS_FAILED, $operation->status() );

		// Parse result data
		$resultData = json_decode( $operation->resultData(), true );
		$this->assertFalse( $resultData['success'] );
		$this->assertEquals( 2, $resultData['operations_completed'] );
		$this->assertEquals( 1, $resultData['operations_failed'] );
		
		$this->assertCount( 1, $resultData['removes'] );
		$this->assertTrue( $resultData['removes'][0]['success'] );
		
		$this->assertCount( 1, $resultData['installs'] );
		$this->assertTrue( $resultData['installs'][0]['success'] );
		
		$this->assertCount( 1, $resultData['updates'] );
		$this->assertFalse( $resultData['updates'][0]['success'] );
	}

	// ========================================
	// Progress Tracking Tests
	// ========================================

	/**
	 * Test progress is tracked correctly.
	 */
	public function testRun_TracksProgress_Successfully(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.0.0', 'depends_on' => [] ] ],
			[]
		);

		$operationId = 'test_progress_' . uniqid();
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackApplyJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'operations' => [
				[ 'action' => 'install', 'pack_name' => 'TestPack', 'version' => '1.0.0', 'pages' => [] ],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify operation has progress (should be 100 at completion)
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation->progress() );
		$this->assertGreaterThanOrEqual( 90, $operation->progress() );
	}
}

