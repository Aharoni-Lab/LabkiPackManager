<?php
declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\Operation;
use LabkiPackManager\Domain\OperationId;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * LabkiOperationRegistry
 *
 * Service for tracking and managing background job and API operations in the labki_operations table.
 * This registry provides a comprehensive interface for creating, updating, querying, and managing
 * long-running operations such as repository initialization, synchronization, and pack installation.
 *
 * Operations can have the following statuses:
 * - 'queued': Operation has been created but not yet started
 * - 'running': Operation is currently in progress
 * - 'success': Operation completed successfully
 * - 'failed': Operation encountered an error and failed
 *
 * @see LabkiRepoAddJob Example usage in background jobs
 */
final class LabkiOperationRegistry {

    // Re-export Operation constants for backward compatibility
    public const STATUS_QUEUED = Operation::STATUS_QUEUED;
    public const STATUS_RUNNING = Operation::STATUS_RUNNING;
    public const STATUS_SUCCESS = Operation::STATUS_SUCCESS;
    public const STATUS_FAILED = Operation::STATUS_FAILED;

	public const TYPE_REPO_ADD = Operation::TYPE_REPO_ADD;
	public const TYPE_REPO_SYNC = Operation::TYPE_REPO_SYNC;
	public const TYPE_REPO_REMOVE = Operation::TYPE_REPO_REMOVE;
	public const TYPE_PACK_INSTALL = Operation::TYPE_PACK_INSTALL;
	public const TYPE_PACK_UPDATE = Operation::TYPE_PACK_UPDATE;
	public const TYPE_PACK_REMOVE = Operation::TYPE_PACK_REMOVE;
	public const TYPE_PACK_APPLY = Operation::TYPE_PACK_APPLY;

    private IDatabase $dbw;
    private IDatabase $dbr;

    /**
     * Constructor
     *
     * Initializes database connections for read and write operations.
     */
    public function __construct() {
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $this->dbw = $lb->getConnection( DB_PRIMARY );
        $this->dbr = $lb->getConnection( DB_REPLICA );
    }

