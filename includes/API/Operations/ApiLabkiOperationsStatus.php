<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Operations;

use Wikimedia\ParamValidator\ParamValidator;
use LabkiPackManager\Services\LabkiOperationRegistry;
use ApiBase;
use ApiMain;

/**
 * ApiLabkiOperationStatus
 *
 * API endpoint to query the status and progress of Labki background operations.
 * Supports both single operation lookup and listing multiple operations.
 *
 * ## Purpose
 * Provides real-time status information for long-running background operations such as
 * repository initialization, synchronization, and pack installation. Enables frontend
 * polling for progress updates and completion detection.
 *
 * ## Action
 * `labkiOperationStatus`
 *
 * ## Query Modes
 *
 * ### Single Operation Mode (with operation_id)
 * Returns detailed status for a specific operation.
 *
 * **Example Request:**
 * ```
 * GET api.php?action=labkiOperationStatus&operation_id=repo_add_abc123
 * ```
 *
 * **Response:**
 * ```json
 * {
 *   "operation_id": "repo_add_abc123",
 *   "operation_type": "repo_add",
 *   "status": "running",
 *   "progress": 60,
 *   "message": "Initializing ref main (1/2)",
 *   "result_data": null,
 *   "user_id": 1,
 *   "started_at": "20251024120000",
 *   "updated_at": "20251024122300",
 *   "_meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024122305"
 *   }
 * }
 * ```
 *
 * ### List Operations Mode (without operation_id)
 * Returns recent operations for the current user.
 *
 * **Example Request:**
 * ```
 * GET api.php?action=labkiOperationStatus&limit=10
 * ```
 *
 * **Response:**
 * ```json
 * {
 *   "operations": [
 *     { "operation_id": "...", "status": "success", ... },
 *     { "operation_id": "...", "status": "running", ... }
 *   ],
 *   "count": 2,
 *   "_meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024122305"
 *   }
 * }
 * ```
 *
 * ## Status Values
 * - `queued`: Operation created but not yet started
 * - `running`: Operation is in progress
 * - `success`: Operation completed successfully
 * - `failed`: Operation encountered an error
 *
 * ## Permissions
 * - Users can view their own operations
 * - Users with 'labkipack-manage' permission can view all operations
 *
 * @ingroup API
 */
final class ApiLabkiOperationStatus extends ApiBase {

	public function __construct( ApiMain $main, string $name ) {
		parent::__construct( $main, $name );
	}

	/** Execute API request. */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$operationId = isset( $params['operation_id'] ) ? trim( (string)$params['operation_id'] ) : null;
		$limit = $params['limit'] ?? 50;

		$registry = new LabkiOperationRegistry();
		$currentUser = $this->getUser();
		$currentUserId = $currentUser->getId();
		$canManage = $currentUser->isAllowed( 'labkipack-manage' );

		// Single operation mode
		if ( $operationId !== null && $operationId !== '' ) {
			$this->executeSingleOperation( $registry, $operationId, $currentUserId, $canManage );
			return;
		}

		// List operations mode
		$this->executeListOperations( $registry, $currentUserId, $canManage, $limit );
	}

	/**
	 * Handle single operation status query
	 *
	 * @param LabkiOperationRegistry $registry Operation registry instance
	 * @param string $operationId Operation ID to query
	 * @param int $currentUserId Current user's ID
	 * @param bool $canManage Whether user has manage permission
	 */
	private function executeSingleOperation(
		LabkiOperationRegistry $registry,
		string $operationId,
		int $currentUserId,
		bool $canManage
	): void {
		$operation = $registry->getOperation( $operationId );

		if ( $operation === null ) {
			$this->dieWithError( 'labkipackmanager-error-operation-not-found', 'operation_not_found' );
		}

		// Permission check: users can only see their own operations unless they have manage permission
		$operationUserId = (int)$operation['user_id'];
		if ( !$canManage && $operationUserId !== $currentUserId && $operationUserId !== 0 ) {
			$this->dieWithError( 'apierror-permissiondenied-generic', 'permission_denied' );
		}

		// Parse result_data if it's valid JSON
		$resultData = $this->parseResultData( $operation['result_data'] );

		// Normalize and build response
		$response = [
			'operation_id' => $operation['operation_id'],
			'operation_type' => $operation['operation_type'],
			'status' => $operation['status'],
			'progress' => (int)( $operation['progress'] ?? 0 ),
			'message' => $operation['message'] ?? '',
			'result_data' => $resultData,
			'user_id' => $operationUserId,
			'started_at' => $operation['started_at'],
			'updated_at' => $operation['updated_at'],
			'_meta' => [
				'schemaVersion' => 1,
				'timestamp' => wfTimestampNow(),
			],
		];

		$result = $this->getResult();
		$result->addValue( null, null, $response );
	}

	/**
	 * Handle list operations query
	 *
	 * @param LabkiOperationRegistry $registry Operation registry instance
	 * @param int $currentUserId Current user's ID
	 * @param bool $canManage Whether user has manage permission
	 * @param int $limit Maximum number of operations to return
	 */
	private function executeListOperations(
		LabkiOperationRegistry $registry,
		int $currentUserId,
		bool $canManage,
		int $limit
	): void {
		// Managers can see all operations, regular users see only their own
		if ( $canManage ) {
			$operations = $registry->listOperations( null, $limit );
		} else {
			$operations = $registry->getOperationsByUser( $currentUserId, $limit );
		}

		// Format operations for response
		$formattedOps = [];
		foreach ( $operations as $op ) {
			$formattedOps[] = [
				'operation_id' => $op->operation_id,
				'operation_type' => $op->operation_type,
				'status' => $op->status,
				'progress' => (int)( $op->progress ?? 0 ),
				'message' => $op->message ?? '',
				'result_data' => $this->parseResultData( $op->result_data ?? null ),
				'user_id' => (int)$op->user_id,
				'started_at' => $op->started_at,
				'updated_at' => $op->updated_at,
			];
		}

		$response = [
			'operations' => $formattedOps,
			'count' => count( $formattedOps ),
			'_meta' => [
				'schemaVersion' => 1,
				'timestamp' => wfTimestampNow(),
			],
		];

		$result = $this->getResult();
		$result->addValue( null, null, $response );
	}

	/**
	 * Parse result_data from JSON string to array if valid
	 *
	 * @param string|null $resultData JSON string or null
	 * @return array|string|null Parsed array, original string, or null
	 */
	private function parseResultData( ?string $resultData ) {
		if ( $resultData === null || $resultData === '' ) {
			return null;
		}

		$decoded = json_decode( $resultData, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return $decoded;
		}

		// Return as-is if not valid JSON
		return $resultData;
	}

	/** Define allowed parameters. */
	public function getAllowedParams(): array {
		return [
			'operation_id' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-operations-status-param-operationid',
			],
			'limit' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 50,
				ParamValidator::PARAM_MIN => 1,
				ParamValidator::PARAM_MAX => 500,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-operations-status-param-limit',
			],
		];
	}

	/** Example messages for auto-generated API docs. */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiOperationStatus&operation_id=repo_add_abc123'
				=> 'apihelp-labkioperationstatus-example-single',
			'action=labkiOperationStatus&limit=10'
				=> 'apihelp-labkioperationstatus-example-list',
		];
	}

	/** Read-only, GET is fine. */
	public function mustBePosted(): bool {
		return false;
	}

	public function isWriteMode(): bool {
		return false;
	}

	/** Internal endpoint, not public in auto docs. */
	public function isInternal(): bool {
		return true;
	}
}
