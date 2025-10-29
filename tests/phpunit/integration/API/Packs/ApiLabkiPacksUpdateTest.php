<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Packs;

use MediaWiki\Tests\Api\ApiTestCase;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Domain\OperationId;
use MediaWiki\MediaWikiServices;

/**
 * Integration tests for ApiLabkiPacksUpdate.
 *
 * @group API
 * @group Database
 * @group medium
 * @covers \LabkiPackManager\API\Packs\ApiLabkiPacksUpdate
 */
class ApiLabkiPacksUpdateTest extends ApiTestCase {

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

		// Grant manage permission to test user
		$this->mergeMwGlobalArrayValue( 'wgGroupPermissions', [
			'*' => [ 'labkipackmanager-manage' => true ],
		] );
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
	 * Create a test manifest.yml file in the worktree.
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
	// Success Cases
	// ========================================

	/**
	 * Test successful pack update with all parameters.
	 */
	public function testUpdatePacks_WithAllParams_QueuesJobAndReturnsOperationId(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$this->createTestManifest(
			[
				'TestPack' => [ 'name' => 'TestPack', 'version' => '1.2.0', 'depends_on' => [] ],
			],
			[ 'TestPage' => [ 'file' => 'pages/test.wiki' ] ]
		);

		// Install pack first (old version)
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack', 'target_version' => '1.2.0' ] ] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		// Check response structure
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
		$this->assertStringStartsWith( 'pack_update_', $data['operation_id'] );
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
		$this->assertSame( LabkiOperationRegistry::TYPE_PACK_UPDATE, $operation->type() );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $operation->status() );
		
		// Verify job was queued
		$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$this->assertGreaterThan( 0, $jobQueue->get( 'labkiPackUpdate' )->getSize() );
	}

	/**
	 * Test update with repo_url instead of repo_id.
	 */
	public function testUpdatePacks_WithRepoUrl_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.1.0', 'depends_on' => [] ] ],
			[]
		);

		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_url' => 'https://github.com/test/repo',
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test update with ref_id instead of ref.
	 */
	public function testUpdatePacks_WithRefId_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.1.0', 'depends_on' => [] ] ],
			[]
		);

		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref_id' => $refId->toInt(),
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test updating multiple packs together.
	 */
	public function testUpdatePacks_WithMultiplePacks_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'version' => '1.2.0', 'depends_on' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'version' => '2.1.0', 'depends_on' => [] ],
			],
			[]
		);

		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$pack2Id = $this->packRegistry->registerPack( $refId, 'Pack2', '2.0.0', 1 );

		$result = $this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [
				[ 'name' => 'Pack1', 'target_version' => '1.2.0' ],
				[ 'name' => 'Pack2', 'target_version' => '2.1.0' ],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		$this->assertTrue( $data['success'] );
		$this->assertCount( 2, $data['packs'] );
		$this->assertContains( 'Pack1', $data['packs'] );
		$this->assertContains( 'Pack2', $data['packs'] );
	}

	// ========================================
	// Parameter Validation Errors
	// ========================================

	/**
	 * Test error when both repo_id and repo_url provided.
	 */
	public function testUpdatePacks_WithBothRepoIdAndRepoUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => 1,
			'repo_url' => 'https://github.com/test/repo',
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when both ref_id and ref provided.
	 */
	public function testUpdatePacks_WithBothRefIdAndRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref_id' => 1,
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when no repo identifier provided.
	 */
	public function testUpdatePacks_WithoutRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when no ref identifier provided.
	 */
	public function testUpdatePacks_WithoutRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when packs parameter is empty.
	 */
	public function testUpdatePacks_WithEmptyPacks_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when packs parameter is invalid JSON.
	 */
	public function testUpdatePacks_WithInvalidPacksJson_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => 'invalid json',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when pack is missing name field.
	 */
	public function testUpdatePacks_WithPackMissingName_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [ [ 'target_version' => '1.2.0' ] ] ), // Missing 'name'
		], null, false, $this->getTestUser()->getUser() );
	}

	// ========================================
	// Resource Validation Errors
	// ========================================

	/**
	 * Test error when repository not found.
	 */
	public function testUpdatePacks_WithNonExistentRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => 99999,
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when ref not found.
	 */
	public function testUpdatePacks_WithNonExistentRef_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'nonexistent',
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when worktree not found.
	 */
	public function testUpdatePacks_WithoutWorktree_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => null, // No worktree
		] );

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	// ========================================
	// Update Validation Errors
	// ========================================

	/**
	 * Test error when pack is not installed.
	 */
	public function testUpdatePacks_WithPackNotInstalled_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.2.0', 'depends_on' => [] ] ],
			[]
		);

		// Don't install the pack - try to update a non-existent pack

		$this->expectException( \ApiUsageException::class );

		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when major version changes.
	 */
	public function testUpdatePacks_WithMajorVersionChange_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '2.0.0', 'depends_on' => [] ] ],
			[]
		);

		// Install pack with v1.x
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.5.0', 1 );

		$this->expectException( \ApiUsageException::class );

		// Try to update to v2.x (major version change)
		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'TestPack', 'target_version' => '2.0.0' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test error when pack has dependents that are not being updated.
	 */
	public function testUpdatePacks_WithDependentsNotUpdating_ReturnsError(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$this->createTestManifest(
			[
				'BasePackage' => [ 'name' => 'BasePackage', 'version' => '1.5.0', 'depends_on' => [] ],
				'DependentPackage' => [ 'name' => 'DependentPackage', 'version' => '1.0.0', 'depends_on' => [ 'BasePackage' ] ],
			],
			[]
		);

		// Install both packs
		$basePackId = $this->packRegistry->registerPack( $refId, 'BasePackage', '1.0.0', 1 );
		$depPackId = $this->packRegistry->registerPack( $refId, 'DependentPackage', '1.0.0', 1 );

		// Store dependency in database (DependentPackage depends on BasePackage)
		$this->packRegistry->storeDependencies( $depPackId, [ $basePackId ] );

		$this->expectException( \ApiUsageException::class );

		// Try to update BasePackage alone (DependentPackage depends on it)
		$this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [ [ 'name' => 'BasePackage', 'target_version' => '1.5.0' ] ] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test success when updating pack with dependencies together.
	 */
	public function testUpdatePacks_WithDependentsAlsoUpdating_Success(): void {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );
		$this->createTestManifest(
			[
				'BasePackage' => [ 'name' => 'BasePackage', 'version' => '1.5.0', 'depends_on' => [] ],
				'DependentPackage' => [ 'name' => 'DependentPackage', 'version' => '1.1.0', 'depends_on' => [ 'BasePackage' ] ],
			],
			[]
		);

		// Install both packs
		$basePackId = $this->packRegistry->registerPack( $refId, 'BasePackage', '1.0.0', 1 );
		$depPackId = $this->packRegistry->registerPack( $refId, 'DependentPackage', '1.0.0', 1 );

		// Store dependency in database
		$this->packRegistry->storeDependencies( $depPackId, [ $basePackId ] );

		// Update both together (should succeed)
		$result = $this->doApiRequest( [
			'action' => 'labkiPacksUpdate',
			'repo_id' => $repoId->toInt(),
			'ref' => 'main',
			'packs' => json_encode( [
				[ 'name' => 'BasePackage', 'target_version' => '1.5.0' ],
				[ 'name' => 'DependentPackage', 'target_version' => '1.1.0' ],
			] ),
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		$this->assertTrue( $data['success'] );
		$this->assertCount( 2, $data['packs'] );
	}
}


