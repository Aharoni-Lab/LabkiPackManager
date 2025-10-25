<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services;

use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWikiIntegrationTestCase;

/**
 * Tests for LabkiOperationRegistry
 *
 * @coversDefaultClass \LabkiPackManager\Services\LabkiOperationRegistry
 * @group Database
 */
final class LabkiOperationRegistryTest extends MediaWikiIntegrationTestCase {

    private function newRegistry(): LabkiOperationRegistry {
        return new LabkiOperationRegistry();
    }

    /**
     * @covers ::createOperation
     * @covers ::operationExists
     */
    public function testCreateOperation_CreatesNewOperation(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation(
            $operationId,
            LabkiOperationRegistry::TYPE_REPO_ADD,
            1,
            LabkiOperationRegistry::STATUS_QUEUED,
            'Test operation'
        );

        $this->assertTrue($registry->operationExists($operationId));
    }

    /**
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testCreateOperation_WithAllFields_StoresCorrectly(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation(
            $operationId,
            LabkiOperationRegistry::TYPE_REPO_SYNC,
            123,
            LabkiOperationRegistry::STATUS_QUEUED,
            'Syncing repository'
        );

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame($operationIdStr, $operation->id()->toString());
        $this->assertSame(LabkiOperationRegistry::TYPE_REPO_SYNC, $operation->type());
        $this->assertSame(LabkiOperationRegistry::STATUS_QUEUED, $operation->status());
        $this->assertSame('Syncing repository', $operation->message());
        $this->assertSame(123, $operation->userId());
    }

    /**
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testCreateOperation_WithDefaults_UsesDefaultValues(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation(
            $operationId,
            LabkiOperationRegistry::TYPE_PACK_INSTALL
        );

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame(LabkiOperationRegistry::STATUS_QUEUED, $operation->status());
        $this->assertSame(0, $operation->userId());
        $this->assertSame('', $operation->message());
    }

    /**
     * @covers ::updateOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testUpdateOperation_UpdatesStatus(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->updateOperation(
            $operationId,
            LabkiOperationRegistry::STATUS_RUNNING,
            'Processing...'
        );

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame(LabkiOperationRegistry::STATUS_RUNNING, $operation->status());
        $this->assertSame('Processing...', $operation->message());
    }

    /**
     * @covers ::updateOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testUpdateOperation_UpdatesProgress(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_SYNC);
        $registry->updateOperation(
            $operationId,
            LabkiOperationRegistry::STATUS_RUNNING,
            null,
            45
        );

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame(45, $operation->progress());
    }

    /**
     * @covers ::updateOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testUpdateOperation_ClampsProgressToRange(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);

        // Test upper bound
        $registry->updateOperation($operationId, LabkiOperationRegistry::STATUS_RUNNING, null, 150);
        $operation = $registry->getOperation($operationId);
        $this->assertSame(100, $operation->progress());

        // Test lower bound
        $registry->updateOperation($operationId, LabkiOperationRegistry::STATUS_RUNNING, null, -10);
        $operation = $registry->getOperation($operationId);
        $this->assertSame(0, $operation->progress());
    }

    /**
     * @covers ::updateOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testUpdateOperation_UpdatesResultData(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_PACK_INSTALL);
        $resultData = json_encode(['installed' => 5, 'failed' => 0]);
        $registry->updateOperation(
            $operationId,
            LabkiOperationRegistry::STATUS_SUCCESS,
            null,
            null,
            $resultData
        );

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame($resultData, $operation->resultData());
    }

    /**
     * @covers ::startOperation
     * @covers ::createOperation
     * @covers ::getOperationStatus
     */
    public function testStartOperation_MarksAsRunning(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->startOperation($operationId, 'Starting process');

        $status = $registry->getOperationStatus($operationId);

        $this->assertSame(LabkiOperationRegistry::STATUS_RUNNING, $status);
    }

