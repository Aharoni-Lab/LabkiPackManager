<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Jobs;

use MediaWikiIntegrationTestCase;
use LabkiPackManager\Jobs\LabkiPackUpdateJob;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Domain\OperationId;
use MediaWiki\Title\Title;

/**
 * Integration tests for LabkiPackUpdateJob.
 *
 * @group Database
 * @group medium
 * @covers \LabkiPackManager\Jobs\LabkiPackUpdateJob
 */
class LabkiPackUpdateJobTest extends MediaWikiIntegrationTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private LabkiPackRegistry $packRegistry;
	private LabkiOperationRegistry $operationRegistry;
	private string $testWorktreePath;

	protected function setUp(): void {
		parent::setUp();
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
		$this->operationRegistry = new LabkiOperationRegistry();

		// Create temporary test worktree directory
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_test_worktree_' . uniqid();
		mkdir( $this->testWorktreePath, 0777, true );
	}

	protected function tearDown(): void {
		// Clean up test worktree
		if ( is_dir( $this->testWorktreePath ) ) {
			$this->recursiveRemoveDirectory( $this->testWorktreePath );
		}
		parent::tearDown();
	}

	/**
	 * Recursively remove a directory and its contents.
	 */
	private function recursiveRemoveDirectory( string $dir ): void {
		if ( !is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->recursiveRemoveDirectory( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * Create a test manifest.yml file.
	 */
	private function createTestManifest( array $packs, array $pages ): void {
		$manifestContent = "schema_version: 1\n";
		$manifestContent .= "packs:\n";
		foreach ( $packs as $packName => $packDef ) {
			$manifestContent .= "  {$packName}:\n";
			$manifestContent .= "    name: {$packName}\n";
			if ( isset( $packDef['version'] ) ) {
				$manifestContent .= "    version: {$packDef['version']}\n";
			}
			if ( isset( $packDef['depends_on'] ) && !empty( $packDef['depends_on'] ) ) {
				$manifestContent .= "    depends_on:\n";
				foreach ( $packDef['depends_on'] as $dep ) {
					$manifestContent .= "      - {$dep}\n";
				}
			} else {
				$manifestContent .= "    depends_on: []\n";
			}
			if ( isset( $packDef['pages'] ) ) {
				$manifestContent .= "    pages:\n";
				foreach ( $packDef['pages'] as $page ) {
					$manifestContent .= "      - {$page}\n";
				}
			}
		}
		$manifestContent .= "pages:\n";
		foreach ( $pages as $pageName => $pageDef ) {
			$manifestContent .= "  {$pageName}:\n";
			$manifestContent .= "    file: {$pageDef['file']}\n";
		}

		file_put_contents( $this->testWorktreePath . '/manifest.yml', $manifestContent );
	}

	// ========================================
	// Parameter Validation Tests
	// ========================================

	/**
	 * Test job fails gracefully with missing ref_id.
	 */
	public function testRun_WithMissingRefId_ReturnsFalse(): void {
		$operationId = 'test_missing_ref_' . uniqid();

		$job = new LabkiPackUpdateJob( Title::newMainPage(), [
			'packs' => [ [ 'name' => 'TestPack' ] ],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail with missing ref_id' );
	}

	/**
	 * Test job fails gracefully with empty packs array.
	 */
	public function testRun_WithEmptyPacks_ReturnsFalse(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$operationId = 'test_empty_packs_' . uniqid();

		$job = new LabkiPackUpdateJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail with empty packs' );
	}

	// ========================================
	// Success Cases
	// ========================================

	/**
	 * Test successful pack update.
	 */
	public function testRun_WithValidPack_UpdatesSuccessfully(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest with updated version
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.2.0', 'pages' => [ 'TestPage' ], 'depends_on' => [] ] ],
			[ 'TestPage' => [ 'file' => 'pages/test.wiki' ] ]
		);

		// Create test page file
		$pagesDir = $this->testWorktreePath . '/pages';
		mkdir( $pagesDir, 0777, true );
		file_put_contents( $pagesDir . '/test.wiki', '== Updated Test Page ==' );

		// Install pack with old version
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$operationId = 'test_update_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_UPDATE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create and run job
		$job = new LabkiPackUpdateJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [ [ 'name' => 'TestPack', 'target_version' => '1.2.0' ] ],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result, 'Job should complete successfully' );

		// Verify operation completed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( '1/1 packs updated', $operation->message() );

		// Verify pack version was updated
		$updatedPack = $this->packRegistry->getPack( $packId );
		$this->assertEquals( '1.2.0', $updatedPack->version() );
	}

	/**
	 * Test updating multiple packs.
	 */
	public function testRun_WithMultiplePacks_UpdatesAll(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'version' => '1.2.0', 'pages' => [ 'Page1' ], 'depends_on' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'version' => '2.1.0', 'pages' => [ 'Page2' ], 'depends_on' => [] ],
			],
			[
				'Page1' => [ 'file' => 'pages/page1.wiki' ],
				'Page2' => [ 'file' => 'pages/page2.wiki' ],
			]
		);

		// Create page files
		$pagesDir = $this->testWorktreePath . '/pages';
		mkdir( $pagesDir, 0777, true );
		file_put_contents( $pagesDir . '/page1.wiki', '== Page 1 ==' );
		file_put_contents( $pagesDir . '/page2.wiki', '== Page 2 ==' );

		// Install packs with old versions
		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$pack2Id = $this->packRegistry->registerPack( $refId, 'Pack2', '2.0.0', 1 );

		$operationId = 'test_multiple_' . uniqid();

		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_UPDATE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackUpdateJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [
				[ 'name' => 'Pack1', 'target_version' => '1.2.0' ],
				[ 'name' => 'Pack2', 'target_version' => '2.1.0' ],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result, 'Job should complete successfully' );

		// Verify operation
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( '2/2 packs updated', $operation->message() );

		// Verify both pack versions were updated
		$pack1 = $this->packRegistry->getPack( $pack1Id );
		$pack2 = $this->packRegistry->getPack( $pack2Id );
		$this->assertEquals( '1.2.0', $pack1->version() );
		$this->assertEquals( '2.1.0', $pack2->version() );
	}

	// ========================================
	// Error Handling Tests
	// ========================================

	/**
	 * Test job handles pack not found error.
	 */
	public function testRun_WithNonExistentPack_RecordsFailure(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.2.0', 'depends_on' => [] ] ],
			[]
		);

		$operationId = 'test_not_found_' . uniqid();

		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_UPDATE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Don't install the pack - try to update non-existent pack
		$job = new LabkiPackUpdateJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [ [ 'name' => 'NonExistentPack' ] ],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail when pack not found' );

		// Verify operation failed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_FAILED, $operation->status() );
	}

	/**
	 * Test job handles partial failures.
	 */
	public function testRun_WithPartialFailure_RecordsFailure(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'version' => '1.2.0', 'pages' => [ 'Page1' ], 'depends_on' => [] ],
			],
			[ 'Page1' => [ 'file' => 'pages/page1.wiki' ] ]
		);

		// Create page file
		$pagesDir = $this->testWorktreePath . '/pages';
		mkdir( $pagesDir, 0777, true );
		file_put_contents( $pagesDir . '/page1.wiki', '== Page 1 ==' );

		// Install only Pack1
		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );

		$operationId = 'test_partial_' . uniqid();

		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_UPDATE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Try to update Pack1 (exists) and Pack2 (doesn't exist)
		$job = new LabkiPackUpdateJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [
				[ 'name' => 'Pack1', 'target_version' => '1.2.0' ],
				[ 'name' => 'Pack2', 'target_version' => '2.0.0' ],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail with partial failure' );

		// Verify operation failed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertEquals( LabkiOperationRegistry::STATUS_FAILED, $operation->status() );
		$this->assertStringContainsString( '1/2 packs updated', $operation->message() );
		$this->assertStringContainsString( '1 failed', $operation->message() );

		// Verify Pack1 was updated despite Pack2 failing
		$pack1 = $this->packRegistry->getPack( $pack1Id );
		$this->assertEquals( '1.2.0', $pack1->version() );
	}

	/**
	 * Test job handles pack with unnamed entry.
	 */
	public function testRun_WithUnnamedPack_RecordsFailure(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.2.0', 'depends_on' => [] ] ],
			[]
		);

		$operationId = 'test_unnamed_' . uniqid();

		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_UPDATE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackUpdateJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [ [ 'target_version' => '1.2.0' ] ], // Missing 'name'
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result, 'Job should fail with unnamed pack' );

		// Verify operation failed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertEquals( LabkiOperationRegistry::STATUS_FAILED, $operation->status() );
	}

	// ========================================
	// Progress Tracking Tests
	// ========================================

	/**
	 * Test job updates progress during execution.
	 */
	public function testRun_TracksProgress(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.2.0', 'pages' => [ 'Page1' ], 'depends_on' => [] ] ],
			[ 'Page1' => [ 'file' => 'pages/page1.wiki' ] ]
		);

		$pagesDir = $this->testWorktreePath . '/pages';
		mkdir( $pagesDir, 0777, true );
		file_put_contents( $pagesDir . '/page1.wiki', '== Page 1 ==' );

		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$operationId = 'test_progress_' . uniqid();

		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_UPDATE,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		$job = new LabkiPackUpdateJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [ [ 'name' => 'TestPack', 'target_version' => '1.2.0' ] ],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify final operation state
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertGreaterThanOrEqual( 95, $operation->progress() ); // Should be at least 95%
	}
}


