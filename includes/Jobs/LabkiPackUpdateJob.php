<?php

declare(strict_types=1);

namespace LabkiPackManager\Jobs;

use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Services\LabkiPackManager;

/**
 * LabkiPackUpdateJob
 *
 * Background job to update one or more Labki content packs.
 * This job is queued by ApiLabkiPacksUpdate to perform the update asynchronously.
 *
 * @ingroup Jobs
 */
final class LabkiPackUpdateJob extends Job {

	/**
	 * @param Title $title Title context (unused but required)
	 * @param array $params Job parameters:
	 *  - ref_id: int (ContentRefId of the ref containing the packs)
	 *  - packs: array (Array of pack definitions with name and optional target_version)
	 *  - operation_id: string
	 *  - user_id: int
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'labkiPackUpdate', $title, $params );
	}

	/** Execute the background job. */
	public function run(): bool {
		$refIdInt = (int)( $this->params['ref_id'] ?? 0 );
		$packsToUpdate = $this->params['packs'] ?? [];
		$operationIdStr = $this->params['operation_id'] ?? ('pack_update_' . uniqid());
		$userId = (int)( $this->params['user_id'] ?? 0 );

		$refId = new ContentRefId( $refIdInt );
		$operationId = new OperationId( $operationIdStr );

		wfDebugLog( 'labkipack', "LabkiPackUpdateJob::run() started for refId={$refId->toInt()} (operation_id={$operationIdStr})" );

		// Basic validation
		if ( $refIdInt === 0 || empty( $packsToUpdate ) ) {
			wfDebugLog( 'labkipack', "LabkiPackUpdateJob: missing ref_id or packs" );
			return false;
		}

		try {
			$operationRegistry = new LabkiOperationRegistry();
			$packManager = new LabkiPackManager();
		} catch ( \Throwable $e ) {
			wfDebugLog( 'labkipack', "LabkiPackUpdateJob: failed to initialize services: {$e->getMessage()}" );
			return false;
		}

		// Mark operation as started
		$operationRegistry->startOperation( $operationId, 'Starting pack update' );

		$results = [
			'success' => true,
			'ref_id' => $refId->toInt(),
			'total_packs_requested' => count( $packsToUpdate ),
			'packs_updated' => [],
			'packs_failed' => [],
			'errors' => [],
		];

		try {
			$progressStep = 90 / count( $packsToUpdate );
			$currentProgress = 10; // Start after initial validation

			foreach ( $packsToUpdate as $packDef ) {
				$packName = $packDef['name'] ?? '';
				$targetVersion = $packDef['target_version'] ?? null;

				if ( $packName === '' ) {
					$results['success'] = false;
					$results['packs_failed'][] = [
						'pack' => '(unnamed)',
						'error' => 'Pack name is required',
					];
					$results['errors'][] = 'Pack name is required for one or more packs';
					continue;
				}

				try {
					$operationRegistry->setProgress( $operationId, (int)$currentProgress, "Updating pack: {$packName}" );
					wfDebugLog( 'labkipack', "LabkiPackUpdateJob: Attempting to update pack {$packName}" );

					$updateResult = $packManager->updatePackByName(
						$refId,
						$packName,
						$targetVersion,
						$userId
					);

					if ( $updateResult['success'] ) {
						$results['packs_updated'][] = [
							'pack' => $packName,
							'old_version' => $updateResult['old_version'] ?? null,
							'new_version' => $updateResult['new_version'] ?? null,
							'pages_updated' => $updateResult['pages_updated'] ?? 0,
						];
						wfDebugLog( 'labkipack', "LabkiPackUpdateJob: Successfully updated pack {$packName}" );
					} else {
						$results['success'] = false;
						$results['packs_failed'][] = [
							'pack' => $packName,
							'error' => $updateResult['error'] ?? 'Unknown error during update',
						];
						$results['errors'][] = "Failed to update pack {$packName}: " . ( $updateResult['error'] ?? 'Unknown error' );
						wfDebugLog( 'labkipack', "LabkiPackUpdateJob: Failed to update pack {$packName}: " . ( $updateResult['error'] ?? 'Unknown error' ) );
					}
				} catch ( \Throwable $e ) {
					$results['success'] = false;
					$results['packs_failed'][] = [
						'pack' => $packName,
						'error' => $e->getMessage(),
					];
					$results['errors'][] = "Exception updating pack {$packName}: {$e->getMessage()}";
					wfDebugLog( 'labkipack', "LabkiPackUpdateJob: Exception updating pack {$packName}: {$e->getMessage()}" );
				}
				$currentProgress += $progressStep;
			}

			// Finalize operation
			$operationRegistry->setProgress( $operationId, 95, 'Finalizing pack update' );

			$updatedCount = count( $results['packs_updated'] );
			$failedCount = count( $results['packs_failed'] );
			$totalRequested = $results['total_packs_requested'];

			$summary = "{$updatedCount}/{$totalRequested} packs updated successfully for ref ID {$refId->toInt()}";
			if ( $failedCount > 0 ) {
				$summary .= " ({$failedCount} failed)";
				$results['success'] = false; // Mark overall operation as failed if any pack failed
			}

			$resultDataJson = json_encode( $results );

			if ( $results['success'] ) {
				wfDebugLog( 'labkipack', "LabkiPackUpdateJob SUCCESS: {$summary}" );
				$operationRegistry->completeOperation(
					$operationId,
					$summary,
					$resultDataJson
				);
			} else {
				wfDebugLog( 'labkipack', "LabkiPackUpdateJob FAILED: {$summary}" );
				$operationRegistry->failOperation(
					$operationId,
					$summary,
					$resultDataJson
				);
			}

			return $results['success'];

		} catch ( \Throwable $e ) {
			$errorMessage = "Failed to update packs for ref ID {$refId->toInt()}: {$e->getMessage()}";
			wfDebugLog( 'labkipack', "LabkiPackUpdateJob FAILED: {$errorMessage}" );
			
			$operationRegistry->failOperation(
				$operationId,
				$errorMessage,
				json_encode( [
					'ref_id' => $refId->toInt(),
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				] )
			);
			
			return false;
		}
	}
}