    /**
     * Create a new operation record
     *
     * Inserts a new operation into the tracking table. The operation is created with
     * 'queued' status by default and can be updated as it progresses.
     *
     * @param OperationId $operationId Unique identifier for this operation
     * @param string $type Operation type (e.g., 'repo_add', 'pack_install')
     * @param int $userId User ID initiating the operation (0 for system)
     * @param string $status Initial status (default: 'queued')
     * @param string $message Optional human-readable message
     * @return void
     */
    public function createOperation(
        OperationId $operationId,
        string $type,
        int $userId = 0,
        string $status = Operation::STATUS_QUEUED,
        string $message = ''
    ): void {
        $now = $this->dbw->timestamp( wfTimestampNow() );
        $this->dbw->insert( Operation::TABLE, [
            'operation_id' => $operationId->toString(),
            'operation_type' => $type,
            'status' => $status,
            'message' => $message,
            'user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ], __METHOD__ );
    }

    /**
     * Update an existing operation
     *
     * Updates operation status, message, progress, and result data. All parameters
     * except operationId and status are optional; null values are ignored.
     *
     * @param OperationId $operationId The operation to update
     * @param string $status New status value
     * @param string|null $message Optional status message
     * @param int|null $progress Optional progress percentage (0-100)
     * @param string|null $resultData Optional JSON result data
     * @return void
     */
    public function updateOperation(
        OperationId $operationId,
        string $status,
        ?string $message = null,
        ?int $progress = null,
        ?string $resultData = null
    ): void {
        $row = [
            'status' => $status,
            'updated_at' => $this->dbw->timestamp( wfTimestampNow() ),
        ];
        if ( $message !== null ) {
            $row['message'] = $message;
        }
        if ( $progress !== null ) {
            $row['progress'] = max( 0, min( 100, $progress ) );
        }
        if ( $resultData !== null ) {
            $row['result_data'] = $resultData;
        }
        $this->dbw->update(
            Operation::TABLE,
            $row,
            [ 'operation_id' => $operationId->toString() ],
            __METHOD__
        );
    }

    /**
     * Mark an operation as started/running
     *
     * Convenience method to transition an operation from 'queued' to 'running'.
     * Sets started_at timestamp when operation begins execution.
     *
     * @param OperationId $operationId The operation to start
     * @param string|null $message Optional status message
     * @return void
     */
    public function startOperation( OperationId $operationId, ?string $message = null ): void {
        $now = $this->dbw->timestamp( wfTimestampNow() );
        $row = [
            'status' => Operation::STATUS_RUNNING,
            'started_at' => $now,
            'updated_at' => $now,
        ];
        if ( $message !== null ) {
            $row['message'] = $message;
        }
        $this->dbw->update(
            Operation::TABLE,
            $row,
            [ 'operation_id' => $operationId->toString() ],
            __METHOD__
        );
    }

    /**
     * Helper method that was here before - keeping for backwards compatibility
     * @deprecated Use startOperation with proper started_at tracking
     */
    private function startOperationOld( OperationId $operationId, ?string $message = null ): void {
        $this->updateOperation( $operationId, Operation::STATUS_RUNNING, $message );
    }

    /**
     * Mark an operation as successfully completed
     *
     * Convenience method to mark an operation as completed with 100% progress.
     *
     * @param OperationId $operationId The operation to complete
     * @param string|null $message Optional completion message
     * @param string|null $resultData Optional JSON result data
     * @return void
     */
    public function completeOperation(
        OperationId $operationId,
        ?string $message = null,
        ?string $resultData = null
    ): void {
        $this->updateOperation( $operationId, Operation::STATUS_SUCCESS, $message, 100, $resultData );
    }

    /**
     * Mark an operation as failed
     *
     * Convenience method to mark an operation as failed with an error message.
     *
     * @param OperationId $operationId The operation to fail
     * @param string $errorMessage Error description
     * @param string|null $resultData Optional JSON error data
     * @return void
     */
    public function failOperation(
        OperationId $operationId,
        string $errorMessage,
        ?string $resultData = null
    ): void {
        $this->updateOperation( $operationId, Operation::STATUS_FAILED, $errorMessage, null, $resultData );
    }

    /**
     * Update operation progress
     *
     * Convenience method to update the progress percentage of a running operation.
     *
     * @param OperationId $operationId The operation to update
     * @param int $progress Progress percentage (0-100)
     * @param string|null $message Optional progress message
     * @return void
     */
    public function setProgress( OperationId $operationId, int $progress, ?string $message = null ): void {
        $this->updateOperation( $operationId, Operation::STATUS_RUNNING, $message, $progress );
    }

    /**
     * Check if an operation exists
     *
     * @param OperationId $operationId The operation ID to check
     * @return bool True if the operation exists in the database
     */
    public function operationExists( OperationId $operationId ): bool {
        return $this->dbr->selectField(
            Operation::TABLE,
            '1',
            [ 'operation_id' => $operationId->toString() ],
            __METHOD__
        ) !== false;
    }

    /**
     * Get the current status of an operation
     *
     * @param OperationId $operationId The operation ID
     * @return string|null The status string, or null if operation doesn't exist
     */
    public function getOperationStatus( OperationId $operationId ): ?string {
        $status = $this->dbr->selectField(
            Operation::TABLE,
            'status',
            [ 'operation_id' => $operationId->toString() ],
            __METHOD__
        );
        return $status !== false ? (string)$status : null;
    }

    /**
     * Fetch a single operation by ID
     *
     * Returns all fields for the requested operation, or null if not found.
     *
     * @param OperationId|string $operationId The operation ID to fetch (object or string)
     * @return Operation|null Operation domain object, or null if not found
     */
    public function getOperation( OperationId|string $operationId ): ?Operation {
        $operationIdStr = $operationId instanceof OperationId ? $operationId->toString() : $operationId;
        $row = $this->dbr->selectRow(
            Operation::TABLE,
            Operation::FIELDS,
            [ 'operation_id' => $operationIdStr ],
            __METHOD__
        );
        return $row ? Operation::fromRow( $row ) : null;
    }

    /**
     * Fetch operations with optional filters
     *
     * Returns operations ordered by most recently updated first.
     * Can filter by type, status, and/or user ID.
     *
     * @param string|null $type Filter by operation type (null for all types)
     * @param string|null $status Filter by status (null for all statuses)
     * @param int|null $userId Filter by user ID (null for all users)
     * @param int $limit Maximum number of operations to return
     * @return Operation[] Array of Operation domain objects
     */
    public function getOperations(
        ?string $type = null,
        ?string $status = null,
        ?int $userId = null,
        int $limit = 50
    ): array {
        $conds = [];
        if ( $type !== null ) {
            $conds['operation_type'] = $type;
        }
        if ( $status !== null ) {
            $conds['status'] = $status;
        }
        if ( $userId !== null ) {
            $conds['user_id'] = $userId;
        }
        
        $res = $this->dbr->select(
            Operation::TABLE,
            Operation::FIELDS,
            $conds,
            __METHOD__,
            [ 'ORDER BY' => 'updated_at DESC', 'LIMIT' => $limit ]
        );
        return array_map( static fn( $row ) => Operation::fromRow( $row ), iterator_to_array( $res ) );
    }

    /**
     * Count operations by status
     *
     * Returns the count of operations in a given status, useful for
     * monitoring system health and operation queue size.
     *
     * @param string $status Status to count
     * @return int Number of operations with the given status
     */
    public function countOperationsByStatus( string $status ): int {
        return (int)$this->dbr->selectField(
            Operation::TABLE,
            'COUNT(*)',
            [ 'status' => $status ],
            __METHOD__
        );
    }

    /**
     * Delete old completed operations
     *
     * Removes operation records older than the specified number of days.
     * Useful for periodic cleanup to prevent the operations table from growing indefinitely.
     *
     * @param int $daysOld Delete operations updated more than this many days ago
     * @param bool $onlyCompleted If true, only delete success/failed operations (preserve running/queued)
     * @return int Number of operations deleted
     */
    public function deleteOldOperations( int $daysOld = 30, bool $onlyCompleted = true ): int {
        $cutoffTimestamp = wfTimestamp( TS_MW, time() - ( $daysOld * 86400 ) );
        $conds = [ 'updated_at < ' . $this->dbr->addQuotes( $cutoffTimestamp ) ];
        
        if ( $onlyCompleted ) {
            $conds['status'] = [ Operation::STATUS_SUCCESS, Operation::STATUS_FAILED ];
        }

        $this->dbw->delete(
            Operation::TABLE,
            $conds,
            __METHOD__
        );
        
        return $this->dbw->affectedRows();
    }

    /**
     * Get operation statistics
     *
     * Returns counts of operations by status for monitoring and reporting.
     *
     * @return array Associative array with status as key and count as value
     */
    public function getOperationStats(): array {
        $res = $this->dbr->select(
            Operation::TABLE,
            [ 'status', 'count' => 'COUNT(*)' ],
            [],
            __METHOD__,
            [ 'GROUP BY' => 'status' ]
        );
        
        $stats = [];
        foreach ( $res as $row ) {
            $stats[$row->status] = (int)$row->count;
        }
        
        return $stats;
    }
}
