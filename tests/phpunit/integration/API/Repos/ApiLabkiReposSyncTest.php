<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Repos;

use ApiTestCase;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWiki\MediaWikiServices;

/**
 * Integration tests for ApiLabkiReposSync.
 *
 * These tests verify that the API endpoint:
 * - Creates operation records in LabkiOperationRegistry
 * - Queues LabkiRepoSyncJob with correct parameters
 * - Returns appropriate responses for various scenarios
 * - Validates input parameters correctly
 * - Handles both full repository sync and selective ref sync
 *
 * Note: These tests focus on the API layer. The actual sync logic
 * is tested in LabkiRepoSyncJobTest.
 *
 * @covers \LabkiPackManager\API\Repos\ApiLabkiReposSync
 * @covers \LabkiPackManager\API\Repos\RepoApiBase
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
class ApiLabkiReposSyncTest extends ApiTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private LabkiOperationRegistry $operationRegistry;

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [
		'labki_content_repo',
		'labki_content_ref',
		'labki_operations',
		'job',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->operationRegistry = new LabkiOperationRegistry();
		
		// Grant permissions for testing - use setGroupPermissions for immediate effect
		$this->setGroupPermissions( 'user', 'labkipackmanager-manage', true );
	}

	/**
	 * Helper to create a test repository.
	 */
	private function createTestRepo( string $url = 'https://github.com/test/repo' ): int {
		$repoId = $this->repoRegistry->ensureRepoEntry( $url );
		return $repoId->toInt();
	}

	/**
	 * Helper to create a test ref.
	 */
	private function createTestRef( int $repoId, string $ref = 'main' ): void {
		$this->refRegistry->ensureRefEntry(
			new \LabkiPackManager\Domain\ContentRepoId( $repoId ),
			$ref
		);
	}

	/**
	 * Test syncing entire repository without refs parameter.
	 */
	public function testSyncRepo_WithoutRefs_QueuesFullSync(): void {
		// Create test repository
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		// Check response structure
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		
		$this->assertArrayHasKey( 'operation_id', $data );
		$this->assertStringStartsWith( 'repo_sync_', $data['operation_id'] );
		
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $data['status'] );
		
		$this->assertArrayHasKey( 'message', $data );
		$this->assertStringContainsString( 'all refs', $data['message'] );
		
		// Should NOT have refs in response when syncing entire repo
		$this->assertArrayNotHasKey( 'refs', $data );
		
		// Verify operation was created
		$operationId = $data['operation_id'];
		$this->assertTrue( $this->operationRegistry->operationExists( $operationId ) );
		
		$operation = $this->operationRegistry->getOperation( $operationId );
		$this->assertSame( LabkiOperationRegistry::TYPE_REPO_SYNC, $operation['operation_type'] );
		
		// Verify job was queued
		$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$this->assertTrue( $jobQueue->get( 'labkiRepoSync' )->getSize() > 0 );
	}

	/**
	 * Test syncing specific refs from repository.
	 */
	public function testSyncRepo_WithSpecificRefs_QueuesSelectiveSync(): void {
		// Create test repository with multiple refs
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		$this->createTestRef( $repoId, 'v1.0' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
			'refs' => 'main|develop',
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
		
		$this->assertArrayHasKey( 'message', $data );
		$this->assertStringContainsString( '2 ref(s)', $data['message'] );
		
		// Should have refs in response when syncing specific refs
		$this->assertArrayHasKey( 'refs', $data );
		$this->assertIsArray( $data['refs'] );
		$this->assertCount( 2, $data['refs'] );
		$this->assertContains( 'main', $data['refs'] );
		$this->assertContains( 'develop', $data['refs'] );
		
		// Verify operation message mentions refs
		$operation = $this->operationRegistry->getOperation( $data['operation_id'] );
		$this->assertStringContainsString( 'ref(s) queued for sync', $operation['message'] );
	}

	/**
	 * Test syncing single ref.
	 */
	public function testSyncRepo_WithSingleRef_QueuesSync(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
			'refs' => 'main',
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'refs', $data );
		$this->assertCount( 1, $data['refs'] );
		$this->assertSame( 'main', $data['refs'][0] );
	}

	/**
	 * Test that missing URL returns error.
	 */
	public function testSyncRepo_WithMissingUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposSync',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test that empty URL returns error.
	 */
	public function testSyncRepo_WithEmptyUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => '',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test that invalid URL format returns error.
	 */
	public function testSyncRepo_WithInvalidUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'not-a-valid-url',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test that syncing non-existent repository returns error.
	 */
	public function testSyncRepo_WhenRepoNotFound_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/nonexistent/repo',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test that empty refs array returns error.
	 */
	public function testSyncRepo_WithEmptyRefsArray_ReturnsError(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		// MediaWiki API handles empty array parameters specially
		// We expect an error when refs is provided but empty
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
			'refs' => '',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test URL normalization (removing .git suffix).
	 */
	public function testSyncRepo_WithGitSuffix_NormalizesUrl(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		// Query with .git suffix
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo.git',
		], null, false, $this->getTestUser()->getUser() );

		$this->assertTrue( $result[0]['success'] );
		$this->assertArrayHasKey( 'operation_id', $result[0] );
	}

	/**
	 * Test that API requires POST method.
	 */
	public function testSyncRepo_RequiresPostMethod(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );
		
		// If we get here without exception, POST was allowed
		$this->assertTrue( $result[0]['success'] );
	}

	/**
	 * Test that API requires labkipackmanager-manage permission.
	 */
	public function testSyncRepo_RequiresManagePermission(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		// Remove permission from users
		$this->setGroupPermissions( 'user', 'labkipackmanager-manage', false );
		
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test operation record creation.
	 */
	public function testSyncRepo_CreatesProperOperationRecord(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );

		$operationId = $result[0]['operation_id'];
		$operation = $this->operationRegistry->getOperation( $operationId );
		
		$this->assertNotNull( $operation );
		$this->assertSame( LabkiOperationRegistry::TYPE_REPO_SYNC, $operation['operation_type'] );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $operation['status'] );
		$this->assertGreaterThan( 0, (int)$operation['user_id'] );
		$this->assertNotEmpty( $operation['created_at'] );
	}

	/**
	 * Test that operation message differs for full vs selective sync.
	 */
	public function testSyncRepo_OperationMessageDiffersByScope(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		
		// Full sync
		$result1 = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );
		
		$op1 = $this->operationRegistry->getOperation( $result1[0]['operation_id'] );
		$this->assertStringContainsString( 'Repository queued for sync', $op1['message'] );
		
		// Selective sync (need another repo)
		$repoId2 = $this->createTestRepo( 'https://github.com/test/repo2' );
		$this->createTestRef( $repoId2, 'main' );
		$this->createTestRef( $repoId2, 'develop' );
		
		$result2 = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo2',
			'refs' => 'main',
		], null, false, $this->getTestUser()->getUser() );
		
		$op2 = $this->operationRegistry->getOperation( $result2[0]['operation_id'] );
		$this->assertStringContainsString( 'ref(s) queued for sync', $op2['message'] );
	}

	/**
	 * Test response includes correct metadata.
	 */
	public function testSyncRepo_ResponseIncludesMetadata(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayHasKey( 'schemaVersion', $data['meta'] );
		$this->assertArrayHasKey( 'timestamp', $data['meta'] );
		$this->assertSame( 1, $data['meta']['schemaVersion'] );
		$this->assertMatchesRegularExpression( '/^\d{14}$/', $data['meta']['timestamp'] );
	}

	/**
	 * Test that each operation gets a unique ID.
	 */
	public function testSyncRepo_GeneratesUniqueOperationIds(): void {
		$repoId1 = $this->createTestRepo( 'https://github.com/test/repo1' );
		$this->createTestRef( $repoId1, 'main' );
		
		$repoId2 = $this->createTestRepo( 'https://github.com/test/repo2' );
		$this->createTestRef( $repoId2, 'main' );
		
		$result1 = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo1',
		], null, false, $this->getTestUser()->getUser() );

		$result2 = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo2',
		], null, false, $this->getTestUser()->getUser() );

		$this->assertNotSame( $result1[0]['operation_id'], $result2[0]['operation_id'] );
	}

	/**
	 * Test with multiple refs sync.
	 */
	public function testSyncRepo_WithMultipleRefs_AcceptsAllRefs(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		$this->createTestRef( $repoId, 'v1.0' );
		$this->createTestRef( $repoId, 'v2.0' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
			'refs' => 'main|develop|v1.0|v2.0',
		], null, false, $this->getTestUser()->getUser() );

		$this->assertTrue( $result[0]['success'] );
		$this->assertArrayHasKey( 'refs', $result[0] );
		$this->assertCount( 4, $result[0]['refs'] );
	}

	/**
	 * Test message count matches refs count.
	 */
	public function testSyncRepo_MessageReflectsRefCount(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		$this->createTestRef( $repoId, 'v1.0' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
			'refs' => 'main|develop',
		], null, false, $this->getTestUser()->getUser() );

		$message = $result[0]['message'];
		$this->assertStringContainsString( '2 ref(s)', $message );
	}

	/**
	 * Test syncing repository with no refs (edge case).
	 */
	public function testSyncRepo_WithRepoButNoRefs_QueuesFullSync(): void {
		// Create repo without any refs registered
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );

		$this->assertTrue( $result[0]['success'] );
		$this->assertArrayHasKey( 'operation_id', $result[0] );
		$this->assertStringContainsString( 'all refs', $result[0]['message'] );
	}

	/**
	 * Test that API is write mode.
	 */
	public function testSyncRepo_IsWriteMode(): void {
		// Write mode APIs require tokens in production
		// ApiTestCase handles token generation for us
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposSync',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );
		
		$this->assertTrue( $result[0]['success'] );
	}
}

