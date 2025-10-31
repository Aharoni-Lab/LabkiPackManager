<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Operations;

use Wikimedia\ParamValidator\ParamValidator;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Services\LabkiOperationRegistry;
use ApiBase;
use ApiMain;
use MediaWiki\Json\FormatJson;

/**
 * ApiLabkiOperationsStatus
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
 *   "created_at": "20251024115900",
 *   "started_at": "20251024120000",
 *   "updated_at": "20251024122300",
 *   "meta": {
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
 *   "meta": {
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
 * - Users with 'labkipackmanager-manage' permission can view all operations
 *
 * @ingroup API
 */
final class ApiLabkiOperationsStatus extends ApiBase {

	public function __construct( ApiMain $main, string $name ) {
		parent::__construct( $main, $name );
	}

	/** Execute API request. */
	public function execute(): void {
		// Extract parameters
		$params = $this->extractRequestParams();
		$operationId = $params['operation_id'];
		$limit = $params['limit'];
		
		// Validate limit range
		// Should be done by ParamValidator, but we're not using it yet
		// TODO: Figure out how to improve this by properly using ParamValidator
		if ( $limit < 1 || $limit > 500 ) {
			$this->dieWithError( [ 'apierror-integer-outofrange', 'limit', 1, 500 ], 'badvalue' );
		}

		$currentUser = $this->getUser();
		$currentUserId = $currentUser->getId();
		$canManage = $currentUser->isAllowed( 'labkipackmanager-manage' );

		// Single operation mode
		if ( $operationId !== null && $operationId !== '' ) {
			$this->executeSingleOperation( $operationId, $currentUserId, $canManage );
			return;
		}

		// List operations mode
		$this->executeListOperations( $currentUserId, $canManage, $limit );
	}

	/**
	 * Handle single operation status query
	 *
	 * @param string $operationId Operation ID to query
	 * @param int $currentUserId Current user's ID
	 * @param bool $canManage Whether user has manage permission
	 */
	private function executeSingleOperation(
		string $operationId,
		int $currentUserId,
		bool $canManage
	): void {
		$registry = new LabkiOperationRegistry();
		$operation = $registry->getOperation( $operationId );

		// Consider moving this to the LabkiOperationRegistry
		if ( $operation === null ) {
			$this->dieWithError( 'labkipackmanager-error-operation-not-found', 'operation_not_found' );
		}

		// Permission check: users can only see their own operations unless they have manage permission
		$operationUserId = $operation->userId();
		// Deny access if: user is NOT a manager AND operation doesn't belong to current user AND operation is not system-owned
		if ( !$canManage && $operationUserId !== $currentUserId ) {
			$this->dieWithError( 'apierror-permissiondenied-generic', 'permission_denied' );
		}

		$parsed = FormatJson::parse( $operation->resultData(), FormatJson::FORCE_ASSOC );
		$resultData = $parsed->isOK() ? $parsed->getValue() : [];

		// Build response
		$result = $this->getResult();
		$result->addValue( null, 'operation_id', $operation->id()->toString() );
		$result->addValue( null, 'operation_type', $operation->type() );
		$result->addValue( null, 'status', $operation->status() );
		$result->addValue( null, 'progress', $operation->progress() ?? 0 );
		$result->addValue( null, 'message', $operation->message() ?? '' );
		$result->addValue( null, 'result_data', $resultData );
		$result->addValue( null, 'user_id', $operationUserId );
		$result->addValue( null, 'created_at', $operation->createdAt() );
		$result->addValue( null, 'started_at', $operation->startedAt() );
		$result->addValue( null, 'updated_at', $operation->updatedAt() );
		$result->addValue( null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		] );
	}

	/**
	 * Handle list operations query
	 * @param int $currentUserId Current user's ID
	 * @param bool $canManage Whether user has manage permission
	 * @param int $limit Maximum number of operations to return
	 */
	private function executeListOperations(
		int $currentUserId,
		bool $canManage,
		int $limit
	): void {
		$registry = new LabkiOperationRegistry();
		// Managers can see all operations, regular users see only their own
		if ( $canManage ) {
			$operations = $registry->getOperations( limit: $limit );
		} else {
			$operations = $registry->getOperations( userId: $currentUserId, limit: $limit );
		}

		// Format operations for response
		$formattedOps = [];
		foreach ( $operations as $op ) {
			$parsed = FormatJson::parse( $op->resultData(), FormatJson::FORCE_ASSOC );
			$resultData = $parsed->isOK() ? $parsed->getValue() : [];

			$formattedOps[] = [
				'operation_id' => $op->id()->toString(),
				'operation_type' => $op->type(),
				'status' => $op->status(),
				'progress' => $op->progress(),
				'message' => $op->message(),
				'result_data' => $resultData,
				'user_id' => $op->userId(),
				'created_at' => $op->createdAt(),
				'started_at' => $op->startedAt(),
				'updated_at' => $op->updatedAt(),
			];
		}

		$result = $this->getResult();
		$result->addValue( null, 'operations', $formattedOps );
		$result->addValue( null, 'count', count( $formattedOps ) );
		$result->addValue( null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		] );
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
				ParamValidator::PARAM_DEFAULT => 50,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-operations-status-param-limit',
			],
		];
	}

	/** Example messages for auto-generated API docs. */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiOperationsStatus&operation_id=repo_add_abc123'
				=> 'apihelp-labkioperationsstatus-example-single',
			'action=labkiOperationsStatus&limit=10'
				=> 'apihelp-labkioperationsstatus-example-list',
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