    /**
     * @covers ::startOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testStartOperation_WithMessage_StoresMessage(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_SYNC);
        $registry->startOperation($operationId, 'Cloning repository');

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame('Cloning repository', $operation->message());
    }

    /**
     * @covers ::completeOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testCompleteOperation_MarksAsSuccessWithFullProgress(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_PACK_INSTALL);
        $registry->completeOperation($operationId, 'Installation complete');

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame(LabkiOperationRegistry::STATUS_SUCCESS, $operation->status());
        $this->assertSame(100, $operation->progress());
        $this->assertSame('Installation complete', $operation->message());
    }

    /**
     * @covers ::completeOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testCompleteOperation_WithResultData_StoresResultData(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $resultData = json_encode(['pages_created' => 10, 'duration_ms' => 1500]);
        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_PACK_INSTALL);
        $registry->completeOperation($operationId, 'Done', $resultData);

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame($resultData, $operation->resultData());
    }

    /**
     * @covers ::failOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testFailOperation_MarksAsFailed(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->failOperation($operationId, 'Network timeout');

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame(LabkiOperationRegistry::STATUS_FAILED, $operation->status());
        $this->assertSame('Network timeout', $operation->message());
    }

    /**
     * @covers ::failOperation
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testFailOperation_WithResultData_StoresErrorData(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $errorData = json_encode(['error_code' => 'E001', 'stack_trace' => 'Sample trace']);
        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_SYNC);
        $registry->failOperation($operationId, 'Git fetch failed', $errorData);

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame($errorData, $operation->resultData());
    }

    /**
     * @covers ::setProgress
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testSetProgress_UpdatesProgressAndKeepsRunningStatus(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_PACK_UPDATE);
        $registry->startOperation($operationId);
        $registry->setProgress($operationId, 75, 'Almost done');

        $operation = $registry->getOperation($operationId);

        $this->assertNotNull($operation);
        $this->assertSame(LabkiOperationRegistry::STATUS_RUNNING, $operation->status());
        $this->assertSame(75, $operation->progress());
        $this->assertSame('Almost done', $operation->message());
    }

    /**
     * @covers ::operationExists
     * @covers ::createOperation
     */
    public function testOperationExists_WhenExists_ReturnsTrue(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);

