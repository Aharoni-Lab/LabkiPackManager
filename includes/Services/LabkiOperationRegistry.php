<?php
declare(strict_types=1);

namespace LabkiPackManager\Services;

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

    // Operation status constants
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    // Common operation types
    public const TYPE_REPO_ADD = 'repo_add';
    public const TYPE_REPO_SYNC = 'repo_sync';
    public const TYPE_REPO_REMOVE = 'repo_remove';
    public const TYPE_PACK_INSTALL = 'pack_install';
    public const TYPE_PACK_UPDATE = 'pack_update';

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
     * @param string $operationId Unique identifier for this operation
     * @param string $type Operation type (e.g., 'repo_add', 'pack_install')
     * @param int $userId User ID initiating the operation (0 for system)
     * @param string $status Initial status (default: 'queued')
     * @param string $message Optional human-readable message
     * @return void
     */
    public function createOperation(
        string $operationId,
        string $type,
        int $userId = 0,
        string $status = self::STATUS_QUEUED,
        string $message = ''
    ): void {
        $this->dbw->insert( 'labki_operations', [
            'operation_id' => $operationId,
            'operation_type' => $type,
            'status' => $status,
            'message' => $message,
            'user_id' => $userId,
            'started_at' => wfTimestampNow(),
            'updated_at' => wfTimestampNow(),
        ], __METHOD__ );
    }

    /**
     * Update an existing operation
     *
     * Updates operation status, message, progress, and result data. All parameters
     * except operationId and status are optional; null values are ignored.
     *
     * @param string $operationId The operation to update
     * @param string $status New status value
     * @param string|null $message Optional status message
     * @param int|null $progress Optional progress percentage (0-100)
     * @param string|null $resultData Optional JSON result data
     * @return void
     */
    public function updateOperation(
        string $operationId,
        string $status,
        ?string $message = null,
        ?int $progress = null,
        ?string $resultData = null
    ): void {
        $row = [
            'status' => $status,
            'updated_at' => wfTimestampNow(),
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
            'labki_operations',
            $row,
            [ 'operation_id' => $operationId ],
            __METHOD__
        );
    }

    /**
     * Mark an operation as started/running
     *
     * Convenience method to transition an operation from 'queued' to 'running'.
     *
     * @param string $operationId The operation to start
     * @param string|null $message Optional status message
     * @return void
     */
    public function startOperation( string $operationId, ?string $message = null ): void {
        $this->updateOperation( $operationId, self::STATUS_RUNNING, $message );
    }

    /**
     * Mark an operation as successfully completed
     *
     * Convenience method to mark an operation as completed with 100% progress.
     *
     * @param string $operationId The operation to complete
     * @param string|null $message Optional completion message
     * @param string|null $resultData Optional JSON result data
     * @return void
     */
    public function completeOperation(
        string $operationId,
        ?string $message = null,
        ?string $resultData = null
    ): void {
        $this->updateOperation( $operationId, self::STATUS_SUCCESS, $message, 100, $resultData );
    }

    /**
     * Mark an operation as failed
     *
     * Convenience method to mark an operation as failed with an error message.
     *
     * @param string $operationId The operation to fail
     * @param string $errorMessage Error description
     * @param string|null $resultData Optional JSON error data
     * @return void
     */
    public function failOperation(
        string $operationId,
        string $errorMessage,
        ?string $resultData = null
    ): void {
        $this->updateOperation( $operationId, self::STATUS_FAILED, $errorMessage, null, $resultData );
    }

    /**
     * Update operation progress
     *
     * Convenience method to update the progress percentage of a running operation.
     *
     * @param string $operationId The operation to update
     * @param int $progress Progress percentage (0-100)
     * @param string|null $message Optional progress message
     * @return void
     */
    public function setProgress( string $operationId, int $progress, ?string $message = null ): void {
        $this->updateOperation( $operationId, self::STATUS_RUNNING, $message, $progress );
    }

    /**
     * Check if an operation exists
     *
     * @param string $operationId The operation ID to check
     * @return bool True if the operation exists in the database
     */
    public function operationExists( string $operationId ): bool {
        return $this->dbr->selectField(
            'labki_operations',
            '1',
            [ 'operation_id' => $operationId ],
            __METHOD__
        ) !== false;
    }

    /**
     * Get the current status of an operation
     *
     * @param string $operationId The operation ID
     * @return string|null The status string, or null if operation doesn't exist
     */
    public function getOperationStatus( string $operationId ): ?string {
        $status = $this->dbr->selectField(
            'labki_operations',
            'status',
            [ 'operation_id' => $operationId ],
            __METHOD__
        );
        return $status !== false ? (string)$status : null;
    }

    /**
     * Fetch a single operation by ID
     *
     * Returns all fields for the requested operation, or null if not found.
     *
     * @param string $operationId The operation ID to fetch
     * @return array|null Associative array of operation data, or null if not found
     */
    public function getOperation( string $operationId ): ?array {
        $row = $this->dbr->selectRow(
            'labki_operations',
            '*',
            [ 'operation_id' => $operationId ],
            __METHOD__
        );
        return $row ? (array)$row : null;
    }

    /**
     * Fetch recent operations with optional filters
     *
     * Returns operations ordered by most recently updated first.
     *
     * @param string|null $type Filter by operation type (null for all types)
     * @param int|null $limit Maximum number of operations to return
     * @return array Array of operation records (as associative arrays)
     */
    public function listOperations( ?string $type = null, ?int $limit = 50 ): array {
        $conds = [];
        if ( $type !== null ) {
            $conds['operation_type'] = $type;
        }
        $res = $this->dbr->select(
            'labki_operations',
            '*',
            $conds,
            __METHOD__,
            [ 'ORDER BY' => 'updated_at DESC', 'LIMIT' => $limit ]
        );
        return iterator_to_array( $res );
    }

    /**
     * Get operations by status
     *
     * Returns all operations matching the specified status, useful for finding
     * all queued, running, or failed operations.
     *
     * @param string $status Status to filter by (e.g., 'running', 'failed')
     * @param int|null $limit Maximum number of operations to return
     * @return array Array of operation records
     */
    public function getOperationsByStatus( string $status, ?int $limit = 50 ): array {
        $res = $this->dbr->select(
            'labki_operations',
            '*',
            [ 'status' => $status ],
            __METHOD__,
            [ 'ORDER BY' => 'updated_at DESC', 'LIMIT' => $limit ]
        );
        return iterator_to_array( $res );
    }

    /**
     * Get operations by user ID
     *
     * Returns all operations initiated by a specific user.
     *
     * @param int $userId User ID to filter by
     * @param int|null $limit Maximum number of operations to return
     * @return array Array of operation records
     */
    public function getOperationsByUser( int $userId, ?int $limit = 50 ): array {
        $res = $this->dbr->select(
            'labki_operations',
            '*',
            [ 'user_id' => $userId ],
            __METHOD__,
            [ 'ORDER BY' => 'updated_at DESC', 'LIMIT' => $limit ]
        );
        return iterator_to_array( $res );
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
            'labki_operations',
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
            $conds['status'] = [ self::STATUS_SUCCESS, self::STATUS_FAILED ];
        }

        $this->dbw->delete(
            'labki_operations',
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
            'labki_operations',
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
