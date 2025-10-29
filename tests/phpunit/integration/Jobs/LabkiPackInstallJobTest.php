<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Jobs;

use MediaWikiIntegrationTestCase;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Jobs\LabkiPackInstallJob;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;

/**
 * Integration tests for LabkiPackInstallJob.
 *
 * These tests verify that the background job correctly:
 * - Validates parameters (ref_id, packs, operation_id)
 * - Executes pack installation operations
 * - Updates operation status through the lifecycle
 * - Handles errors gracefully
 * - Reports progress and results accurately
 *
 * @covers \LabkiPackManager\Jobs\LabkiPackInstallJob
 * @group LabkiPackManager
 * @group Database
 * @group Jobs
 */
class LabkiPackInstallJobTest extends MediaWikiIntegrationTestCase {

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
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
		$this->pageRegistry = new LabkiPageRegistry();
		$this->operationRegistry = new LabkiOperationRegistry();

		// Create temporary directory for test worktree
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_job_test_' . uniqid();
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

	/**
	 * Test that job fails gracefully with missing ref_id.
	 */
	public function testRun_WithMissingRefId_ReturnsFalse(): void {
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			// Missing ref_id parameter
			'packs' => [ [ 'name' => 'TestPack', 'pages' => [] ] ],
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	/**
	 * Test that job fails gracefully with missing packs.
	 */
	public function testRun_WithMissingPacks_ReturnsFalse(): void {
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => 1,
			// Missing packs parameter
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	/**
	 * Test that job fails gracefully with empty packs array.
	 */
	public function testRun_WithEmptyPacks_ReturnsFalse(): void {
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => 1,
			'packs' => [], // Empty packs array
			'operation_id' => 'test_op_123',
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );
	}

	/**
	 * Test that job creates operation record if operation_id is provided.
	 */
	public function testRun_CreatesOperationRecord(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest and page
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.0.0' ] ],
			[ 'TestPage' => [ 'file' => 'pages/TestPage.wiki' ] ]
		);
		$this->createTestPageFile( 'pages/TestPage.wiki', '== Test Page ==' );

		$operationId = 'test_pack_install_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_INSTALL,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create and run job
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [
				[
					'name' => 'TestPack',
					'version' => '1.0.0',
					'pages' => [
						[
							'name' => 'TestPage',
							'original' => 'TestPage',
							'finalTitle' => 'TestPack/TestPage',
						],
					],
				],
			],
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
			LabkiOperationRegistry::TYPE_PACK_INSTALL,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job with non-existent ref_id
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => 99999, // Non-existent ref
			'packs' => [
				[ 'name' => 'TestPack', 'pages' => [] ],
			],
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
	 * Test that job fails when worktree does not exist.
	 */
	public function testRun_WithMissingWorktree_FailsOperation(): void {
		// Create ref without worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => '/nonexistent/path',
		] );

		$operationId = 'test_missing_worktree_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_INSTALL,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [
				[ 'name' => 'TestPack', 'pages' => [] ],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertFalse( $result );

