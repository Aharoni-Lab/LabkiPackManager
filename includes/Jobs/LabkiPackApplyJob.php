<?php

declare(strict_types=1);

namespace LabkiPackManager\Jobs;

use Job;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Services\LabkiPackManager;

/**
 * LabkiPackApplyJob
 *
 * Unified background job to apply batch pack operations (install, update, remove).
 * This job is queued by ApiLabkiPacksState (command=apply) to perform operations
 * asynchronously in a single atomic transaction.
 *
 * ## Operation Order
 * Operations are processed in dependency-safe order:
 * 1. REMOVE operations (clean up first)
 * 2. INSTALL operations (install dependencies before dependents)
 * 3. UPDATE operations (update after installs are complete)
 *
 * ## Expected Operation Format
 * Install/Update operations:
 * ```php
 * [
 *   'action' => 'install',
 *   'pack_name' => 'MyPack',
 *   'pages' => [
 *     ['name' => 'page1', 'final_title' => 'Custom/Title1'],
 *     ['name' => 'page2', 'final_title' => 'Custom/Title2']
 *   ]
 * ]
 * ```
 *
 * Remove operations:
 * ```php
 * [
 *   'action' => 'remove',
 *   'pack_id' => 123
 * ]
 * ```
 *
 * ## Error Handling
 * - If any operation fails, the entire batch is marked as failed
 * - Partial results are recorded in the operation result data
 * - Already-completed operations are not rolled back (MediaWiki doesn't support transactions)
 *
 * @ingroup Jobs
 */
final class LabkiPackApplyJob extends Job {

