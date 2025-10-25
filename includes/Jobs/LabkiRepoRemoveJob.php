<?php

declare(strict_types=1);

namespace LabkiPackManager\Jobs;

use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use LabkiPackManager\Services\GitContentManager;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;

/**
 * LabkiRepoRemoveJob
 *
 * Background job to remove a Labki content repository or specific refs.
 * This job is queued by ApiLabkiReposRemove to perform the following tasks asynchronously:
 *
 * ## Remove Operations:
 * 1. Remove worktrees from filesystem
 * 2. Remove database entries (refs and/or repository)
 * 3. Remove bare repository from filesystem (if removing entire repo)
 * 4. Clean up associated packs and pages (future implementation)
 *
 * @ingroup Jobs
 */
final class LabkiRepoRemoveJob extends Job {

	/**
	 * @param \Title $title Title context (unused but required)
	 * @param array $params Job parameters:
	 *  - url: string (repository URL)
	 *  - refs: string[]|null (specific refs to remove, or null to remove entire repo)
	 *  - operation_id: string
	 *  - user_id: int
	 */
	public function __construct( \Title $title, array $params ) {
		parent::__construct( 'labkiRepoRemove', $title, $params );
	}

	/**
	 * Execute the removal job.
	 *
	 * @return bool Success status
	 */
	public function run(): bool {
		$url = $this->params['url'] ?? '';
		$refs = $this->params['refs'] ?? null;
		$operationId = $this->params['operation_id'] ?? '';

		wfDebugLog( 'labkipack', "LabkiRepoRemoveJob: starting removal for {$url}" );

		if ( empty( $url ) || empty( $operationId ) ) {
			wfDebugLog( 'labkipack', 'LabkiRepoRemoveJob: missing required parameters' );
			return false;
		}

		$operationRegistry = new LabkiOperationRegistry();
		$contentManager = new GitContentManager();

		// Determine removal scope
		$removeAll = $refs === null || empty( $refs );
		$removeMessage = $removeAll 
			? 'Removing entire repository' 
			: 'Removing ' . count( $refs ) . ' specific ref(s)';

		// Mark operation as started
		$operationRegistry->startOperation( $operationId, $removeMessage );

		try {
			if ( $removeAll ) {
				// Remove entire repository
				wfDebugLog( 'labkipack', "LabkiRepoRemoveJob: removing entire repo {$url}" );
				
				$operationRegistry->setProgress( $operationId, 10, 'Starting repository removal' );
				$removedRefs = $contentManager->removeRepo( $url );
				
				$operationRegistry->setProgress( $operationId, 90, 'Finalizing removal' );
				
				$resultData = [
					'url' => $url,
					'removal_type' => 'full',
					'removed_refs' => $removedRefs,
				];

				$operationRegistry->completeOperation(
					$operationId,
					"Repository successfully removed ({$removedRefs} refs)",
					$resultData
				);

				wfDebugLog( 'labkipack', "LabkiRepoRemoveJob: removed repo {$url} ({$removedRefs} refs)" );

			} else {
				// Remove specific refs
				wfDebugLog( 'labkipack', "LabkiRepoRemoveJob: removing " . count( $refs ) . " specific refs" );
				
				$totalRefs = count( $refs );
				$removedRefs = [];
				$failedRefs = [];
				$progressPerRef = $totalRefs > 0 ? 80 / $totalRefs : 0; // 80% of progress for refs

				foreach ( $refs as $index => $ref ) {
					try {
						$currentProgress = 10 + (int)( $progressPerRef * $index );
						$operationRegistry->setProgress(
							$operationId,
							$currentProgress,
							"Removing ref {$ref} (" . ($index + 1) . "/{$totalRefs})"
						);

						$contentManager->removeRef( $url, $ref );
						$removedRefs[] = $ref;
						wfDebugLog( 'labkipack', "LabkiRepoRemoveJob: removed {$url}@{$ref}" );
					} catch ( \Throwable $e ) {
						$failedRefs[] = [
							'ref' => $ref,
							'error' => $e->getMessage()
						];
						wfDebugLog(
							'labkipack',
							"LabkiRepoRemoveJob: failed to remove {$url}@{$ref}: " . $e->getMessage()
						);
					}
				}

				$operationRegistry->setProgress( $operationId, 90, 'Finalizing removal' );

				$resultData = [
					'url' => $url,
					'removal_type' => 'selective',
					'requested_refs' => $totalRefs,
					'removed_refs' => count( $removedRefs ),
					'failed_refs' => count( $failedRefs ),
					'removed_ref_list' => $removedRefs,
					'failed_ref_list' => $failedRefs,
				];

				$summary = count( $removedRefs ) . '/' . $totalRefs . ' refs removed successfully';
				if ( !empty( $failedRefs ) ) {
					$summary .= ' (' . count( $failedRefs ) . ' failed)';
				}

				if ( count( $removedRefs ) === $totalRefs ) {
					$operationRegistry->completeOperation(
						$operationId,
						$summary,
						$resultData
					);
				} elseif ( count( $removedRefs ) > 0 ) {
					$operationRegistry->completeOperation(
						$operationId,
						"Partial success: {$summary}",
						$resultData
					);
				} else {
					throw new \RuntimeException( "Failed to remove any refs" );
				}

				wfDebugLog( 'labkipack', "LabkiRepoRemoveJob: removed " . count( $removedRefs ) . "/{$totalRefs} refs" );
			}

			return true;

		} catch ( \Throwable $e ) {
			wfDebugLog( 'labkipack', "LabkiRepoRemoveJob: exception - " . $e->getMessage() );

			$operationRegistry->failOperation(
				$operationId,
				"Removal failed: " . $e->getMessage()
			);

			return false;
		}
	}
}

