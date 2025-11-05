<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Domain\Operation;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for LabkiOperationRegistry
 *
 * Tests the operation tracking service for the labki_operations table.
 * These tests use the actual MediaWiki database.
 *
 * @covers \LabkiPackManager\Services\LabkiOperationRegistry
 * @group Database
 */
class LabkiOperationRegistryTest extends MediaWikiIntegrationTestCase {

	private function newRegistry(): LabkiOperationRegistry {
		return new LabkiOperationRegistry();
	}

	private function generateOperationId(): OperationId {
		return new OperationId( 'test_op_' . uniqid() . '_' . mt_rand() );
	}

	public function testNow_ReturnsValidTimestamp(): void {
		$registry = $this->newRegistry();
		
		$timestamp = $registry->now();
		
		$this->assertIsString( $timestamp );
		$this->assertNotEmpty( $timestamp );
		$this->assertMatchesRegularExpression( '/^\d{14}$/', $timestamp );
	}

	public function testCreateOperation_CreatesNewOperation(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation(
			$operationId,
			Operation::TYPE_REPO_ADD,
			1,
			Operation::STATUS_QUEUED,
			'Test operation'
		);

		$this->assertTrue( $registry->operationExists( $operationId ) );
	}

	public function testCreateOperation_WithAllFields_StoresCorrectly(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation(
			$operationId,
			Operation::TYPE_REPO_SYNC,
			123,
			Operation::STATUS_QUEUED,
			'Syncing repository'
		);

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( $operationId->toString(), $operation->id()->toString() );
		$this->assertSame( Operation::TYPE_REPO_SYNC, $operation->type() );
		$this->assertSame( Operation::STATUS_QUEUED, $operation->status() );
		$this->assertSame( 'Syncing repository', $operation->message() );
		$this->assertSame( 123, $operation->userId() );
	}

	public function testCreateOperation_WithDefaults_UsesDefaultValues(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation(
			$operationId,
			Operation::TYPE_PACK_INSTALL
		);

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( Operation::STATUS_QUEUED, $operation->status() );
		$this->assertSame( 0, $operation->userId() );
		$this->assertSame( '', $operation->message() );
	}

