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
use MediaWiki\MediaWikiServices;

/**
 * Integration tests for ApiLabkiPacksApply.
 *
 * These tests verify that the unified API endpoint:
 * - Creates operation records in LabkiOperationRegistry
 * - Queues LabkiPackApplyJob with correct parameters
 * - Returns appropriate responses for various scenarios
 * - Validates input parameters correctly (repo, ref, operations)
 * - Handles mixed operations (install + update + remove)
 * - Validates dependencies across operations
 *
 * @covers \LabkiPackManager\API\Packs\ApiLabkiPacksApply
 * @covers \LabkiPackManager\API\Packs\PackApiBase
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
class ApiLabkiPacksApplyTest extends ApiTestCase {

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
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_api_apply_test_' . uniqid();
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

	// ========================================
	// Success Cases
	// ========================================

	/**
	 * Test successful single install operation.
	 */
	public function testApply_SingleInstall_QueuesJobAndReturnsOperationId(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.0.0' ] ],
			[ 'TestPage' => [ 'file' => 'pages/test.wiki' ] ]
		);

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [
				[
					'action' => 'install',
					'pack_name' => 'TestPack',
					'pages' => [
						[ 'name' => 'TestPage', 'final_title' => 'TestPack/TestPage' ],
					],
				],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
		$this->assertStringStartsWith( 'pack_apply_', $data['operation_id'] );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $data['status'] );
		
		$this->assertArrayHasKey( 'summary', $data );
		$this->assertSame( 1, $data['summary']['total_operations'] );
		$this->assertSame( 1, $data['summary']['installs'] );
		$this->assertSame( 0, $data['summary']['updates'] );
		$this->assertSame( 0, $data['summary']['removes'] );
		
		// Verify operation was created
		$operationId = $data['operation_id'];
		$this->assertTrue( $this->operationRegistry->operationExists( new OperationId( $operationId ) ) );
		
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertSame( LabkiOperationRegistry::TYPE_PACK_APPLY, $operation->type() );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $operation->status() );
		
		// Verify job was queued
		$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$this->assertGreaterThan( 0, $jobQueue->get( 'labkiPackApply' )->getSize() );
	}

	/**
	 * Test successful mixed operations (install + update + remove).
	 */
	public function testApply_MixedOperations_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
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

		// Install UpdatePack and RemovePack first
		$updatePackId = $this->packRegistry->registerPack( $refId, 'UpdatePack', '1.0.0', 1 );
		$removePackId = $this->packRegistry->registerPack( $refId, 'RemovePack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [
				[
					'action' => 'install',
					'pack_name' => 'InstallPack',
					'pages' => [],
				],
				[
					'action' => 'update',
					'pack_name' => 'UpdatePack',
					'target_version' => '1.5.0',
					'pages' => [],
				],
				[
					'action' => 'remove',
					'pack_id' => $removePackId->toInt(),
					'delete_pages' => false,
				],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertSame( 3, $data['summary']['total_operations'] );
		$this->assertSame( 1, $data['summary']['installs'] );
		$this->assertSame( 1, $data['summary']['updates'] );
		$this->assertSame( 1, $data['summary']['removes'] );
	}

	/**
	 * Test with repo_url instead of repo_id.
	 */
	public function testApply_WithRepoUrl_Success(): void {
		$repoUrl = 'https://github.com/test/repo';
		$repoId = $this->repoRegistry->ensureRepoEntry( $repoUrl );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack' ] ],
			[]
		);

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_url' => $repoUrl,
			'ref' => 'main',
			'operations' => json_encode( [
				[ 'action' => 'install', 'pack_name' => 'TestPack', 'pages' => [] ],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test with ref_id instead of ref name.
	 */
	public function testApply_WithRefId_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack' ] ],
			[]
		);

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref_id' => $refId->toInt(),
			'operations' => json_encode( [
				[ 'action' => 'install', 'pack_name' => 'TestPack', 'pages' => [] ],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test remove-only operations don't require worktree.
	 */
	public function testApply_RemoveOnlyWithoutWorktree_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => null, // No worktree
		] );

		// Install a pack first
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [
				[ 'action' => 'remove', 'pack_id' => $packId->toInt() ],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		$this->assertTrue( $data['success'] );
	}

	// ========================================
	// Parameter Validation Errors
	// ========================================

	/**
	 * Test error when both repo_id and repo_url provided.
	 */
	public function testApply_WithBothRepoIdentifiers_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => 1,
			'repo_url' => 'https://github.com/test/repo',
			'ref' => 'main',
			'operations' => json_encode( [ [ 'action' => 'install', 'pack_name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when both ref_id and ref provided.
	 */
	public function testApply_WithBothRefIdentifiers_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref_id' => 1,
			'ref' => 'main',
			'operations' => json_encode( [ [ 'action' => 'install', 'pack_name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when no repo identifier provided.
	 */
	public function testApply_WithoutRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'ref' => 'main',
			'operations' => json_encode( [ [ 'action' => 'install', 'pack_name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when no ref identifier provided.
	 */
	public function testApply_WithoutRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'operations' => json_encode( [ [ 'action' => 'install', 'pack_name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when operations parameter is empty.
	 */
	public function testApply_WithEmptyOperations_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when operations parameter is invalid JSON.
	 */
	public function testApply_WithInvalidOperationsJson_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => 'invalid json{',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when operation is missing action field.
	 */
	public function testApply_WithOperationMissingAction_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [
				[ 'pack_name' => 'Test' ], // Missing 'action'
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when operation has invalid action.
	 */
	public function testApply_WithInvalidAction_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [
				[ 'action' => 'invalid_action', 'pack_name' => 'Test' ],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when install/update operation missing pack_name.
	 */
	public function testApply_WithInstallMissingPackName_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [
				[ 'action' => 'install', 'pages' => [] ], // Missing 'pack_name'
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when remove operation missing pack_id.
	 */
	public function testApply_WithRemoveMissingPackId_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [
				[ 'action' => 'remove' ], // Missing 'pack_id'
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	// ========================================
	// Resource Validation Errors
	// ========================================

	/**
	 * Test error when repository not found.
	 */
	public function testApply_WithNonExistentRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => 99999,
			'ref' => 'main',
			'operations' => json_encode( [ [ 'action' => 'install', 'pack_name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when ref not found.
	 */
	public function testApply_WithNonExistentRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'nonexistent',
			'operations' => json_encode( [ [ 'action' => 'install', 'pack_name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when worktree not found (for install/update operations).
	 */
	public function testApply_WithInstallAndMissingWorktree_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => '/nonexistent/path',
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksApply',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'operations' => json_encode( [
				[ 'action' => 'install', 'pack_name' => 'Test', 'pages' => [] ],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}
}

