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
 *   'pack_id' => 123,
 *   'delete_pages' => true
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
		$refIdInt = (int)( $this->params['ref_id'] ?? 0 );
		$operations = $this->params['operations'] ?? [];
		$operationIdStr = $this->params['operation_id'] ?? ( 'pack_apply_' . uniqid() );
		$userId = (int)( $this->params['user_id'] ?? 0 );

		$refId = new ContentRefId( $refIdInt );
		$operationId = new OperationId( $operationIdStr );

		wfDebugLog( 'labkipack', "LabkiPackApplyJob::run() started for refId={$refId->toInt()} with " . count( $operations ) . " operation(s) (operation_id={$operationIdStr})" );

		// Basic validation
		if ( $refIdInt === 0 || empty( $operations ) ) {
			wfDebugLog( 'labkipack', "LabkiPackApplyJob: missing ref_id or operations" );
			return false;
		}

		try {
			$operationRegistry = new LabkiOperationRegistry();
			$packManager = new LabkiPackManager();
		} catch ( \Throwable $e ) {
			$errorMessage = "LabkiPackApplyJob: failed to initialize services: {$e->getMessage()}";
			wfDebugLog( 'labkipack', $errorMessage );
			return false;
		}

		// Mark operation as started
		$operationRegistry->startOperation( $operationId, 'Starting pack operations' );

		// Separate operations by type
		$removeOps = [];
		$installOps = [];
		$updateOps = [];

		foreach ( $operations as $op ) {
			$action = $op['action'] ?? 'unknown';
			if ( $action === 'remove' ) {
				$removeOps[] = $op;
			} elseif ( $action === 'install' ) {
				$installOps[] = $op;
			} elseif ( $action === 'update' ) {
				$updateOps[] = $op;
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
				$packId = new PackId( (int)$op['pack_id'] );
				$deletePages = $op['delete_pages'] ?? false;

				$operationRegistry->setProgress( $operationId, (int)$currentProgress, "Removing pack ID {$packId->toInt()}" );
				
				try {
					$removeResult = $packManager->removePack( $packId, $deletePages, $userId );
					
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
				$packName = $op['pack_name'] ?? 'Unknown Pack';
				$pages = $op['pages'] ?? [];

				$operationRegistry->setProgress( $operationId, (int)$currentProgress, "Installing pack: {$packName}" );
				
				try {
					// Build pack definition from operation
					$packDef = [
						'name' => $packName,
						'version' => $op['version'] ?? null,
						'pages' => [],
					];

					// Map pages with their final titles
					foreach ( $pages as $page ) {
						$pageName = $page['name'] ?? $page['original'] ?? null;
						$finalTitle = $page['final_title'] ?? $page['finalTitle'] ?? null;
						
						if ( $pageName && $finalTitle ) {
							$packDef['pages'][] = [
								'name' => $pageName,
								'finalTitle' => $finalTitle,
							];
						}
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
				$packName = $op['pack_name'] ?? 'Unknown Pack';
				$targetVersion = $op['target_version'] ?? null;

				$operationRegistry->setProgress( $operationId, (int)$currentProgress, "Updating pack: {$packName}" );
				
				try {
					$updateResult = $packManager->updatePackByName( $refId, $packName, $targetVersion, $userId );
					
					if ( $updateResult['success'] ) {
						$results['updates'][] = [
							'pack_name' => $packName,
							'old_version' => $updateResult['old_version'] ?? null,
							'new_version' => $updateResult['new_version'] ?? null,
							'pages_updated' => $updateResult['pages_updated'] ?? 0,
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