		// Verify operation was marked as failed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_FAILED, $operation->status() );
		$this->assertStringContainsString( 'Worktree not found', $operation->message() );
	}

	/**
	 * Test successful pack installation.
	 */
	public function testRun_WithValidPack_InstallsSuccessfully(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest and page files
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.0.0' ] ],
			[
				'Page1' => [ 'file' => 'pages/Page1.wiki' ],
				'Page2' => [ 'file' => 'pages/Page2.wiki' ],
			]
		);
		$this->createTestPageFile( 'pages/Page1.wiki', '== Page 1 ==' );
		$this->createTestPageFile( 'pages/Page2.wiki', '== Page 2 ==' );

		$operationId = 'test_success_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_INSTALL,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [
				[
					'name' => 'TestPack',
					'version' => '1.0.0',
					'pages' => [
						[ 'name' => 'Page1', 'original' => 'Page1', 'finalTitle' => 'TestPack/Page1' ],
						[ 'name' => 'Page2', 'original' => 'Page2', 'finalTitle' => 'TestPack/Page2' ],
					],
				],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result, 'Job should complete successfully' );

		// Verify operation completed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( '1/1 pack(s) installed', $operation->message() );
		$this->assertStringContainsString( '2 pages created', $operation->message() );

		// Verify pack was registered
		$packs = $this->packRegistry->listPacksByRef( $refId );
		$this->assertCount( 1, $packs );
		$this->assertEquals( 'TestPack', $packs[0]->name() );
		$this->assertEquals( '1.0.0', $packs[0]->version() );

		// Verify pages were registered
		$pages = $this->pageRegistry->listPagesByPack( $packs[0]->id() );
		$this->assertCount( 2, $pages );
	}

	/**
	 * Test job with multiple packs.
	 */
	public function testRun_WithMultiplePacks_InstallsAll(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest and page files
		$this->createTestManifest(
			[
				'PackA' => [ 'name' => 'PackA', 'version' => '1.0.0' ],
				'PackB' => [ 'name' => 'PackB', 'version' => '2.0.0' ],
			],
			[
				'PageA' => [ 'file' => 'pages/PageA.wiki' ],
				'PageB' => [ 'file' => 'pages/PageB.wiki' ],
			]
		);
		$this->createTestPageFile( 'pages/PageA.wiki', '== Page A ==' );
		$this->createTestPageFile( 'pages/PageB.wiki', '== Page B ==' );

		$operationId = 'test_multiple_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_INSTALL,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [
				[
					'name' => 'PackA',
					'version' => '1.0.0',
					'pages' => [
						[ 'name' => 'PageA', 'original' => 'PageA', 'finalTitle' => 'PackA/PageA' ],
					],
				],
				[
					'name' => 'PackB',
					'version' => '2.0.0',
					'pages' => [
						[ 'name' => 'PageB', 'original' => 'PageB', 'finalTitle' => 'PackB/PageB' ],
					],
				],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result );

		// Verify operation completed
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( '2/2 pack(s) installed', $operation->message() );

		// Verify both packs were registered
		$packs = $this->packRegistry->listPacksByRef( $refId );
		$this->assertCount( 2, $packs );

		$packNames = array_map( fn( $p ) => $p->name(), $packs );
		$this->assertContains( 'PackA', $packNames );
		$this->assertContains( 'PackB', $packNames );
	}

	/**
	 * Test job with partial failure (some packs succeed, some fail).
	 */
	public function testRun_WithPartialFailure_CompletesWithSummary(): void {
		// Create ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest with only one page
		$this->createTestManifest(
			[
				'GoodPack' => [ 'name' => 'GoodPack' ],
				'BadPack' => [ 'name' => 'BadPack' ],
			],
			[ 'GoodPage' => [ 'file' => 'pages/Good.wiki' ] ]
			// Note: BadPack's page file doesn't exist
		);
		$this->createTestPageFile( 'pages/Good.wiki', '== Good Page ==' );

		$operationId = 'test_partial_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_INSTALL,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [
				[
					'name' => 'GoodPack',
					'pages' => [
						[ 'name' => 'GoodPage', 'original' => 'GoodPage', 'finalTitle' => 'Good/Page' ],
					],
				],
				[
					'name' => 'BadPack',
					'pages' => [
						[ 'name' => 'BadPage', 'original' => 'BadPage', 'finalTitle' => 'Bad/Page' ],
					],
				],
			],
			'operation_id' => $operationId,
			'user_id' => 1,
		] );

		$result = $job->run();

		$this->assertTrue( $result, 'Job should complete with partial success' );

		// Verify operation completed (partial success still counts as complete)
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertEquals( LabkiOperationRegistry::STATUS_SUCCESS, $operation->status() );
		$this->assertStringContainsString( 'Partial success', $operation->message() );
		$this->assertStringContainsString( '1/2 pack(s) installed', $operation->message() );
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

		// Create manifest and page
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack' ] ],
			[ 'TestPage' => [ 'file' => 'pages/Test.wiki' ] ]
		);
		$this->createTestPageFile( 'pages/Test.wiki', '== Test ==' );

		$operationId = 'test_progress_' . uniqid();

		// Create operation record
		$this->operationRegistry->createOperation(
			new OperationId( $operationId ),
			LabkiOperationRegistry::TYPE_PACK_INSTALL,
			1,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Test operation'
		);

		// Create job
		$job = new LabkiPackInstallJob( Title::newMainPage(), [
			'ref_id' => $refId->toInt(),
			'packs' => [
				[
					'name' => 'TestPack',
					'pages' => [
						[ 'name' => 'TestPage', 'original' => 'TestPage', 'finalTitle' => 'Test/Page' ],
					],
				],
			],
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
		$this->assertArrayHasKey( 'installed_packs', $resultData );
		$this->assertArrayHasKey( 'total_pages_created', $resultData );
		$this->assertEquals( 1, $resultData['total_packs'] );
		$this->assertEquals( 1, $resultData['installed_packs'] );
		$this->assertEquals( 1, $resultData['total_pages_created'] );
	}
}

