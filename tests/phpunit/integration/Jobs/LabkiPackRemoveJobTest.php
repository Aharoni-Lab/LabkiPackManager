<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Jobs;

use MediaWikiIntegrationTestCase;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Jobs\LabkiPackRemoveJob;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;

/**
 * Integration tests for LabkiPackRemoveJob.
 *
 * These tests verify that the background job correctly:
 * - Validates parameters (ref_id, pack_ids, operation_id)
 * - Executes pack removal operations
 * - Updates operation status through the lifecycle
 * - Handles errors gracefully
 * - Reports progress and results accurately
 * - Respects the delete_pages parameter
 *
 * @covers \LabkiPackManager\Jobs\LabkiPackRemoveJob
 * @group LabkiPackManager
 * @group Database
 * @group Jobs
 */
class LabkiPackRemoveJobTest extends MediaWikiIntegrationTestCase {

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
		'page',
		'revision',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
		$this->pageRegistry = new LabkiPageRegistry();
		$this->operationRegistry = new LabkiOperationRegistry();

		// Create temporary directory for test worktree
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_remove_job_test_' . uniqid();
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
	 * Test that job fails gracefully with missing ref_id.
	 */
	public function testRun_WithMissingRefId_ReturnsFalse(): void {
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			// Missing ref_id parameter
			'pack_ids' => [ 1 ],
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	/**
	 * Test that job fails gracefully with missing pack_ids.
	 */
	public function testRun_WithMissingPackIds_ReturnsFalse(): void {
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => 1,
			// Missing pack_ids parameter
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	/**
	 * Test that job fails gracefully with empty pack_ids array.
	 */
	public function testRun_WithEmptyPackIds_ReturnsFalse(): void {
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => 1,
			'pack_ids' => [], // Empty pack_ids array
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	/**
	 * Test that job updates operation record.
	 */
	public function testRun_UpdatesOperationRecord(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Register a pack
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$operationId = 'test_pack_remove_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create and run job
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'pack_ids' => [ $packId->toInt() ],
			'delete_pages' => false,
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify operation was updated
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
	}

	/**
	 * Test that job fails when ref does not exist.
	 */
	public function testRun_WithInvalidRef_FailsOperation(): void {
		$operationId = 'test_invalid_ref_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job with non-existent ref_id
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => 99999, // Non-existent ref
			'pack_ids' => [ 1 ],
			'delete_pages' => false,
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );

		// Verify operation was marked as failed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_FAILED, $operation->status() );
		$this->assertStringContainsString( 'Ref not found', $operation->message() );
	}

	/**
	 * Test successful pack removal (metadata only, no pages).
	 */
	public function testRun_WithValidPack_RemovesSuccessfully(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Register a pack
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$operationId = 'test_success_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'pack_ids' => [ $packId->toInt() ],
			'delete_pages' => false, // Don't delete pages
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result, 'Job should complete successfully' );

		// Verify operation completed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( '1/1 pack(s) removed', $operation->message() );

		// Verify pack was removed from registry
		$pack = $this->packRegistry->getPack( $packId );
		$this->assertNull( $pack, 'Pack should be removed from registry' );
	}

	/**
	 * Test successful pack removal with page deletion.
	 */
	public function testRun_WithDeletePages_RemovesPagesAndPack(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Register a pack with pages
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );
		
		// Create MediaWiki pages
		$page1Title = Title::newFromText( 'TestPack/Page1' );
		$page2Title = Title::newFromText( 'TestPack/Page2' );
		
		$this->editPage( $page1Title, '== Page 1 ==' );
		$this->editPage( $page2Title, '== Page 2 ==' );

		// Register pages in database
		$pageId1 = $this->pageRegistry->addPage( $packId, 'Page1', 'TestPack/Page1', 0 );
		$pageId2 = $this->pageRegistry->addPage( $packId, 'Page2', 'TestPack/Page2', 0 );

		$operationId = 'test_delete_pages_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'pack_ids' => [ $packId->toInt() ],
			'delete_pages' => true, // Delete pages
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify operation completed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( 'pages deleted', $operation->message() );

		// Verify pack was removed
		$pack = $this->packRegistry->getPack( $packId );
		$this->assertNull( $pack );
	}

