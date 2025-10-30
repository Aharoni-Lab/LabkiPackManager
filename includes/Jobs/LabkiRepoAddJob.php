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
 * LabkiRepoAddJob
 *
 * Background job to initialize or update a Labki content repository.
 * This job is queued by ApiLabkiReposAdd to perform the following tasks asynchronously:
 *
 * ## For New Repositories:
 * 1. Clone bare Git repository mirror
 * 2. Register the repository in the database
 * 3. Create worktrees for each specified ref
 * 4. Register each ref in the database
 *
 * ## For Existing Repositories:
 * 1. Update bare repository with git fetch
 * 2. Create worktrees for any new refs
 * 3. Register new refs in the database
 * 4. Update existing ref entries if needed
 *
 * Designed to mirror InitializeContentRepos.php but without console output.
 *
 * @ingroup Jobs
 */
final class LabkiRepoAddJob extends Job {

	/**
	 * @param Title $title Title context (unused but required)
	 * @param array $params Job parameters:
	 *  - url: string (repository URL)
	 *  - refs: string[] (refs to initialize)
	 *  - default_ref: string
	 *  - operation_id: string
	 *  - user_id: int
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'labkiRepoAdd', $title, $params );
	}

	/** Execute the background job. */
	public function run(): bool {
		$url = trim( (string)( $this->params['url'] ?? '' ) );
		$refs = $this->params['refs'] ?? [];
		$defaultRef = trim( (string)( $this->params['default_ref'] ?? '' ) );
		$operationIdStr = $this->params['operation_id'] ?? ('repo_add_' . uniqid());
		$operationId = new OperationId( $operationIdStr );
		$userId = (int)( $this->params['user_id'] ?? 0 );

		wfDebugLog( 'labkipack', "LabkiRepoAddJob::run() started for {$url} (operation_id={$operationIdStr})" );

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

		// Check if repository already exists
		$repoId = $repoRegistry->getRepoId( $url );
		$isExistingRepo = $repoId !== null;
		
		if ( $isExistingRepo ) {
			wfDebugLog( 'labkipack', "LabkiRepoAddJob: repo exists (ID={$repoId}), will update and add new refs" );
		} else {
			wfDebugLog( 'labkipack', "LabkiRepoAddJob: repo does not exist, will create new" );
		}

		// Mark operation as started
		$message = $isExistingRepo ? 'Updating repository and adding refs' : 'Starting repository initialization';
		$operationRegistry->startOperation( $operationId, $message );

		try {
			// Step 1: Ensure bare repository mirror (0-30% progress)
			// This will clone if new, or fetch if existing
			wfDebugLog( 'labkipack', "LabkiRepoAddJob: ensuring bare repo for {$url}" );
			$progressMessage = $isExistingRepo ? 'Updating bare repository' : 'Cloning bare repository';
			$operationRegistry->setProgress( $operationId, 10, $progressMessage );
			
			$barePath = $contentManager->ensureBareRepo( $url );
			wfDebugLog( 'labkipack', "LabkiRepoAddJob: bare repo ready at {$barePath}" );
			$operationRegistry->setProgress( $operationId, 30, 'Bare repository ready' );

			// Step 2: Verify repository registration (30-40% progress)
			$repoId = $repoRegistry->getRepoId( $url );
			if ( $repoId === null ) {
				throw new \RuntimeException( "Repository not found in DB after ensureBareRepo" );
			}
			$operationRegistry->setProgress( $operationId, 40, 'Repository registered' );

			// Step 3: Initialize worktrees for refs (40-90% progress)
			$totalRefs = count( $refs );
			$successRefs = 0;
			$newRefs = 0;
			$existingRefs = 0;
			$progressPerRef = $totalRefs > 0 ? 50 / $totalRefs : 0; // 50% of total progress for all refs
			
			foreach ( $refs as $index => $ref ) {
				try {
					// Check if ref already exists
					$existingRefId = $refRegistry->getRefIdByRepoAndRef( $repoId, $ref );
					$isNewRef = $existingRefId === null;
					
					if ( $isNewRef ) {
						wfDebugLog( 'labkipack', "LabkiRepoAddJob: creating new ref {$url}@{$ref}" );
						$newRefs++;
					} else {
						wfDebugLog( 'labkipack', "LabkiRepoAddJob: updating existing ref {$url}@{$ref}" );
						$existingRefs++;
					}
					
					$currentProgress = 40 + (int)( $progressPerRef * $index );
					$operationRegistry->setProgress(
						$operationId,
						$currentProgress,
						"Processing ref {$ref} (" . ($index + 1) . "/{$totalRefs})"
					);
					
					$worktreePath = $contentManager->ensureWorktree( $url, $ref );
					$refRegistry->ensureRefEntry( $repoId, $ref );
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
			
			$summary = "{$successRefs}/{$totalRefs} refs processed successfully for {$url}";
			if ( $newRefs > 0 && $existingRefs > 0 ) {
				$summary .= " ({$newRefs} new, {$existingRefs} updated)";
			} elseif ( $newRefs > 0 ) {
				$summary .= " ({$newRefs} new)";
			} elseif ( $existingRefs > 0 ) {
				$summary .= " ({$existingRefs} updated)";
			}
			
			$resultData = json_encode( [
				'url' => $url,
				'repo_id' => $repoId,
				'is_existing_repo' => $isExistingRepo,
				'total_refs' => $totalRefs,
				'successful_refs' => $successRefs,
				'new_refs' => $newRefs,
				'updated_refs' => $existingRefs,
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
