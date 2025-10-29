<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Packs;

use ApiTestCase;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Domain\OperationId;
use MediaWiki\MediaWikiServices;

/**
 * Integration tests for ApiLabkiPacksInstall.
 *
 * These tests verify that the API endpoint:
 * - Creates operation records in LabkiOperationRegistry
 * - Queues LabkiPackInstallJob with correct parameters
 * - Returns appropriate responses for various scenarios
 * - Validates input parameters correctly (repo, ref, packs)
 * - Verifies worktree existence before queuing
 * - Validates pack dependencies before installation
 *
 * Note: These tests mock Git operations and use temporary worktrees.
 *
 * @covers \LabkiPackManager\API\Packs\ApiLabkiPacksInstall
 * @covers \LabkiPackManager\API\Packs\PackApiBase
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
class ApiLabkiPacksInstallTest extends ApiTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private LabkiPackRegistry $packRegistry;
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
		$this->operationRegistry = new LabkiOperationRegistry();
		
		// Grant permissions for testing
		$this->setGroupPermissions( 'user', 'labkipackmanager-manage', true );

		// Create temporary directory for test worktree
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_api_test_' . uniqid();
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
	 * Test successful pack installation with all parameters.
	 */
	public function testInstallPacks_WithAllParams_QueuesJobAndReturnsOperationId(): void {
		// Create test repo and ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.0.0' ] ],
			[ 'TestPage' => [ 'file' => 'pages/test.wiki' ] ]
		);

		$packsJson = json_encode( [
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
		] );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => $packsJson,
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		// Check response structure
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		
		$this->assertArrayHasKey( 'operation_id', $data );
		$this->assertStringStartsWith( 'pack_install_', $data['operation_id'] );
		
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $data['status'] );
		
		$this->assertArrayHasKey( 'message', $data );
		$this->assertStringContainsString( 'queued', strtolower( $data['message'] ) );
		
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertIsArray( $data['packs'] );
		$this->assertContains( 'TestPack', $data['packs'] );
		
		// Check metadata
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'schemaVersion', $data['meta'] );
		$this->assertSame( 1, $data['meta']['schemaVersion'] );
		
		// Verify operation was created in database
		$operationId = $data['operation_id'];
		$this->assertTrue( $this->operationRegistry->operationExists( new OperationId( $operationId ) ) );
		
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertNotNull( $operation );
		$this->assertSame( LabkiOperationRegistry::TYPE_PACK_INSTALL, $operation->type() );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $operation->status() );
		
		// Verify job was queued
		$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$this->assertGreaterThan( 0, $jobQueue->get( 'labkiPackInstall' )->getSize() );
	}

	/**
	 * Test installation fails without repo parameter.
	 */
	public function testInstallPacks_WithoutRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			// Missing repo_id and repo_url
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails without ref parameter.
	 */
	public function testInstallPacks_WithoutRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			// Missing ref_id and ref
			'packs' => json_encode( [ [ 'name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with both repo_id and repo_url.
	 */
	public function testInstallPacks_WithMultipleRepoIdentifiers_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'repo_url' => 'https://github.com/test/repo', // Both provided
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with both ref_id and ref.
	 */
	public function testInstallPacks_WithMultipleRefIdentifiers_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref_id' => $refId->toInt(),
			'ref' => 'main', // Both provided
			'packs' => json_encode( [ [ 'name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with invalid packs JSON.
	 */
	public function testInstallPacks_WithInvalidPacksJson_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => 'invalid json{',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with empty packs array.
	 */
	public function testInstallPacks_WithEmptyPacks_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [] ), // Empty array
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with invalid pack format (missing name).
	 */
	public function testInstallPacks_WithMissingPackName_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [
				[ 'pages' => [] ], // Missing 'name' field
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with invalid pack format (missing pages).
	 */
	public function testInstallPacks_WithMissingPackPages_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [
				[ 'name' => 'Test' ], // Missing 'pages' field
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with non-existent repo.
	 */
	public function testInstallPacks_WithNonExistentRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => 99999, // Non-existent repo
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with non-existent ref.
	 */
	public function testInstallPacks_WithNonExistentRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'nonexistent', // Non-existent ref
			'packs' => json_encode( [ [ 'name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails when worktree doesn't exist.
	 */
	public function testInstallPacks_WithMissingWorktree_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => '/nonexistent/path', // Non-existent worktree
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'Test', 'pages' => [] ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation fails with missing pack dependencies.
	 */
	public function testInstallPacks_WithMissingDependencies_ReturnsError(): void {
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
			[
				'Page1' => [ 'file' => 'pages/page1.wiki' ],
				'Page2' => [ 'file' => 'pages/page2.wiki' ],
			]
		);

		$this->expectException( \ApiUsageException::class );

		// Try to install DependentPackage without BasePackage
		$this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [
				[
					'name' => 'DependentPackage',
					'pages' => [
						[ 'name' => 'Page2', 'original' => 'Page2', 'finalTitle' => 'Dep/Page2' ],
					],
				],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test installation succeeds when dependencies are satisfied.
	 */
	public function testInstallPacks_WithSatisfiedDependencies_Success(): void {
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
			[
				'Page1' => [ 'file' => 'pages/page1.wiki' ],
				'Page2' => [ 'file' => 'pages/page2.wiki' ],
			]
		);

		// Install both packages together (dependencies satisfied)
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [
				[
					'name' => 'BasePackage',
					'pages' => [
						[ 'name' => 'Page1', 'original' => 'Page1', 'finalTitle' => 'Base/Page1' ],
					],
				],
				[
					'name' => 'DependentPackage',
					'pages' => [
						[ 'name' => 'Page2', 'original' => 'Page2', 'finalTitle' => 'Dep/Page2' ],
					],
				],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
		$this->assertCount( 2, $data['packs'] );
		$this->assertContains( 'BasePackage', $data['packs'] );
		$this->assertContains( 'DependentPackage', $data['packs'] );
	}

	/**
	 * Test installation works with repo_url instead of repo_id.
	 */
	public function testInstallPacks_WithRepoUrl_Success(): void {
		// Create test repo and ref with worktree
		$repoUrl = 'https://github.com/test/repo';
		$repoId = $this->repoRegistry->ensureRepoEntry( $repoUrl );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack' ] ],
			[ 'TestPage' => [ 'file' => 'pages/test.wiki' ] ]
		);

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_url' => $repoUrl, // Using URL instead of ID
			'ref' => 'main',
			'packs' => json_encode( [
				[
					'name' => 'TestPack',
					'pages' => [
						[ 'name' => 'TestPage', 'original' => 'TestPage', 'finalTitle' => 'Test/Page' ],
					],
				],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
	}

	/**
	 * Test installation works with ref_id instead of ref name.
	 */
	public function testInstallPacks_WithRefId_Success(): void {
		// Create test repo and ref with worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack' ] ],
			[ 'TestPage' => [ 'file' => 'pages/test.wiki' ] ]
		);

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksInstall',
			'repo_id' => $repoId->toInt(),
			'ref_id' => $refId->toInt(), // Using ref_id instead of ref name
			'packs' => json_encode( [
				[
					'name' => 'TestPack',
					'pages' => [
						[ 'name' => 'TestPage', 'original' => 'TestPage', 'finalTitle' => 'Test/Page' ],
					],
				],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
	}
}