        $this->assertTrue($registry->operationExists($operationId));
    }

    /**
     * @covers ::operationExists
     */
    public function testOperationExists_WhenNotExists_ReturnsFalse(): void {
        $registry = $this->newRegistry();

        $this->assertFalse($registry->operationExists(new OperationId('nonexistent_operation')));
    }

    /**
     * @covers ::getOperationStatus
     * @covers ::createOperation
     */
    public function testGetOperationStatus_ReturnsCorrectStatus(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_SYNC);

        $status = $registry->getOperationStatus($operationId);

        $this->assertSame(LabkiOperationRegistry::STATUS_QUEUED, $status);
    }

    /**
     * @covers ::getOperationStatus
     */
    public function testGetOperationStatus_WhenNotExists_ReturnsNull(): void {
        $registry = $this->newRegistry();

        $status = $registry->getOperationStatus(new OperationId('nonexistent_operation'));

        $this->assertNull($status);
    }

    /**
     * @covers ::getOperation
     */
    public function testGetOperation_WhenNotExists_ReturnsNull(): void {
        $registry = $this->newRegistry();

        $operation = $registry->getOperation(new OperationId('nonexistent_operation'));

        $this->assertNull($operation);
    }

    /**
     * @covers ::listOperations
     * @covers ::createOperation
     */
    public function testListOperations_ReturnsAllOperations(): void {
        $registry = $this->newRegistry();
        $opIdStr1 = 'test_op_' . uniqid();
        $opIdStr2 = 'test_op_' . uniqid();
        $opId1 = new OperationId( $opIdStr1 );
        $opId2 = new OperationId( $opIdStr2 );

        $registry->createOperation($opId1, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->createOperation($opId2, LabkiOperationRegistry::TYPE_REPO_SYNC);

        $operations = $registry->listOperations();

        $this->assertGreaterThanOrEqual(2, count($operations));

        $operationIds = array_map(fn($op) => $op->id()->toString(), $operations);
        $this->assertContains($opIdStr1, $operationIds);
        $this->assertContains($opIdStr2, $operationIds);
    }

    /**
     * @covers ::listOperations
     * @covers ::createOperation
     */
    public function testListOperations_WithTypeFilter_ReturnsOnlyMatchingType(): void {
        $registry = $this->newRegistry();
        $opIdStr1 = 'test_op_' . uniqid();
        $opIdStr2 = 'test_op_' . uniqid();
        $opId1 = new OperationId( $opIdStr1 );
        $opId2 = new OperationId( $opIdStr2 );

        $registry->createOperation($opId1, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->createOperation($opId2, LabkiOperationRegistry::TYPE_PACK_INSTALL);

        $operations = $registry->listOperations(LabkiOperationRegistry::TYPE_REPO_ADD);

        $operationIds = array_map(fn($op) => $op->id()->toString(), $operations);
        $this->assertContains($opIdStr1, $operationIds);
        $this->assertNotContains($opIdStr2, $operationIds);
    }

    /**
     * @covers ::listOperations
     * @covers ::createOperation
     */
    public function testListOperations_WithLimit_RespectsLimit(): void {
        $registry = $this->newRegistry();

        // Create more operations than the limit
        for ($i = 0; $i < 10; $i++) {
            $registry->createOperation(new OperationId('test_op_' . uniqid()), LabkiOperationRegistry::TYPE_REPO_ADD);
        }

        $operations = $registry->listOperations(null, 5);

        $this->assertLessThanOrEqual(5, count($operations));
    }

    /**
     * @covers ::getOperationsByStatus
     * @covers ::createOperation
     * @covers ::updateOperation
     */
    public function testGetOperationsByStatus_ReturnsOnlyMatchingStatus(): void {
        $registry = $this->newRegistry();
        $opIdStr1 = 'test_op_' . uniqid();
        $opIdStr2 = 'test_op_' . uniqid();
        $opIdStr3 = 'test_op_' . uniqid();
        $opId1 = new OperationId( $opIdStr1 );
        $opId2 = new OperationId( $opIdStr2 );
        $opId3 = new OperationId( $opIdStr3 );

        $registry->createOperation($opId1, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->createOperation($opId2, LabkiOperationRegistry::TYPE_REPO_SYNC);
        $registry->createOperation($opId3, LabkiOperationRegistry::TYPE_PACK_INSTALL);

        $registry->startOperation($opId1);
        $registry->completeOperation($opId2);
        // opId3 remains queued

        $runningOps = $registry->getOperationsByStatus(LabkiOperationRegistry::STATUS_RUNNING);
        $runningIds = array_map(fn($op) => $op->id()->toString(), $runningOps);

        $this->assertContains($opIdStr1, $runningIds);
        $this->assertNotContains($opIdStr2, $runningIds);
        $this->assertNotContains($opIdStr3, $runningIds);
    }

    /**
     * @covers ::getOperationsByUser
     * @covers ::createOperation
     */
    public function testGetOperationsByUser_ReturnsOnlyUserOperations(): void {
        $registry = $this->newRegistry();
        $opIdStr1 = 'test_op_' . uniqid();
        $opIdStr2 = 'test_op_' . uniqid();
        $opIdStr3 = 'test_op_' . uniqid();
        $opId1 = new OperationId( $opIdStr1 );
        $opId2 = new OperationId( $opIdStr2 );
        $opId3 = new OperationId( $opIdStr3 );

        $registry->createOperation($opId1, LabkiOperationRegistry::TYPE_REPO_ADD, 100);
        $registry->createOperation($opId2, LabkiOperationRegistry::TYPE_REPO_SYNC, 200);
        $registry->createOperation($opId3, LabkiOperationRegistry::TYPE_PACK_INSTALL, 100);

        $user100Ops = $registry->getOperationsByUser(100);
        $user100Ids = array_map(fn($op) => $op->id()->toString(), $user100Ops);

        $this->assertContains($opIdStr1, $user100Ids);
        $this->assertNotContains($opIdStr2, $user100Ids);
        $this->assertContains($opIdStr3, $user100Ids);
    }

    /**
     * @covers ::countOperationsByStatus
     * @covers ::createOperation
     * @covers ::updateOperation
     */
    public function testCountOperationsByStatus_ReturnsCorrectCount(): void {
        $registry = $this->newRegistry();

        // Create some operations with different statuses
        $opId1 = new OperationId('test_op_' . uniqid());
        $opId2 = new OperationId('test_op_' . uniqid());
        $opId3 = new OperationId('test_op_' . uniqid());

        $registry->createOperation($opId1, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->createOperation($opId2, LabkiOperationRegistry::TYPE_REPO_SYNC);
        $registry->createOperation($opId3, LabkiOperationRegistry::TYPE_PACK_INSTALL);

        $registry->startOperation($opId1);
        $registry->startOperation($opId2);
        // opId3 remains queued

        $runningCount = $registry->countOperationsByStatus(LabkiOperationRegistry::STATUS_RUNNING);

        $this->assertGreaterThanOrEqual(2, $runningCount);
    }

    /**
     * @covers ::getOperationStats
     * @covers ::createOperation
     * @covers ::updateOperation
     */
    public function testGetOperationStats_ReturnsCountsByStatus(): void {
        $registry = $this->newRegistry();

        $opId1 = new OperationId('test_op_' . uniqid());
        $opId2 = new OperationId('test_op_' . uniqid());
        $opId3 = new OperationId('test_op_' . uniqid());
        $opId4 = new OperationId('test_op_' . uniqid());

        $registry->createOperation($opId1, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->createOperation($opId2, LabkiOperationRegistry::TYPE_REPO_SYNC);
        $registry->createOperation($opId3, LabkiOperationRegistry::TYPE_PACK_INSTALL);
        $registry->createOperation($opId4, LabkiOperationRegistry::TYPE_REPO_REMOVE);

        $registry->startOperation($opId1);
        $registry->completeOperation($opId2);
        $registry->failOperation($opId3, 'Error');
        // opId4 remains queued

        $stats = $registry->getOperationStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey(LabkiOperationRegistry::STATUS_QUEUED, $stats);
        $this->assertArrayHasKey(LabkiOperationRegistry::STATUS_RUNNING, $stats);
        $this->assertArrayHasKey(LabkiOperationRegistry::STATUS_SUCCESS, $stats);
        $this->assertArrayHasKey(LabkiOperationRegistry::STATUS_FAILED, $stats);

        $this->assertGreaterThanOrEqual(1, $stats[LabkiOperationRegistry::STATUS_QUEUED]);
        $this->assertGreaterThanOrEqual(1, $stats[LabkiOperationRegistry::STATUS_RUNNING]);
        $this->assertGreaterThanOrEqual(1, $stats[LabkiOperationRegistry::STATUS_SUCCESS]);
        $this->assertGreaterThanOrEqual(1, $stats[LabkiOperationRegistry::STATUS_FAILED]);
    }

    /**
     * @covers ::deleteOldOperations
     * @covers ::createOperation
     * @covers ::operationExists
     */
    public function testDeleteOldOperations_DeletesCompletedOperations(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->completeOperation($operationId);

        // Manually update the updated_at to be old enough
        $db = $this->db;
        $oldTimestamp = wfTimestamp(TS_MW, time() - (31 * 86400)); // 31 days ago
        $db->update(
            'labki_operations',
            ['updated_at' => $oldTimestamp],
            ['operation_id' => $operationIdStr],
            __METHOD__
        );

        $deleted = $registry->deleteOldOperations(30, true);

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertFalse($registry->operationExists($operationId));
    }

    /**
     * @covers ::deleteOldOperations
     * @covers ::createOperation
     * @covers ::operationExists
     */
    public function testDeleteOldOperations_PreservesRunningOperations(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->startOperation($operationId);

        // Manually update the updated_at to be old enough
        $db = $this->db;
        $oldTimestamp = wfTimestamp(TS_MW, time() - (31 * 86400)); // 31 days ago
        $db->update(
            'labki_operations',
            ['updated_at' => $oldTimestamp],
            ['operation_id' => $operationIdStr],
            __METHOD__
        );

        $registry->deleteOldOperations(30, true);

        // Running operation should still exist when onlyCompleted = true
        $this->assertTrue($registry->operationExists($operationId));
    }

    /**
     * @covers ::deleteOldOperations
     * @covers ::createOperation
     * @covers ::operationExists
     */
    public function testDeleteOldOperations_WithOnlyCompletedFalse_DeletesAllOldOperations(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);
        $registry->startOperation($operationId);

        // Manually update the updated_at to be old enough
        $db = $this->db;
        $oldTimestamp = wfTimestamp(TS_MW, time() - (31 * 86400)); // 31 days ago
        $db->update(
            'labki_operations',
            ['updated_at' => $oldTimestamp],
            ['operation_id' => $operationIdStr],
            __METHOD__
        );

        $registry->deleteOldOperations(30, false);

        // Running operation should be deleted when onlyCompleted = false
        $this->assertFalse($registry->operationExists($operationId));
    }

    /**
     * @covers ::listOperations
     * @covers ::createOperation
     * @covers ::updateOperation
     */
    public function testListOperations_OrdersByMostRecentFirst(): void {
        $registry = $this->newRegistry();
        $opIdStr1 = 'test_op_1_' . uniqid();
        $opIdStr2 = 'test_op_2_' . uniqid();
        $opId1 = new OperationId( $opIdStr1 );
        $opId2 = new OperationId( $opIdStr2 );

        $registry->createOperation($opId1, LabkiOperationRegistry::TYPE_REPO_ADD);
        sleep(1); // Ensure different timestamps
        $registry->createOperation($opId2, LabkiOperationRegistry::TYPE_REPO_SYNC);

        $operations = $registry->listOperations();

        // First operation should be the most recent (opId2)
        $firstOp = reset($operations);
        $this->assertSame($opIdStr2, $firstOp->id()->toString());
    }

    /**
     * @covers ::createOperation
     * @covers ::getOperation
     */
    public function testOperationLifecycle_CompleteWorkflow(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_lifecycle_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        // Create
        $registry->createOperation(
            $operationId,
            LabkiOperationRegistry::TYPE_REPO_ADD,
            1,
            LabkiOperationRegistry::STATUS_QUEUED,
            'Initializing'
        );

        $op = $registry->getOperation($operationId);
        $this->assertSame(LabkiOperationRegistry::STATUS_QUEUED, $op->status());

        // Start
        $registry->startOperation($operationId, 'Cloning repository');
        $op = $registry->getOperation($operationId);
        $this->assertSame(LabkiOperationRegistry::STATUS_RUNNING, $op->status());

        // Progress
        $registry->setProgress($operationId, 50, 'Halfway done');
        $op = $registry->getOperation($operationId);
        $this->assertSame(50, $op->progress());

        // Complete
        $resultData = json_encode(['files' => 42]);
        $registry->completeOperation($operationId, 'All done', $resultData);
        $op = $registry->getOperation($operationId);
        $this->assertSame(LabkiOperationRegistry::STATUS_SUCCESS, $op->status());
        $this->assertSame(100, $op->progress());
        $this->assertSame($resultData, $op->resultData());
    }

    /**
     * @covers ::createOperation
     * @covers ::updateOperation
     * @covers ::getOperation
     */
    public function testUpdateOperation_OnlyUpdatesSpecifiedFields(): void {
        $registry = $this->newRegistry();
        $operationIdStr = 'test_op_' . uniqid();
        $operationId = new OperationId( $operationIdStr );

        $registry->createOperation($operationId, LabkiOperationRegistry::TYPE_REPO_ADD);
        
        // Update only status
        $registry->updateOperation($operationId, LabkiOperationRegistry::STATUS_RUNNING);
        $op = $registry->getOperation($operationId);
        $this->assertSame(LabkiOperationRegistry::STATUS_RUNNING, $op->status());
        $this->assertSame('', $op->message()); // Should remain empty
        $this->assertSame(0, $op->progress()); // Should remain 0

        // Update only message
        $registry->updateOperation($operationId, LabkiOperationRegistry::STATUS_RUNNING, 'New message');
        $op = $registry->getOperation($operationId);
        $this->assertSame('New message', $op->message());
    }
}
