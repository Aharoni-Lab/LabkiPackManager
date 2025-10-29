<?php

declare(strict_types=1);

namespace LabkiPackManager\Jobs;

use Job;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\LabkiPackManager;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;

/**
 * LabkiPackInstallJob
 *
 * Background job to install one or more content packs.
 * This job is queued by ApiLabkiPacksInstall to perform the following tasks asynchronously:
 *
 * ## Process:
 * 1. Validate ref and worktree exist
 * 2. Call LabkiPackManager->installPacks()
 * 3. Track progress through operation registry
 * 4. Report detailed results
 *
 * ## Progress Tracking:
 * - 0-10%: Validation
 * - 10-90%: Pack installation (distributed across packs)
 * - 90-100%: Finalization
 *
 * @ingroup Jobs
 */
final class LabkiPackInstallJob extends Job {

	/**
	 * @param Title $title Title context (unused but required)
	 * @param array $params Job parameters:
	 *  - ref_id: int (content ref ID)
	 *  - packs: array (pack definitions with pages)
	 *  - operation_id: string
	 *  - user_id: int
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'labkiPackInstall', $title, $params );
	}

	/** Execute the background job. */
	public function run(): bool {
		$refId = (int)( $this->params['ref_id'] ?? 0 );
		$packs = $this->params['packs'] ?? [];
		$operationIdStr = $this->params['operation_id'] ?? ('pack_install_' . uniqid());
		$operationId = new OperationId( $operationIdStr );
		$userId = (int)( $this->params['user_id'] ?? 0 );

		wfDebugLog( 'labkipack', "LabkiPackInstallJob::run() started for refId={$refId} (operation_id={$operationIdStr})" );

		// Basic validation
		if ( $refId === 0 || empty( $packs ) ) {
			wfDebugLog( 'labkipack', "LabkiPackInstallJob: missing ref_id or packs" );
			return false;
		}

        $packManager = new LabkiPackManager();
        $refRegistry = new LabkiRefRegistry();
        $operationRegistry = new LabkiOperationRegistry();

		$contentRefId = new ContentRefId( $refId );

		// Mark operation as started
		$totalPacks = count( $packs );
		$operationRegistry->startOperation(
			$operationId,
			"Starting installation of {$totalPacks} pack(s)"
		);

		try {
			// Step 1: Validate ref and worktree (0-10% progress)
			wfDebugLog( 'labkipack', "LabkiPackInstallJob: validating ref {$refId}" );
			$operationRegistry->setProgress( $operationId, 5, 'Validating repository ref' );

			$ref = $refRegistry->getRefById( $contentRefId );
			if ( !$ref ) {
				throw new \RuntimeException( "Ref not found: {$refId}" );
			}

			$worktreePath = $ref->worktreePath();
			if ( !$worktreePath || !is_dir( $worktreePath ) ) {
				throw new \RuntimeException( "Worktree not found for ref {$refId}: {$worktreePath}" );
			}

			wfDebugLog( 'labkipack', "LabkiPackInstallJob: using worktree: {$worktreePath}" );
			$operationRegistry->setProgress( $operationId, 10, 'Repository ref validated' );

			// Step 2: Install packs (10-90% progress)
			wfDebugLog( 'labkipack', "LabkiPackInstallJob: installing {$totalPacks} pack(s)" );
			$operationRegistry->setProgress(
				$operationId,
				15,
				"Installing {$totalPacks} pack(s)"
			);

			$installResult = $packManager->installPacks( $contentRefId, $packs, $userId );

			// Calculate detailed statistics
			$installedCount = count( $installResult['installed'] ?? [] );
			$failedCount = count( $installResult['failed'] ?? [] );
			$totalPagesCreated = 0;
			$totalPagesFailed = 0;

			foreach ( $installResult['installed'] ?? [] as $packResult ) {
				$totalPagesCreated += $packResult['pages_created'] ?? 0;
				$totalPagesFailed += $packResult['pages_failed'] ?? 0;
			}

			wfDebugLog(
				'labkipack',
				"LabkiPackInstallJob: installation complete - {$installedCount} packs installed, {$failedCount} failed"
			);

			$operationRegistry->setProgress( $operationId, 90, 'Pack installation complete' );

			// Step 3: Finalize (90-100% progress)
			$operationRegistry->setProgress( $operationId, 95, 'Finalizing installation' );

			// Build summary message
			$summary = "{$installedCount}/{$totalPacks} pack(s) installed successfully";
			if ( $totalPagesCreated > 0 ) {
				$summary .= " ({$totalPagesCreated} pages created";
				if ( $totalPagesFailed > 0 ) {
					$summary .= ", {$totalPagesFailed} failed";
				}
				$summary .= ")";
			}

			// Build result data
			$resultData = json_encode( [
				'ref_id' => $refId,
				'total_packs' => $totalPacks,
				'installed_packs' => $installedCount,
				'failed_packs' => $failedCount,
				'total_pages_created' => $totalPagesCreated,
				'total_pages_failed' => $totalPagesFailed,
				'packs' => array_map( function( $p ) {
					return [
						'pack' => $p['pack'],
						'version' => $p['version'] ?? null,
						'pack_id' => $p['pack_id'] ?? null,
						'pages_created' => $p['pages_created'] ?? 0,
						'pages_failed' => $p['pages_failed'] ?? 0,
					];
				}, $installResult['installed'] ?? [] ),
				'errors' => $installResult['errors'] ?? [],
			] );

			// Determine success level
			if ( $installedCount === $totalPacks ) {
				// Full success
				wfDebugLog( 'labkipack', "LabkiPackInstallJob SUCCESS: {$summary}" );
				$operationRegistry->completeOperation(
					$operationId,
					$summary,
					$resultData
				);
			} elseif ( $installedCount > 0 ) {
				// Partial success
				wfDebugLog( 'labkipack', "LabkiPackInstallJob PARTIAL: {$summary}" );
				$operationRegistry->completeOperation(
					$operationId,
					"Partial success: {$summary}",
					$resultData
				);
			} else {
				// Complete failure
				throw new \RuntimeException( "No packs were installed successfully" );
			}

			return true;

		} catch ( \Throwable $e ) {
			$errorMessage = "Failed to install packs: {$e->getMessage()}";
			wfDebugLog( 'labkipack', "LabkiPackInstallJob FAILED: {$errorMessage}" );

			$operationRegistry->failOperation(
				$operationId,
				$errorMessage,
				json_encode( [
					'ref_id' => $refId,
					'packs' => array_column( $packs, 'name' ),
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				] )
			);

			return false;
		}
	}
}

