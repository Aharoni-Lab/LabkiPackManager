<?php

declare(strict_types=1);

namespace LabkiPackManager\Jobs;

use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use LabkiPackManager\Services\GitContentManager;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;

/**
 * LabkiRepoAddJob
 *
 * Background job to initialize a new Labki content repository.
 * This job is queued by ApiLabkiReposAdd to perform the following tasks asynchronously:
 *
 * 1. Create or update a bare Git repository mirror
 * 2. Register the repository in the database
 * 3. Create worktrees for each specified ref
 * 4. Register each ref in the database
 *
 * Designed to mirror InitializeContentRepos.php but without console output.
 *
 * @ingroup Jobs
 */
final class LabkiRepoAddJob extends Job {

	/**
	 * @param \Title $title Title context (unused but required)
	 * @param array $params Job parameters:
	 *  - url: string (repository URL)
	 *  - refs: string[] (refs to initialize)
	 *  - default_ref: string
	 *  - operation_id: string
	 *  - user_id: int
	 */
	public function __construct( \Title $title, array $params ) {
		parent::__construct( 'labkiRepoAdd', $title, $params );
	}

	/** Execute the background job. */
	public function run(): bool {
		$url = trim( (string)( $this->params['url'] ?? '' ) );
		$refs = $this->params['refs'] ?? [];
		$defaultRef = trim( (string)( $this->params['default_ref'] ?? '' ) );
		$operationId = $this->params['operation_id'] ?? ('repo_add_' . uniqid());
		$userId = (int)( $this->params['user_id'] ?? 0 );

		wfDebugLog( 'labkipack', "LabkiRepoAddJob::run() started for {$url} (operation_id={$operationId})" );

		// Basic validation
		if ( $url === '' || empty( $refs ) ) {
			wfDebugLog( 'labkipack', "LabkiRepoAddJob: missing URL or refs" );
			return false;
		}

		try {
			$contentManager = new GitContentManager();
			$repoRegistry = new LabkiRepoRegistry();
			$refRegistry = new LabkiRefRegistry();
			$operationRegistry = new LabkiOperationRegistry();
		} catch ( \Throwable $e ) {
			wfDebugLog( 'labkipack', "LabkiRepoAddJob: failed to initialize services: {$e->getMessage()}" );
			return false;
		}

		// Mark operation as started
		$operationRegistry->startOperation( $operationId, 'Starting repository initialization' );

		try {
			// Step 1: Ensure bare repository mirror (0-30% progress)
			wfDebugLog( 'labkipack', "LabkiRepoAddJob: ensuring bare repo for {$url}" );
			$operationRegistry->setProgress( $operationId, 10, 'Cloning bare repository' );
			
			$barePath = $contentManager->ensureBareRepo( $url );
			wfDebugLog( 'labkipack', "LabkiRepoAddJob: bare repo ready at {$barePath}" );
			$operationRegistry->setProgress( $operationId, 30, 'Bare repository ready' );

			// Step 2: Verify repository registration (30-40% progress)
			$repoId = $repoRegistry->getRepoIdByUrl( $url );
			if ( $repoId === null ) {
				throw new \RuntimeException( "Repository not found in DB after ensureBareRepo" );
			}
			$operationRegistry->setProgress( $operationId, 40, 'Repository registered' );

			// Step 3: Initialize worktrees for refs (40-90% progress)
			$totalRefs = count( $refs );
			$successRefs = 0;
			$progressPerRef = $totalRefs > 0 ? 50 / $totalRefs : 0; // 50% of total progress for all refs
			
			foreach ( $refs as $index => $ref ) {
				try {
					wfDebugLog( 'labkipack', "LabkiRepoAddJob: ensuring worktree for {$url}@{$ref}" );
					$currentProgress = 40 + (int)( $progressPerRef * $index );
					$operationRegistry->setProgress(
						$operationId,
						$currentProgress,
						"Initializing ref {$ref} (" . ($index + 1) . "/{$totalRefs})"
					);
					
					$worktreePath = $contentManager->ensureWorktree( $url, $ref );
					$refRegistry->createRef( $repoId, $ref );
					wfDebugLog( 'labkipack', "LabkiRepoAddJob: worktree ready at {$worktreePath}" );
					$successRefs++;
				} catch ( \Throwable $e ) {
					wfDebugLog(
						'labkipack',
						"LabkiRepoAddJob: failed worktree for {$url}@{$ref}: " . $e->getMessage()
					);
				}
			}

			// Step 4: Complete operation (90-100% progress)
			$operationRegistry->setProgress( $operationId, 95, 'Finalizing repository setup' );
			
			$summary = "{$successRefs}/{$totalRefs} refs initialized successfully for {$url}";
			$resultData = json_encode( [
				'url' => $url,
				'repo_id' => $repoId,
				'total_refs' => $totalRefs,
				'successful_refs' => $successRefs,
				'refs' => $refs,
			] );

			if ( $successRefs === $totalRefs ) {
				wfDebugLog( 'labkipack', "LabkiRepoAddJob SUCCESS: {$summary}" );
				$operationRegistry->completeOperation(
					$operationId,
					$summary,
					$resultData
				);
			} elseif ( $successRefs > 0 ) {
				wfDebugLog( 'labkipack', "LabkiRepoAddJob PARTIAL: {$summary}" );
				$operationRegistry->completeOperation(
					$operationId,
					"Partial success: {$summary}",
					$resultData
				);
			} else {
				throw new \RuntimeException( "No refs were initialized successfully" );
			}

			return true;

		} catch ( \Throwable $e ) {
			$errorMessage = "Failed to initialize repository {$url}: {$e->getMessage()}";
			wfDebugLog( 'labkipack', "LabkiRepoAddJob FAILED: {$errorMessage}" );
			
			$operationRegistry->failOperation(
				$operationId,
				$errorMessage,
				json_encode( [
					'url' => $url,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				] )
			);
			
			return false;
		}
	}
}