	/**
	 * Test job with multiple packs.
	 */
	public function testRun_WithMultiplePacks_RemovesAll(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Register multiple packs
		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$pack2Id = $this->packRegistry->registerPack( $refId, 'Pack2', '2.0.0', 1 );
		$pack3Id = $this->packRegistry->registerPack( $refId, 'Pack3', '3.0.0', 1 );

		$operationId = 'test_multiple_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'pack_ids' => [ $pack1Id->toInt(), $pack2Id->toInt(), $pack3Id->toInt() ],
			'delete_pages' => false,
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify operation completed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( '3/3 pack(s) removed', $operation->message() );

		// Verify all packs were removed
		$this->assertNull( $this->packRegistry->getPack( $pack1Id ) );
		$this->assertNull( $this->packRegistry->getPack( $pack2Id ) );
		$this->assertNull( $this->packRegistry->getPack( $pack3Id ) );
	}

	/**
	 * Test job with partial failure (some packs removed, some fail).
	 */
	public function testRun_WithPartialFailure_CompletesWithSummary(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Register one valid pack
		$validPackId = $this->packRegistry->registerPack( $refId, 'ValidPack', '1.0.0', 1 );

		$operationId = 'test_partial_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job with one valid and one invalid pack ID
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'pack_ids' => [ $validPackId->toInt(), 99999 ], // One valid, one invalid
			'delete_pages' => false,
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result, 'Job should complete even with partial success' );

		// Verify operation shows partial success
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( 'Partial success', $operation->message() );

		// Verify result data contains error information
		$resultData = json_decode( $operation->resultData(), true );
		$this->assertNotNull( $resultData );
		$this->assertArrayHasKey( 'errors', $resultData );
		$this->assertCount( 1, $resultData['errors'], 'Should have one error for invalid pack' );
	}

	/**
	 * Test job with all packs failing.
	 */
	public function testRun_WithAllPacksFailing_FailsOperation(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$operationId = 'test_all_fail_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job with only invalid pack IDs
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'pack_ids' => [ 99998, 99999 ], // All invalid
			'delete_pages' => false,
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail when all packs fail to remove' );

		// Verify operation was marked as failed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_FAILED, $operation->status() );
		$this->assertStringContainsString( 'No packs were removed', $operation->message() );
	}

	/**
	 * Test that job reports detailed progress information.
	 */
	public function testRun_ReportsDetailedProgress(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Register a pack with pages
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );
		
		// Create MediaWiki page
		$pageTitle = Title::newFromText( 'TestPack/Page1' );
		$this->editPage( $pageTitle, '== Test ==' );

		// Register page
		$this->pageRegistry->addPage( $packId, 'Page1', 'TestPack/Page1', 0 );

		$operationId = 'test_progress_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackRemoveJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'pack_ids' => [ $packId->toInt() ],
			'delete_pages' => true,
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify operation has detailed result data
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		
		$resultData = json_decode( $operation->resultData(), true );
		$this->assertNotNull( $resultData );
		$this->assertArrayHasKey( 'ref_id', $resultData );
		$this->assertArrayHasKey( 'total_packs', $resultData );
		$this->assertArrayHasKey( 'removed_packs', $resultData );
		$this->assertArrayHasKey( 'failed_packs', $resultData );
		$this->assertArrayHasKey( 'total_pages_deleted', $resultData );
		$this->assertArrayHasKey( 'delete_pages', $resultData );
		$this->assertArrayHasKey( 'packs', $resultData );
		
		$this->assertEquals( 1, $resultData['total_packs'] );
		$this->assertEquals( 1, $resultData['removed_packs'] );
		$this->assertEquals( 0, $resultData['failed_packs'] );
		$this->assertTrue( $resultData['delete_pages'] );
	}
}

