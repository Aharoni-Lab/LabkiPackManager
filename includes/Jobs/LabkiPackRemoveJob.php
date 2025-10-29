<?php

declare(strict_types=1);

namespace LabkiPackManager\Jobs;

use Job;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Services\LabkiPackManager;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;

/**
 * LabkiPackRemoveJob
 *
 * Background job to remove/uninstall one or more Labki content packs.
 * This job is queued by ApiLabkiPacksRemove to perform the following tasks asynchronously:
 *
 * ## Process:
 * 1. Validate ref exists
 * 2. Validate all pack IDs exist
 * 3. Call LabkiPackManager->removePack() for each pack
 * 4. Track progress through operation registry
 * 5. Report detailed results
 *
 * ## Progress Tracking:
 * - 0-10%: Validation
 * - 10-90%: Pack removal (distributed across packs)
 * - 90-100%: Finalization
 *
 * @ingroup Jobs
 */
final class LabkiPackRemoveJob extends Job {

	/**
	 * @param Title $title Title context (unused but required)
	 * @param array $params Job parameters:
	 *  - ref_id: int (content ref ID)
	 *  - pack_ids: array (array of pack IDs to remove)
	 *  - delete_pages: bool (whether to delete MediaWiki pages)
	 *  - operation_id: string
	 *  - user_id: int
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'labkiPackRemove', $title, $params );
	}

	/** Execute the background job. */
	public function run(): bool {
		$refId = (int)( $this->params['ref_id'] ?? 0 );
		$packIds = $this->params['pack_ids'] ?? [];
		$deletePages = (bool)( $this->params['delete_pages'] ?? false );
		$operationIdStr = $this->params['operation_id'] ?? ('pack_remove_' . uniqid());
		$operationId = new OperationId( $operationIdStr );
		$userId = (int)( $this->params['user_id'] ?? 0 );

		wfDebugLog( 'labkipack', "LabkiPackRemoveJob::run() started for refId={$refId} (operation_id={$operationIdStr}, delete_pages={$deletePages})" );

		// Basic validation
		if ( $refId === 0 || empty( $packIds ) ) {
			wfDebugLog( 'labkipack', "LabkiPackRemoveJob: missing ref_id or pack_ids" );
			return false;
		}

		$packManager = new LabkiPackManager();
		$refRegistry = new LabkiRefRegistry();
		$operationRegistry = new LabkiOperationRegistry();

		$contentRefId = new ContentRefId( $refId );

		// Mark operation as started
		$totalPacks = count( $packIds );
		$operationRegistry->startOperation(
			$operationId,
			"Starting removal of {$totalPacks} pack(s)"
		);

		try {
			// Step 1: Validate ref exists (0-10% progress)
			wfDebugLog( 'labkipack', "LabkiPackRemoveJob: validating ref {$refId}" );
			$operationRegistry->setProgress( $operationId, 5, 'Validating repository ref' );

			$ref = $refRegistry->getRefById( $contentRefId );
			if ( !$ref ) {
				throw new \RuntimeException( "Ref not found: {$refId}" );
			}

			wfDebugLog( 'labkipack', "LabkiPackRemoveJob: ref validated" );
			$operationRegistry->setProgress( $operationId, 10, 'Repository ref validated' );

			// Step 2: Remove packs (10-90% progress)
			wfDebugLog( 'labkipack', "LabkiPackRemoveJob: removing {$totalPacks} pack(s)" );
			$operationRegistry->setProgress(
				$operationId,
				15,
				"Removing {$totalPacks} pack(s)"
			);

			$removedPacks = [];
			$failedPacks = [];
			$totalPagesDeleted = 0;
			$totalPagesFailed = 0;

			foreach ( $packIds as $index => $packIdValue ) {
				$packId = new PackId( (int)$packIdValue );
				
				// Update progress
				$progress = 15 + (int)( ( $index / $totalPacks ) * 75 );
				$operationRegistry->setProgress(
					$operationId,
					$progress,
					"Removing pack {$packIdValue}"
				);

				try {
					$removeResult = $packManager->removePack( $packId, $deletePages, $userId );

					if ( $removeResult['success'] ) {
						$removedPacks[] = $removeResult;
						$totalPagesDeleted += $removeResult['pages_deleted'] ?? 0;
						$totalPagesFailed += $removeResult['pages_failed'] ?? 0;
					} else {
						$failedPacks[] = [
							'pack_id' => $packIdValue,
							'error' => $removeResult['error'] ?? 'Unknown error',
						];
					}
				} catch ( \Exception $e ) {
					wfDebugLog( 'labkipack', "Exception removing pack {$packIdValue}: " . $e->getMessage() );
					$failedPacks[] = [
						'pack_id' => $packIdValue,
						'error' => $e->getMessage(),
					];
				}
			}

			$removedCount = count( $removedPacks );
			$failedCount = count( $failedPacks );

			wfDebugLog(
				'labkipack',
				"LabkiPackRemoveJob: removal complete - {$removedCount} packs removed, {$failedCount} failed"
			);

			$operationRegistry->setProgress( $operationId, 90, 'Pack removal complete' );

			// Step 3: Finalize (90-100% progress)
			$operationRegistry->setProgress( $operationId, 95, 'Finalizing removal' );

			// Build summary message
			$summary = "{$removedCount}/{$totalPacks} pack(s) removed successfully";
			if ( $deletePages && $totalPagesDeleted > 0 ) {
				$summary .= " ({$totalPagesDeleted} pages deleted";
				if ( $totalPagesFailed > 0 ) {
					$summary .= ", {$totalPagesFailed} failed";
				}
				$summary .= ")";
			}

			// Build result data
			$resultData = json_encode( [
				'ref_id' => $refId,
				'total_packs' => $totalPacks,
				'removed_packs' => $removedCount,
				'failed_packs' => $failedCount,
				'total_pages_deleted' => $totalPagesDeleted,
				'total_pages_failed' => $totalPagesFailed,
				'delete_pages' => $deletePages,
				'packs' => array_map( function( $p ) {
					return [
						'pack' => $p['pack'],
						'pack_id' => $p['pack_id'],
						'pages_deleted' => $p['pages_deleted'] ?? 0,
						'pages_failed' => $p['pages_failed'] ?? 0,
					];
				}, $removedPacks ),
				'errors' => $failedPacks,
			] );

			// Determine success level
			if ( $removedCount === $totalPacks ) {
				// Full success
				wfDebugLog( 'labkipack', "LabkiPackRemoveJob SUCCESS: {$summary}" );
				$operationRegistry->completeOperation(
					$operationId,
					$summary,
					$resultData
				);
			} elseif ( $removedCount > 0 ) {
				// Partial success
				wfDebugLog( 'labkipack', "LabkiPackRemoveJob PARTIAL: {$summary}" );
				$operationRegistry->completeOperation(
					$operationId,
					"Partial success: {$summary}",
					$resultData
				);
			} else {
				// Complete failure
				throw new \RuntimeException( "No packs were removed successfully" );
			}

			return true;

		} catch ( \Throwable $e ) {
			$errorMessage = "Failed to remove packs: {$e->getMessage()}";
			wfDebugLog( 'labkipack', "LabkiPackRemoveJob FAILED: {$errorMessage}" );

			$operationRegistry->failOperation(
				$operationId,
				$errorMessage,
				json_encode( [
					'ref_id' => $refId,
					'pack_ids' => $packIds,
					'delete_pages' => $deletePages,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				] )
			);

			return false;
		}
	}
}

