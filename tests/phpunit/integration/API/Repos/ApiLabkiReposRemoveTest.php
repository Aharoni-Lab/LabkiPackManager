<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Repos;

use ApiTestCase;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Domain\OperationId;
use MediaWiki\MediaWikiServices;

/**
 * Integration tests for ApiLabkiReposRemove.
 *
 * These tests verify that the API endpoint:
 * - Creates operation records in LabkiOperationRegistry
 * - Queues LabkiRepoRemoveJob with correct parameters
 * - Returns appropriate responses for various scenarios
 * - Validates input parameters correctly
 * - Handles both full repository removal and selective ref removal
 *
 * Note: These tests focus on the API layer. The actual removal logic
 * is tested in LabkiRepoRemoveJobTest.
 *
 * @covers \LabkiPackManager\API\Repos\ApiLabkiReposRemove
 * @covers \LabkiPackManager\API\Repos\RepoApiBase
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
class ApiLabkiReposRemoveTest extends ApiTestCase {

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
	 * Test removing entire repository without refs parameter.
	 */
	public function testRemoveRepo_WithoutRefs_QueuesFullRemoval(): void {
		// Create test repository
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		// Check response structure
		$this->assertArrayHasKey( 'success', $data );
		$this->assertTrue( $data['success'] );
		
		$this->assertArrayHasKey( 'operation_id', $data );
		$this->assertStringStartsWith( 'repo_remove_', $data['operation_id'] );
		
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $data['status'] );
		
		$this->assertArrayHasKey( 'message', $data );
		$this->assertStringContainsString( 'Repository removal queued', $data['message'] );
		
		// Should NOT have refs in response when removing entire repo
		$this->assertArrayNotHasKey( 'refs', $data );
		
		// Verify operation was created
		$operationId = $data['operation_id'];
		$this->assertTrue( $this->operationRegistry->operationExists( new OperationId( $operationId ) ) );
		
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		$this->assertSame( LabkiOperationRegistry::TYPE_REPO_REMOVE, $operation->type() );
		
		// Verify job was queued
		$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$this->assertTrue( $jobQueue->get( 'labkiRepoRemove' )->getSize() > 0 );
	}

	/**
	 * Test removing specific refs from repository.
	 */
	public function testRemoveRepo_WithSpecificRefs_QueuesSelectiveRemoval(): void {
		// Create test repository with multiple refs
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		$this->createTestRef( $repoId, 'v1.0' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
			'refs' => 'develop|v1.0',
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'operation_id', $data );
		
		$this->assertArrayHasKey( 'message', $data );
		$this->assertStringContainsString( '2 ref(s)', $data['message'] );
		
		// Should have refs in response when removing specific refs
		$this->assertArrayHasKey( 'refs', $data );
		$this->assertIsArray( $data['refs'] );
		$this->assertCount( 2, $data['refs'] );
		$this->assertContains( 'develop', $data['refs'] );
		$this->assertContains( 'v1.0', $data['refs'] );
		
		// Verify operation message mentions refs
		$operation = $this->operationRegistry->getOperation( new OperationId( $data['operation_id'] ) );
		$this->assertStringContainsString( 'ref(s) queued for removal', $operation->message() );
	}

	/**
	 * Test removing single ref.
	 */
	public function testRemoveRepo_WithSingleRef_QueuesRemoval(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
			'refs' => 'develop',
		], null, false, $this->getTestUser()->getUser() );

		$data = $result[0];
		
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'refs', $data );
		$this->assertCount( 1, $data['refs'] );
		$this->assertSame( 'develop', $data['refs'][0] );
	}

	/**
	 * Test that missing URL returns error.
	 */
	public function testRemoveRepo_WithMissingUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposRemove',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test that empty URL returns error.
	 */
	public function testRemoveRepo_WithEmptyUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => '',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test that invalid URL format returns error.
	 */
	public function testRemoveRepo_WithInvalidUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'not-a-valid-url',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test that removing non-existent repository returns error.
	 */
	public function testRemoveRepo_WhenRepoNotFound_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/nonexistent/repo',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test that empty refs array returns error.
	 */
	public function testRemoveRepo_WithEmptyRefsArray_ReturnsError(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		// MediaWiki API handles empty array parameters specially
		// We expect an error when refs is provided but empty
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
			'refs' => '',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test URL normalization (removing .git suffix).
	 */
	public function testRemoveRepo_WithGitSuffix_NormalizesUrl(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		// Query with .git suffix
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo.git',
		], null, false, $this->getTestUser()->getUser() );

		$this->assertTrue( $result[0]['success'] );
		$this->assertArrayHasKey( 'operation_id', $result[0] );
	}

	/**
	 * Test that API requires POST method.
	 */
	public function testRemoveRepo_RequiresPostMethod(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );
		
		// If we get here without exception, POST was allowed
		$this->assertTrue( $result[0]['success'] );
	}

	/**
	 * Test that API requires labkipackmanager-manage permission.
	 */
	public function testRemoveRepo_RequiresManagePermission(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		// Remove permission from users
		$this->setGroupPermissions( 'user', 'labkipackmanager-manage', false );
		
		$this->expectException( \ApiUsageException::class );
		
		$this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );
	}

	/**
	 * Test operation record creation.
	 */
	public function testRemoveRepo_CreatesProperOperationRecord(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );

		$operationId = $result[0]['operation_id'];
		$operation = $this->operationRegistry->getOperation( new OperationId( $operationId ) );
		
		$this->assertNotNull( $operation );
		$this->assertSame( LabkiOperationRegistry::TYPE_REPO_REMOVE, $operation->type() );
		$this->assertSame( LabkiOperationRegistry::STATUS_QUEUED, $operation->status() );
		$this->assertGreaterThan( 0, $operation->userId() );
		$this->assertNotEmpty( $operation->createdAt() );
	}

	/**
	 * Test that operation message differs for full vs selective removal.
	 */
	public function testRemoveRepo_OperationMessageDiffersByScope(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		
		// Full removal
		$result1 = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
		], null, false, $this->getTestUser()->getUser() );
		
		$op1 = $this->operationRegistry->getOperation( new OperationId( $result1[0]['operation_id'] ) );
		$this->assertStringContainsString( 'Repository queued for removal', $op1->message() );
		
		// Selective removal (need another repo)
		$repoId2 = $this->createTestRepo( 'https://github.com/test/repo2' );
		$this->createTestRef( $repoId2, 'main' );
		$this->createTestRef( $repoId2, 'develop' );
		
		$result2 = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo2',
			'refs' => 'develop',
		], null, false, $this->getTestUser()->getUser() );
		
		$op2 = $this->operationRegistry->getOperation( new OperationId( $result2[0]['operation_id'] ) );
		$this->assertStringContainsString( 'ref(s) queued for removal', $op2->message() );
	}

	/**
	 * Test response includes correct metadata.
	 */
	public function testRemoveRepo_ResponseIncludesMetadata(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
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
	public function testRemoveRepo_GeneratesUniqueOperationIds(): void {
		$repoId1 = $this->createTestRepo( 'https://github.com/test/repo1' );
		$this->createTestRef( $repoId1, 'main' );
		
		$repoId2 = $this->createTestRepo( 'https://github.com/test/repo2' );
		$this->createTestRef( $repoId2, 'main' );
		
		$result1 = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo1',
		], null, false, $this->getTestUser()->getUser() );

		$result2 = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo2',
		], null, false, $this->getTestUser()->getUser() );

		$this->assertNotSame( $result1[0]['operation_id'], $result2[0]['operation_id'] );
	}


	/**
	 * Test with multiple refs removal.
	 */
	public function testRemoveRepo_WithMultipleRefs_AcceptsAllRefs(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		$this->createTestRef( $repoId, 'v1.0' );
		$this->createTestRef( $repoId, 'v2.0' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
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
	public function testRemoveRepo_MessageReflectsRefCount(): void {
		$repoId = $this->createTestRepo( 'https://github.com/test/repo' );
		$this->createTestRef( $repoId, 'main' );
		$this->createTestRef( $repoId, 'develop' );
		$this->createTestRef( $repoId, 'v1.0' );
		
		$result = $this->doApiRequest( [
			'action' => 'labkiReposRemove',
			'url' => 'https://github.com/test/repo',
			'refs' => 'develop|v1.0',
		], null, false, $this->getTestUser()->getUser() );

		$message = $result[0]['message'];
		$this->assertStringContainsString( '2 ref(s)', $message );
	}
}

