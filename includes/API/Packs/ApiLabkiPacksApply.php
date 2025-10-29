<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Jobs\LabkiPackApplyJob;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWiki\Title\Title;

/**
 * API endpoint to apply pack operations (install, update, remove).
 *
 * ## Purpose
 * Unified endpoint for applying batch pack operations after previewing with
 * `labkiPacksPreview`. Handles install, update, and remove operations in a
 * single atomic transaction with proper dependency ordering.
 *
 * ## Action
 * `labkiPacksApply`
 *
 * ## Example Request
 * ```
 * POST api.php?action=labkiPacksApply
 * {
 *   "repo_id": 1,
 *   "ref": "main",
 *   "operations": [
 *     {
 *       "action": "install",
 *       "pack_name": "Advanced Imaging",
 *       "pages": [
 *         {
 *           "name": "Processing",
 *           "final_title": "Imaging/Advanced_Processing"
 *         }
 *       ]
 *     },
 *     {
 *       "action": "update",
 *       "pack_name": "Analysis Basics",
 *       "target_version": "1.5.0",
 *       "pages": [
 *         {
 *           "name": "FAQ",
 *           "final_title": "Analysis/FAQ",
 *           "overwrite": true
 *         }
 *       ]
 *     },
 *     {
 *       "action": "remove",
 *       "pack_id": 5,
 *       "delete_pages": true
 *     }
 *   ]
 * }
 * ```
 *
 * ## Process Flow
 * 1. Validate parameters (repo, ref, operations structure)
 * 2. Resolve repo and ref identifiers
 * 3. Verify ref has worktree (for install/update operations)
 * 4. Basic validation of operations array structure
 * 5. Generate unique operation ID
 * 6. Queue `LabkiPackApplyJob` for background processing
 * 7. Return immediate queued response
 *
 * ## Response Structure
 * ```json
 * {
 *   "success": true,
 *   "operation_id": "pack_apply_abc123",
 *   "status": "queued",
 *   "message": "Pack operations queued",
 *   "summary": {
 *     "total_operations": 3,
 *     "installs": 1,
 *     "updates": 1,
 *     "removes": 1
 *   },
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000"
 *   }
 * }
 * ```
 *
 * ## Workflow Integration
 * This API is designed to work in tandem with `labkiPacksPreview`:
 * 1. Frontend calls `labkiPacksPreview` to preview operations
 * 2. User resolves any conflicts (page naming, etc.)
 * 3. Frontend calls `labkiPacksApply` with resolved operations
 * 4. Job processes all operations atomically
 * 5. Frontend tracks progress via `labkiOperationsStatus`
 *
 * ## Implementation Notes
 * - Extends PackApiBase for repo/ref resolution
 * - Uses MediaWiki job queue for async operations
 * - Background job handles detailed parsing, validation, and execution
 * - Operations are processed in dependency order (installs → updates → removes)
 * - All operations share a single operation ID for atomic tracking
 * - Requires `labkipackmanager-manage` permission
 *
 * @ingroup API
 */
final class ApiLabkiPacksApply extends PackApiBase {

	/** @inheritDoc */
	public function __construct( \ApiMain $main, string $name ) {
		wfDebugLog( 'labkipack', "ApiLabkiPacksApply::__construct() called with name={$name}" );
		parent::__construct( $main, $name );
	}

	/** Execute the API request. */
	public function execute(): void {
		$this->requireManagePermission();
		$params = $this->extractRequestParams();

		// Get parameters
		$repoId = $params['repo_id'] ?? null;
		$repoUrl = $params['repo_url'] ?? null;
		$refId = $params['ref_id'] ?? null;
		$ref = $params['ref'] ?? null;
		$operationsJson = $params['operations'] ?? '[]';

		wfDebugLog( 'labkipack', "ApiLabkiPacksApply::execute() repo_id={$repoId}, repo_url={$repoUrl}, ref_id={$refId}, ref={$ref}" );

		// Validate: only one repo identifier
		if ( $repoId !== null && $repoUrl !== null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-multiple-identifiers',
				'multiple_identifiers'
			);
		}