	/**
	 * @param Title $title Title context (unused but required)
	 * @param array $params Job parameters:
	 *  - ref_id: int (ContentRefId)
	 *  - operations: array (Array of operation definitions)
	 *  - operation_id: string
	 *  - user_id: int
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'labkiPackApply', $title, $params );
	}

	/** Execute the background job. */
	public function run(): bool {
		$operationRegistry = new LabkiOperationRegistry();
		$packManager = new LabkiPackManager();

		// Basic validation - all parameters are required
		if ( !isset( $this->params['ref_id'] ) ) {
			wfDebugLog( 'labkipack', "LabkiPackApplyJob: missing required parameter 'ref_id'" );
			return false;
		}
		if ( !isset( $this->params['operations'] ) ) {
			wfDebugLog( 'labkipack', "LabkiPackApplyJob: missing required parameter 'operations'" );
			return false;
		}
		if ( !isset( $this->params['operation_id'] ) ) {
			wfDebugLog( 'labkipack', "LabkiPackApplyJob: missing required parameter 'operation_id'" );
			return false;
		}
		if ( !isset( $this->params['user_id'] ) ) {
			wfDebugLog( 'labkipack', "LabkiPackApplyJob: missing required parameter 'user_id'" );
			return false;
		}

		$refIdInt = (int)$this->params['ref_id'];
		$operations = $this->params['operations'];
		$operationIdStr = $this->params['operation_id'];
		$userId = (int)$this->params['user_id'];

		if ( $refIdInt === 0 || empty( $operations ) ) {
			wfDebugLog( 'labkipack', "LabkiPackApplyJob: invalid ref_id or empty operations array" );
			return false;
		}

		$refId = new ContentRefId( $refIdInt );
		$operationId = new OperationId( $operationIdStr );

		wfDebugLog( 'labkipack', "LabkiPackApplyJob::run() started for refId={$refId->toInt()} with " . count( $operations ) . " operation(s) (operation_id={$operationIdStr})" );

		// Mark operation as started
		$operationRegistry->startOperation( $operationId, 'Starting pack operations' );

		// Separate operations by type
		$removeOps = [];
		$installOps = [];
		$updateOps = [];

		foreach ( $operations as $op ) {
			$action = $op['action'];
			switch ( $action ) {
				case 'remove':
					$removeOps[] = $op;
					break;
				case 'install':
					$installOps[] = $op;
					break;
				case 'update':
					$updateOps[] = $op;
					break;
				default:
					// We shouldn't ever get here, but we should handle it?
					throw new \InvalidArgumentException( "Invalid action: {$action}" );
			}
		}

		$results = [
			'success' => true,
			'ref_id' => $refId->toInt(),
			'total_operations' => count( $operations ),
			'operations_completed' => 0,
			'operations_failed' => 0,
			'removes' => [],
			'installs' => [],
			'updates' => [],
			'errors' => [],
		];

		$totalOps = count( $operations );
		$completedOps = 0;
		$progressIncrement = 90.0 / max( $totalOps, 1 );
		$currentProgress = 5; // Start after initialization

		try {
		// Phase 1: REMOVE operations
		wfDebugLog( 'labkipack', "LabkiPackApplyJob: Phase 1 - Processing " . count( $removeOps ) . " remove operation(s)" );
		foreach ( $removeOps as $op ) {
			// Validate required parameters
			if ( !isset( $op['pack_id'] ) ) {
				$results['success'] = false;
				$results['operations_failed']++;
				$results['removes'][] = [
					'success' => false,
					'error' => 'Missing required parameter: pack_id',
				];
				$results['errors'][] = 'Remove operation missing required parameter: pack_id';
				$currentProgress += $progressIncrement;
				continue;
			}

			$packId = new PackId( (int)$op['pack_id'] );

			$operationRegistry->setProgress( $operationId, (int)$currentProgress, "Removing pack ID {$packId->toInt()}" );
			
			try {
				$removeResult = $packManager->removePack( $packId, $userId );
					
				if ( $removeResult['success'] ) {
					$results['removes'][] = [
						'pack_id' => $packId->toInt(),
						'pack_name' => $removeResult['pack_name'] ?? 'Unknown',
						'pages_deleted' => $removeResult['pages_deleted'] ?? 0,
						'success' => true,
					];
						$completedOps++;
						$results['operations_completed']++;
					} else {
					$results['success'] = false;
					$results['operations_failed']++;
					$results['removes'][] = [
						'pack_id' => $packId->toInt(),
						'success' => false,
						'error' => $removeResult['error'] ?? 'Unknown error',
					];
						$results['errors'][] = "Remove pack {$packId->toInt()}: " . ( $removeResult['error'] ?? 'Unknown error' );
					}
				} catch ( \Throwable $e ) {
					$results['success'] = false;
					$results['operations_failed']++;
					$results['removes'][] = [
						'pack_id' => $packId->toInt(),
						'success' => false,
						'error' => $e->getMessage(),
					];
					$results['errors'][] = "Exception removing pack {$packId->toInt()}: {$e->getMessage()}";
					wfDebugLog( 'labkipack', "LabkiPackApplyJob: Exception removing pack {$packId->toInt()}: {$e->getMessage()}" );
				}

				$currentProgress += $progressIncrement;
			}

		// Phase 2: INSTALL operations
		wfDebugLog( 'labkipack', "LabkiPackApplyJob: Phase 2 - Processing " . count( $installOps ) . " install operation(s)" );
		foreach ( $installOps as $op ) {
			// Validate required parameters
			$missingParams = [];
			if ( !isset( $op['pack_name'] ) ) {
				$missingParams[] = 'pack_name';
			}
			if ( !isset( $op['pages'] ) ) {
				$missingParams[] = 'pages';
			}
			if ( !isset( $op['version'] ) && !isset( $op['target_version'] ) ) {
				$missingParams[] = 'version or target_version';
			}

			if ( !empty( $missingParams ) ) {
				$results['success'] = false;
				$results['operations_failed']++;
				$results['installs'][] = [
					'success' => false,
					'error' => 'Missing required parameters: ' . implode( ', ', $missingParams ),
				];
				$results['errors'][] = 'Install operation missing required parameters: ' . implode( ', ', $missingParams );
				$currentProgress += $progressIncrement;
				continue;
			}

			$packName = $op['pack_name'];
			$pages = $op['pages'];
			$version = $op['target_version'] ?? $op['version'];

			$operationRegistry->setProgress( $operationId, (int)$currentProgress, "Installing pack: {$packName}" );
			
		try {
			// Build pack definition from operation
			$packDef = [
				'name' => $packName,
				'version' => $version,
				'pages' => [],
			];

			// Map pages with their final titles
			$packDef['pages'] = [];
			foreach ( $pages as $page ) {
				if ( !isset( $page['name'] ) ) {
					throw new \RuntimeException( "Page missing required 'name' field in install operation for pack {$packName}" );
				}
				if ( !isset( $page['final_title'] ) ) {
					throw new \RuntimeException( "Page '{$page['name']}' missing required 'final_title' field in install operation for pack {$packName}" );
				}
				$packDef['pages'][] = [
					'name' => $page['name'],
					'final_title' => $page['final_title']
				];
			}

					$installResult = $packManager->installSinglePack( $refId, $packDef, $userId );
					
					if ( $installResult['success'] ) {
						$results['installs'][] = [
							'pack_name' => $packName,
							'pack_id' => $installResult['pack_id'] ?? null,
							'pages_created' => $installResult['pages_created'] ?? 0,
							'success' => true,
						];
						$completedOps++;
						$results['operations_completed']++;
					} else {
						$results['success'] = false;
						$results['operations_failed']++;
						$results['installs'][] = [
							'pack_name' => $packName,
							'success' => false,
							'error' => $installResult['error'] ?? 'Unknown error',
						];
						$results['errors'][] = "Install pack {$packName}: " . ( $installResult['error'] ?? 'Unknown error' );
					}
				} catch ( \Throwable $e ) {
					$results['success'] = false;
					$results['operations_failed']++;
					$results['installs'][] = [
						'pack_name' => $packName,
						'success' => false,
						'error' => $e->getMessage(),
					];
					$results['errors'][] = "Exception installing pack {$packName}: {$e->getMessage()}";
					wfDebugLog( 'labkipack', "LabkiPackApplyJob: Exception installing pack {$packName}: {$e->getMessage()}" );
				}

				$currentProgress += $progressIncrement;
			}

		// Phase 3: UPDATE operations
		wfDebugLog( 'labkipack', "LabkiPackApplyJob: Phase 3 - Processing " . count( $updateOps ) . " update operation(s)" );
		foreach ( $updateOps as $op ) {
			// Validate required parameters
			$missingParams = [];
			if ( !isset( $op['pack_name'] ) ) {
				$missingParams[] = 'pack_name';
			}
			if ( !isset( $op['target_version'] ) ) {
				$missingParams[] = 'target_version';
			}

			if ( !empty( $missingParams ) ) {
				$results['success'] = false;
				$results['operations_failed']++;
				$results['updates'][] = [
					'success' => false,
					'error' => 'Missing required parameters: ' . implode( ', ', $missingParams ),
				];
				$results['errors'][] = 'Update operation missing required parameters: ' . implode( ', ', $missingParams );
				$currentProgress += $progressIncrement;
				continue;
			}

			$packName = $op['pack_name'];
			$targetVersion = $op['target_version'];

			$operationRegistry->setProgress( $operationId, (int)$currentProgress, "Updating pack: {$packName}" );
			
			try {
				$updateResult = $packManager->updatePackByName( $refId, $packName, $userId, $targetVersion );
					
					if ( $updateResult['success'] ) {
						$results['updates'][] = [
							'pack_name' => $packName,
							'old_version' => $updateResult['old_version'],
							'new_version' => $updateResult['new_version'],
							'pages_updated' => $updateResult['pages_updated'],
							'success' => true,
						];
						$completedOps++;
						$results['operations_completed']++;
					} else {
						$results['success'] = false;
						$results['operations_failed']++;
						$results['updates'][] = [
							'pack_name' => $packName,
							'success' => false,
							'error' => $updateResult['error'] ?? 'Unknown error',
						];
						$results['errors'][] = "Update pack {$packName}: " . ( $updateResult['error'] ?? 'Unknown error' );
					}
				} catch ( \Throwable $e ) {
					$results['success'] = false;
					$results['operations_failed']++;
					$results['updates'][] = [
						'pack_name' => $packName,
						'success' => false,
						'error' => $e->getMessage(),
					];
					$results['errors'][] = "Exception updating pack {$packName}: {$e->getMessage()}";
					wfDebugLog( 'labkipack', "LabkiPackApplyJob: Exception updating pack {$packName}: {$e->getMessage()}" );
				}

				$currentProgress += $progressIncrement;
			}

			// Finalize operation
			$operationRegistry->setProgress( $operationId, 95, 'Finalizing operations' );

			$summary = sprintf(
				"%d/%d operations completed successfully for ref ID %d",
				$results['operations_completed'],
				$totalOps,
				$refId->toInt()
			);

			if ( $results['operations_failed'] > 0 ) {
				$summary .= " ({$results['operations_failed']} failed)";
			}

			$resultDataJson = json_encode( $results );

			if ( $results['success'] ) {
				wfDebugLog( 'labkipack', "LabkiPackApplyJob SUCCESS: {$summary}" );
				$operationRegistry->completeOperation(
					$operationId,
					$summary,
					$resultDataJson
				);
			} else {
				wfDebugLog( 'labkipack', "LabkiPackApplyJob FAILED: {$summary}" );
				$operationRegistry->failOperation(
					$operationId,
					$summary,
					$resultDataJson
				);
			}

			return $results['success'];

		} catch ( \Throwable $e ) {
			$errorMessage = "Failed to apply pack operations for ref ID {$refId->toInt()}: {$e->getMessage()}";
			wfDebugLog( 'labkipack', "LabkiPackApplyJob FAILED: {$errorMessage}" );
			
			$operationRegistry->failOperation(
				$operationId,
				$errorMessage,
				json_encode( [
					'ref_id' => $refId->toInt(),
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
					'partial_results' => $results,
				] )
			);
			
			return false;
		}
	}
}

