<?php

declare(strict_types=1);

namespace LabkiPackManager\Jobs;

use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Services\GitContentManager;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;

/**
 * LabkiRepoSyncJob
 *
 * Background job to sync/update a Labki content repository.
 * This job is queued by ApiLabkiReposSync to perform the following tasks asynchronously:
 *
 * ## Sync Operations:
 * 1. Fetch updates from remote for bare repository
 * 2. Update worktrees for specified refs (or all refs if none specified)
 * 3. Update database entries with new commit hashes
 * 4. Update manifest information for each synced ref
 *
 * The bare repository is always fetched to ensure we have the latest remote state.
 *
 * @ingroup Jobs
 */
final class LabkiRepoSyncJob extends Job {

	/**
	 * @param Title $title Title context (unused but required)
	 * @param array $params Job parameters:
	 *  - url: string (repository URL)
	 *  - refs: string[]|null (specific refs to sync, or null for all refs)
	 *  - operation_id: string
	 *  - user_id: int
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'labkiRepoSync', $title, $params );
	}

	/**
	 * Execute the sync job.
	 *
	 * @return bool Success status
	 */
	public function run(): bool {
		$url = $this->params['url'] ?? '';
		$refs = $this->params['refs'] ?? null;
		$operationIdStr = $this->params['operation_id'] ?? '';

		wfDebugLog( 'labkipack', "LabkiRepoSyncJob: starting sync for {$url}" );

		if ( empty( $url ) || empty( $operationIdStr ) ) {
			wfDebugLog( 'labkipack', 'LabkiRepoSyncJob: missing required parameters' );
			return false;
		}
		
		$operationId = new OperationId( $operationIdStr );

		$operationRegistry = new LabkiOperationRegistry();
		$contentManager = new GitContentManager();
		$refRegistry = new LabkiRefRegistry();

		// Determine sync scope
		$syncAll = $refs === null || empty( $refs );
		$syncMessage = $syncAll 
			? 'Syncing all refs in repository' 
			: 'Syncing ' . count( $refs ) . ' specific ref(s)';

		// Mark operation as started
		$operationRegistry->startOperation( $operationId, $syncMessage );

		try {
			if ( $syncAll ) {
				// Sync entire repository
				wfDebugLog( 'labkipack', "LabkiRepoSyncJob: syncing all refs for {$url}" );
				
				$operationRegistry->setProgress( $operationId, 10, 'Fetching repository updates' );
				$syncedCount = $contentManager->syncRepo( $url );
				
				$operationRegistry->setProgress( $operationId, 90, 'Finalizing sync' );
				
				$resultData = [
					'url' => $url,
					'sync_type' => 'full',
					'synced_refs' => $syncedCount,
				];

				$operationRegistry->completeOperation(
					$operationId,
					"Repository synced successfully ({$syncedCount} refs)",
					json_encode( $resultData )
				);

				wfDebugLog( 'labkipack', "LabkiRepoSyncJob: synced {$syncedCount} refs for {$url}" );

			} else {
				// Sync specific refs
				wfDebugLog( 'labkipack', "LabkiRepoSyncJob: syncing " . count( $refs ) . " specific refs" );
				
				// First, fetch the bare repository (always do this)
				$operationRegistry->setProgress( $operationId, 10, 'Fetching repository updates' );
				$contentManager->ensureBareRepo( $url );

				$totalRefs = count( $refs );
				$successRefs = 0;
				$failedRefs = [];
				$progressPerRef = $totalRefs > 0 ? 70 / $totalRefs : 0; // 70% of progress for refs

				foreach ( $refs as $index => $ref ) {
					try {
						$currentProgress = 10 + (int)( $progressPerRef * $index );
						$operationRegistry->setProgress(
							$operationId,
							$currentProgress,
							"Syncing ref {$ref} (" . ($index + 1) . "/{$totalRefs})"
						);

						$contentManager->syncRef( $url, $ref );
						$successRefs++;
						wfDebugLog( 'labkipack', "LabkiRepoSyncJob: synced {$url}@{$ref}" );
					} catch ( \Throwable $e ) {
						$failedRefs[] = [
							'ref' => $ref,
							'error' => $e->getMessage()
						];
						wfDebugLog(
							'labkipack',
							"LabkiRepoSyncJob: failed to sync {$url}@{$ref}: " . $e->getMessage()
						);
					}
				}

				$operationRegistry->setProgress( $operationId, 90, 'Finalizing sync' );

				$resultData = [
					'url' => $url,
					'sync_type' => 'selective',
					'requested_refs' => $totalRefs,
					'synced_refs' => $successRefs,
					'failed_refs' => count( $failedRefs ),
				];

				if ( $successRefs === $totalRefs ) {
					$operationRegistry->completeOperation(
						$operationId,
						"All refs synced successfully ({$successRefs}/{$totalRefs})",
						json_encode( $resultData )
					);
				} elseif ( $successRefs > 0 ) {
					$operationRegistry->completeOperation(
						$operationId,
						"Partial success: {$successRefs}/{$totalRefs} refs synced",
						json_encode( $resultData )
					);
				} else {
					throw new \RuntimeException( "Failed to sync any refs" );
				}

				wfDebugLog( 'labkipack', "LabkiRepoSyncJob: synced {$successRefs}/{$totalRefs} refs" );
			}

			return true;

		} catch ( \Throwable $e ) {
			wfDebugLog( 'labkipack', "LabkiRepoSyncJob: exception - " . $e->getMessage() );

			$operationRegistry->failOperation(
				$operationId,
				"Sync failed: " . $e->getMessage()
			);

			return false;
		}
	}
}