		// Validate: only one ref identifier
		if ( $refId !== null && $ref !== null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-multiple-identifiers',
				'multiple_identifiers'
			);
		}

		// Validate: repo is required
		if ( $repoId === null && $repoUrl === null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-required',
				'missing_repo'
			);
		}

		// Validate: ref is required
		if ( $refId === null && $ref === null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-required',
				'missing_ref'
			);
		}

		// Parse operations JSON
		$operations = json_decode( $operationsJson, true );
		if ( !is_array( $operations ) || empty( $operations ) ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-operations',
				'invalid_operations'
			);
		}

		// Basic validation: each operation must have 'action' field
		$summary = [
			'installs' => 0,
			'updates' => 0,
			'removes' => 0,
		];

		foreach ( $operations as $op ) {
			if ( !isset( $op['action'] ) || !is_string( $op['action'] ) ) {
				$this->dieWithError(
					'labkipackmanager-error-invalid-operation-format',
					'invalid_operation_format'
				);
			}

			$action = $op['action'];
			if ( !in_array( $action, [ 'install', 'update', 'remove' ], true ) ) {
				$this->dieWithError(
					'labkipackmanager-error-invalid-operation-action',
					'invalid_operation_action'
				);
			}

			// Count operations by type
			if ( $action === 'install' ) {
				$summary['installs']++;
			} elseif ( $action === 'update' ) {
				$summary['updates']++;
			} elseif ( $action === 'remove' ) {
				$summary['removes']++;
			}

			// Basic field validation per action type
			if ( $action === 'install' || $action === 'update' ) {
				if ( !isset( $op['pack_name'] ) || !is_string( $op['pack_name'] ) ) {
					$this->dieWithError(
						'labkipackmanager-error-operation-missing-pack-name',
						'missing_pack_name'
					);
				}
			}

			if ( $action === 'remove' ) {
				if ( !isset( $op['pack_id'] ) || !is_int( $op['pack_id'] ) ) {
					$this->dieWithError(
						'labkipackmanager-error-operation-missing-pack-id',
						'missing_pack_id'
					);
				}
			}
		}

		wfDebugLog( 'labkipack', "ApiLabkiPacksApply: received " . count( $operations ) . " operation(s): " .
			"{$summary['installs']} installs, {$summary['updates']} updates, {$summary['removes']} removes" );

		// Resolve repo identifier
		$identifier = $repoId ?? $repoUrl;
		$resolvedRepoId = $this->resolveRepoId( $identifier );

		if ( $resolvedRepoId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-not-found',
				'repo_not_found'
			);
		}

		// Resolve ref identifier
		$refIdentifier = $refId ?? $ref;
		$resolvedRefId = $this->resolveRefId( $resolvedRepoId, $refIdentifier );

		if ( $resolvedRefId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-not-found',
				'ref_not_found'
			);
		}

		wfDebugLog( 'labkipack', "ApiLabkiPacksApply: resolved refId={$resolvedRefId->toInt()}" );

		// Verify ref has worktree (needed for install/update operations)
		$hasInstallOrUpdate = ( $summary['installs'] > 0 || $summary['updates'] > 0 );
		if ( $hasInstallOrUpdate ) {
			$refRegistry = $this->getRefRegistry();
			$refObj = $refRegistry->getRefById( $resolvedRefId );

			if ( !$refObj ) {
				$this->dieWithError(
					'labkipackmanager-error-ref-not-found',
					'ref_not_found'
				);
			}

			$worktreePath = $refObj->worktreePath();
			if ( !$worktreePath || !is_dir( $worktreePath ) ) {
				$this->dieWithError(
					'labkipackmanager-error-worktree-not-found',
					'worktree_not_found'
				);
			}
		}

		// Generate operation ID
		$operationIdStr = 'pack_apply_' . substr( md5( $resolvedRefId->toInt() . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );
		$userId = $this->getUser()->getId();

		// Create operation record in database
		$operationRegistry = new LabkiOperationRegistry();
		$operationMessage = sprintf(
			'Pack operations queued: %d installs, %d updates, %d removes',
			$summary['installs'],
			$summary['updates'],
			$summary['removes']
		);
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			$operationMessage
		);

		// Queue background job
		$jobParams = [
			'ref_id' => $resolvedRefId->toInt(),
			'operations' => $operations,
			'operation_id' => $operationIdStr,
			'user_id' => $userId,
		];

		$title = $this->getTitle() ?: Title::newFromText( 'LabkiPackApplyJob' );
		$job = new LabkiPackApplyJob( $title, $jobParams );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApiLabkiPacksApply: queued job with operation_id={$operationIdStr}" );

		// Build response
		$result = $this->getResult();
		$result->addValue( null, 'success', true );
		$result->addValue( null, 'operation_id', $operationIdStr );
		$result->addValue( null, 'status', LabkiOperationRegistry::STATUS_QUEUED );
		$result->addValue( null, 'message', 'Pack operations queued' );
		$result->addValue( null, 'summary', [
			'total_operations' => count( $operations ),
			'installs' => $summary['installs'],
			'updates' => $summary['updates'],
			'removes' => $summary['removes'],
		] );
		$result->addValue( null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'repo_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-apply-param-repoid',
			],
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-apply-param-repourl',
			],
			'ref_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-apply-param-refid',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-apply-param-ref',
			],
			'operations' => [
				ParamValidator::PARAM_TYPE => 'text', // JSON-encoded array
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-apply-param-operations',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiPacksApply&repo_id=1&ref=main&operations=[{"action":"install","pack_name":"MyPack","pages":[...]}]'
				=> 'apihelp-labkipacksapply-example-basic',
		];
	}

	/** POST required. */
	public function mustBePosted(): bool {
		return true;
	}

	/** Requires write mode. */
	public function isWriteMode(): bool {
		return true;
	}

	/** Internal API (not exposed publicly). */
	public function isInternal(): bool {
		return true;
	}
}