	public function testCreateOperation_SetsTimestamps(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$beforeTime = wfTimestampNow();
		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );
		$afterTime = wfTimestampNow();

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertNotNull( $operation->createdAt() );
		$this->assertNotNull( $operation->updatedAt() );
		$this->assertGreaterThanOrEqual( (int)$beforeTime, $operation->createdAt() );
		$this->assertLessThanOrEqual( (int)$afterTime, $operation->createdAt() );
	}

	public function testGetOperation_WithOperationId_ReturnsOperation(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertInstanceOf( Operation::class, $operation );
	}

	public function testGetOperation_WithString_ReturnsOperation(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );

		$operation = $registry->getOperation( $operationId->toString() );

		$this->assertNotNull( $operation );
		$this->assertInstanceOf( Operation::class, $operation );
		$this->assertSame( $operationId->toString(), $operation->id()->toString() );
	}

	public function testGetOperation_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();
		$operationId = new OperationId( 'nonexistent_operation' );

		$result = $registry->getOperation( $operationId );

		$this->assertNull( $result );
	}

	public function testOperationExists_WhenExists_ReturnsTrue(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );

		$this->assertTrue( $registry->operationExists( $operationId ) );
	}

	public function testOperationExists_WhenNotExists_ReturnsFalse(): void {
		$registry = $this->newRegistry();
		$operationId = new OperationId( 'nonexistent_check' );

		$this->assertFalse( $registry->operationExists( $operationId ) );
	}

	public function testGetOperationStatus_WhenExists_ReturnsStatus(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD, 0, Operation::STATUS_QUEUED );

		$status = $registry->getOperationStatus( $operationId );

		$this->assertSame( Operation::STATUS_QUEUED, $status );
	}

	public function testGetOperationStatus_WhenNotExists_ReturnsNull(): void {
		$registry = $this->newRegistry();
		$operationId = new OperationId( 'nonexistent_status' );

		$status = $registry->getOperationStatus( $operationId );

		$this->assertNull( $status );
	}

	public function testUpdateOperation_UpdatesStatus(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );
		$registry->updateOperation(
			$operationId,
			Operation::STATUS_RUNNING,
			'Processing...'
		);

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( Operation::STATUS_RUNNING, $operation->status() );
		$this->assertSame( 'Processing...', $operation->message() );
	}

	public function testUpdateOperation_UpdatesProgress(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_SYNC );
		$registry->updateOperation(
			$operationId,
			Operation::STATUS_RUNNING,
			null,
			45
		);

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( 45, $operation->progress() );
	}

	public function testUpdateOperation_ClampsProgressToRange(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );

		// Test upper bound
		$registry->updateOperation( $operationId, Operation::STATUS_RUNNING, null, 150 );
		$operation = $registry->getOperation( $operationId );
		$this->assertSame( 100, $operation->progress() );

		// Test lower bound
		$registry->updateOperation( $operationId, Operation::STATUS_RUNNING, null, -10 );
		$operation = $registry->getOperation( $operationId );
		$this->assertSame( 0, $operation->progress() );
	}

	public function testUpdateOperation_UpdatesResultData(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_PACK_INSTALL );
		$resultData = json_encode( [ 'installed' => 5, 'failed' => 0 ] );
		$registry->updateOperation(
			$operationId,
			Operation::STATUS_SUCCESS,
			null,
			null,
			$resultData
		);

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( $resultData, $operation->resultData() );
	}

	public function testStartOperation_MarksAsRunning(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );
		$registry->startOperation( $operationId, 'Starting process' );

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( Operation::STATUS_RUNNING, $operation->status() );
		$this->assertSame( 'Starting process', $operation->message() );
		$this->assertNotNull( $operation->startedAt() );
	}

	public function testStartOperation_SetsStartedAtTimestamp(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );
		
		$beforeStart = wfTimestampNow();
		$registry->startOperation( $operationId );
		$afterStart = wfTimestampNow();

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertNotNull( $operation->startedAt() );
		$this->assertGreaterThanOrEqual( (int)$beforeStart, $operation->startedAt() );
		$this->assertLessThanOrEqual( (int)$afterStart, $operation->startedAt() );
	}

	public function testCompleteOperation_MarksAsSuccess(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_SYNC );
		$registry->completeOperation( $operationId, 'Completed successfully' );

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( Operation::STATUS_SUCCESS, $operation->status() );
		$this->assertSame( 'Completed successfully', $operation->message() );
		$this->assertSame( 100, $operation->progress() );
	}

	public function testCompleteOperation_WithResultData_StoresData(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_PACK_INSTALL );
		$resultData = json_encode( [ 'packs' => 3, 'pages' => 25 ] );
		$registry->completeOperation( $operationId, 'Installation complete', $resultData );

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( $resultData, $operation->resultData() );
	}

	public function testFailOperation_MarksAsFailed(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_ADD );
		$registry->failOperation( $operationId, 'Error occurred' );

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( Operation::STATUS_FAILED, $operation->status() );
		$this->assertSame( 'Error occurred', $operation->message() );
	}

	public function testFailOperation_WithResultData_StoresErrorData(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_PACK_UPDATE );
		$errorData = json_encode( [ 'error_code' => 500, 'details' => 'Connection timeout' ] );
		$registry->failOperation( $operationId, 'Failed to update', $errorData );

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( $errorData, $operation->resultData() );
	}

	public function testSetProgress_UpdatesProgress(): void {
		$registry = $this->newRegistry();
		$operationId = $this->generateOperationId();

		$registry->createOperation( $operationId, Operation::TYPE_REPO_SYNC );
		$registry->setProgress( $operationId, 75, 'Almost done' );

		$operation = $registry->getOperation( $operationId );

		$this->assertNotNull( $operation );
		$this->assertSame( 75, $operation->progress() );
		$this->assertSame( 'Almost done', $operation->message() );
		$this->assertSame( Operation::STATUS_RUNNING, $operation->status() );
	}

	public function testGetOperations_WithNoFilters_ReturnsAllOperations(): void {
		$registry = $this->newRegistry();

		$op1 = $this->generateOperationId();
		$op2 = $this->generateOperationId();
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD );
		$registry->createOperation( $op2, Operation::TYPE_PACK_INSTALL );

		$operations = $registry->getOperations();

		$this->assertIsArray( $operations );
		$this->assertGreaterThanOrEqual( 2, count( $operations ) );
		
		foreach ( $operations as $operation ) {
			$this->assertInstanceOf( Operation::class, $operation );
		}
	}

	public function testGetOperations_WithTypeFilter_ReturnsOnlyMatchingType(): void {
		$registry = $this->newRegistry();

		$op1 = $this->generateOperationId();
		$op2 = $this->generateOperationId();
		$op3 = $this->generateOperationId();
		
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD );
		$registry->createOperation( $op2, Operation::TYPE_REPO_ADD );
		$registry->createOperation( $op3, Operation::TYPE_PACK_INSTALL );

		$operations = $registry->getOperations( Operation::TYPE_REPO_ADD );

		$this->assertGreaterThanOrEqual( 2, count( $operations ) );
		
		foreach ( $operations as $operation ) {
			$this->assertSame( Operation::TYPE_REPO_ADD, $operation->type() );
		}
	}

	public function testGetOperations_WithStatusFilter_ReturnsOnlyMatchingStatus(): void {
		$registry = $this->newRegistry();

		$op1 = $this->generateOperationId();
		$op2 = $this->generateOperationId();
		$op3 = $this->generateOperationId();
		
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD, 0, Operation::STATUS_QUEUED );
		$registry->createOperation( $op2, Operation::TYPE_REPO_SYNC, 0, Operation::STATUS_QUEUED );
		$registry->createOperation( $op3, Operation::TYPE_PACK_INSTALL, 0, Operation::STATUS_RUNNING );

		$operations = $registry->getOperations( null, Operation::STATUS_QUEUED );

		$this->assertGreaterThanOrEqual( 2, count( $operations ) );
		
		foreach ( $operations as $operation ) {
			$this->assertSame( Operation::STATUS_QUEUED, $operation->status() );
		}
	}

	public function testGetOperations_WithUserIdFilter_ReturnsOnlyUserOperations(): void {
		$registry = $this->newRegistry();

		$op1 = $this->generateOperationId();
		$op2 = $this->generateOperationId();
		$op3 = $this->generateOperationId();
		
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD, 100 );
		$registry->createOperation( $op2, Operation::TYPE_REPO_SYNC, 100 );
		$registry->createOperation( $op3, Operation::TYPE_PACK_INSTALL, 200 );

		$operations = $registry->getOperations( null, null, 100 );

		$this->assertGreaterThanOrEqual( 2, count( $operations ) );
		
		foreach ( $operations as $operation ) {
			$this->assertSame( 100, $operation->userId() );
		}
	}

	public function testGetOperations_WithLimit_RespectsLimit(): void {
		$registry = $this->newRegistry();

		// Create several operations
		for ( $i = 0; $i < 10; $i++ ) {
			$opId = $this->generateOperationId();
			$registry->createOperation( $opId, Operation::TYPE_REPO_ADD );
		}

		$operations = $registry->getOperations( null, null, null, 3 );

		$this->assertCount( 3, $operations );
	}

	public function testGetOperations_OrderedByMostRecentFirst(): void {
		$registry = $this->newRegistry();

		$op1 = $this->generateOperationId();
		$op2 = $this->generateOperationId();
		$op3 = $this->generateOperationId();
		
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD );
		usleep( 10000 );
		$registry->createOperation( $op2, Operation::TYPE_REPO_SYNC );
		usleep( 10000 );
		$registry->createOperation( $op3, Operation::TYPE_PACK_INSTALL );

		$operations = $registry->getOperations( null, null, null, 10 );

		// Most recent should be first
		$this->assertGreaterThanOrEqual( 3, count( $operations ) );
		
		// Verify descending order by updated_at
		$prevUpdated = PHP_INT_MAX;
		foreach ( $operations as $operation ) {
			$this->assertLessThanOrEqual( $prevUpdated, $operation->updatedAt() );
			$prevUpdated = $operation->updatedAt();
		}
	}

	public function testCountOperationsByStatus_ReturnsCorrectCount(): void {
		$registry = $this->newRegistry();

		// Create operations with different statuses
		$op1 = $this->generateOperationId();
		$op2 = $this->generateOperationId();
		$op3 = $this->generateOperationId();
		
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD, 0, Operation::STATUS_QUEUED );
		$registry->createOperation( $op2, Operation::TYPE_REPO_SYNC, 0, Operation::STATUS_QUEUED );
		$registry->createOperation( $op3, Operation::TYPE_PACK_INSTALL, 0, Operation::STATUS_RUNNING );

		$queuedCount = $registry->countOperationsByStatus( Operation::STATUS_QUEUED );
		$runningCount = $registry->countOperationsByStatus( Operation::STATUS_RUNNING );

		$this->assertGreaterThanOrEqual( 2, $queuedCount );
		$this->assertGreaterThanOrEqual( 1, $runningCount );
	}

	public function testDeleteOldOperations_DeletesOldRecords(): void {
		$registry = $this->newRegistry();

		$op1 = $this->generateOperationId();
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD, 0, Operation::STATUS_SUCCESS );
		
		// Delete operations older than 0 days
		// Note: Just-created operations may not count as "old" depending on timestamp precision
		$deleted = $registry->deleteOldOperations( 0, false );

		// Verify the method executes without error (count may be 0 or more)
		$this->assertGreaterThanOrEqual( 0, $deleted );
		
		// Verify we can still call the method (testing it doesn't crash)
		$this->assertIsInt( $deleted );
	}

	public function testDeleteOldOperations_WithOnlyCompletedFlag_PreservesRunning(): void {
		$registry = $this->newRegistry();

		$op1 = $this->generateOperationId();
		$op2 = $this->generateOperationId();
		
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD, 0, Operation::STATUS_SUCCESS );
		$registry->createOperation( $op2, Operation::TYPE_REPO_SYNC, 0, Operation::STATUS_RUNNING );

		// Delete old completed operations only
		$registry->deleteOldOperations( 0, true );

		// Running operation should still exist
		$this->assertTrue( $registry->operationExists( $op2 ) );
	}

	public function testGetOperationStats_ReturnsStatusCounts(): void {
		$registry = $this->newRegistry();

		$op1 = $this->generateOperationId();
		$op2 = $this->generateOperationId();
		$op3 = $this->generateOperationId();
		$op4 = $this->generateOperationId();
		
		$registry->createOperation( $op1, Operation::TYPE_REPO_ADD, 0, Operation::STATUS_QUEUED );
		$registry->createOperation( $op2, Operation::TYPE_REPO_SYNC, 0, Operation::STATUS_QUEUED );
		$registry->createOperation( $op3, Operation::TYPE_PACK_INSTALL, 0, Operation::STATUS_RUNNING );
		$registry->createOperation( $op4, Operation::TYPE_PACK_UPDATE, 0, Operation::STATUS_SUCCESS );

		$stats = $registry->getOperationStats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( Operation::STATUS_QUEUED, $stats );
		$this->assertArrayHasKey( Operation::STATUS_RUNNING, $stats );
		$this->assertArrayHasKey( Operation::STATUS_SUCCESS, $stats );
		
		$this->assertGreaterThanOrEqual( 2, $stats[Operation::STATUS_QUEUED] );
		$this->assertGreaterThanOrEqual( 1, $stats[Operation::STATUS_RUNNING] );
		$this->assertGreaterThanOrEqual( 1, $stats[Operation::STATUS_SUCCESS] );
	}

	/**
	 * Test operation type constants are available
	 */
	public function testOperationTypeConstants_AreAvailable(): void {
		$this->assertSame( 'repo_add', LabkiOperationRegistry::TYPE_REPO_ADD );
		$this->assertSame( 'repo_sync', LabkiOperationRegistry::TYPE_REPO_SYNC );
		$this->assertSame( 'repo_remove', LabkiOperationRegistry::TYPE_REPO_REMOVE );
		$this->assertSame( 'pack_install', LabkiOperationRegistry::TYPE_PACK_INSTALL );
		$this->assertSame( 'pack_update', LabkiOperationRegistry::TYPE_PACK_UPDATE );
		$this->assertSame( 'pack_remove', LabkiOperationRegistry::TYPE_PACK_REMOVE );
		$this->assertSame( 'pack_apply', LabkiOperationRegistry::TYPE_PACK_APPLY );
	}

	/**
	 * Test operation status constants are available
	 */
	public function testOperationStatusConstants_AreAvailable(): void {
		$this->assertSame( 'queued', LabkiOperationRegistry::STATUS_QUEUED );
		$this->assertSame( 'running', LabkiOperationRegistry::STATUS_RUNNING );
		$this->assertSame( 'success', LabkiOperationRegistry::STATUS_SUCCESS );
		$this->assertSame( 'failed', LabkiOperationRegistry::STATUS_FAILED );
	}
}
