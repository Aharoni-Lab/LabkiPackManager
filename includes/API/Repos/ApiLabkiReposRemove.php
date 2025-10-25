<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Repos;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\GitContentManager;
use LabkiPackManager\Services\LabkiOperationRegistry;

/**
 * ApiLabkiReposRemove
 *
 * API endpoint to remove a content repository or specific refs from a repository.
 *
 * ## Purpose
 * - Remove entire repository and all associated refs, packs, and pages
 * - Remove specific refs from a repository while keeping the repository itself
 * - Supports batch removal of multiple refs
 *
 * ## Action
 * `labkiReposRemove`
 *
 * ## Behavior
 * - **No refs specified**: Removes entire repository and all associated data
 * - **Refs specified**: Removes only the specified refs, repository remains
 * - Connected packs and pages are also removed (TODO: implement cleanup)
 *
 * ## Example Requests
 *
 * Remove entire repository:
 * ```
 * POST api.php?action=labkiReposRemove&url=https://github.com/Aharoni-Lab/labki-packs
 * ```
 *
 * Remove specific refs:
 * ```
 * POST api.php?action=labkiReposRemove
 *   &url=https://github.com/Aharoni-Lab/labki-packs
 *   &refs=v1.0.0|v2.0.0
 * ```
 *
 * ## Response Structure
 * ```json
 * {
 *   "success": true,
 *   "operation_id": "repo_remove_abc123",
 *   "status": "success",
 *   "message": "Repository successfully removed",
 *   "removed_refs": 3,
 *   "failed_refs": [],
 *   "_meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024140000"
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 * - Currently executes synchronously
 * - Future version may queue background job for large repositories
 * - Uses operation tracking for consistency
 *
 * @ingroup API
 */
final class ApiLabkiReposRemove extends RepoApiBase {

	public function __construct( \ApiMain $main, string $name ) {
		wfDebugLog( 'labkipack', "ApiLabkiReposRemove::__construct() called with name={$name}" );
		parent::__construct( $main, $name );
	}

	/** Execute the API request. */
	public function execute(): void {
		$this->requireManagePermission();
		$params = $this->extractRequestParams();

		$url = trim( (string)( $params['url'] ?? '' ) );
		$refs = $params['refs'] ?? null;

		wfDebugLog( 'labkipack', "ApiLabkiReposRemove::execute() url={$url}, refs=" . json_encode( $refs ) );

		if ( $url === '' ) {
			$this->dieWithError( [ 'apierror-missingparam', 'url' ], 'missing_url' );
		}

		// Normalize and validate
		$normalizedUrl = $this->validateAndNormalizeUrl( $url );

		// Verify repository exists
		$repoRegistry = $this->getRepoRegistry();
		$repo = $repoRegistry->getRepo( $normalizedUrl );
		if ( $repo === null ) {
			$this->dieWithError( 'labkipackmanager-error-repo-not-found', 'repo_not_found' );
		}

		// Initialize GitContentManager for removal operations
		$contentManager = new GitContentManager();

		// Validate refs if provided
		if ( $refs !== null && !is_array( $refs ) ) {
			$this->dieWithError( [ 'apierror-badvalue', 'refs' ], 'invalid_refs' );
		}

		if ( $refs !== null && empty( $refs ) ) {
			$this->dieWithError( [ 'apierror-missingparam', 'refs' ], 'empty_refs' );
		}

		// Create operation record
		$operationId = 'repo_remove_' . substr( md5( $normalizedUrl . microtime() ), 0, 8 );
		$userId = $this->getUser()->getId();

		$operationRegistry = new LabkiOperationRegistry();
		$message = $refs !== null 
			? count( $refs ) . ' ref(s) queued for removal'
			: 'Repository queued for removal';
		
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_REPO_REMOVE,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			$message
		);

		// Mark operation as started
		$operationRegistry->startOperation( $operationId, 'Starting removal process' );

		// For now, we handle removal synchronously.
		// Future version may queue a background job (LabkiRepoRemoveJob).

		try {
			$removedRefs = [];
			$failedRefs = [];

			if ( $refs !== null ) {
				// Remove specific refs/worktrees
				wfDebugLog( 'labkipack', "ApiLabkiReposRemove: removing " . count( $refs ) . " ref(s)" );
				
				foreach ( $refs as $ref ) {
					try {
						$contentManager->removeRef( $normalizedUrl, $ref );
						$removedRefs[] = $ref;
						wfDebugLog( 'labkipack', "ApiLabkiReposRemove: successfully removed ref {$ref}" );
					} catch ( \Throwable $e ) {
						$failedRefs[] = [
							'ref' => $ref,
							'error' => $e->getMessage()
						];
						wfDebugLog( 'labkipack', "ApiLabkiReposRemove: failed to remove ref {$ref}: " . $e->getMessage() );
					}
				}

				$summary = count( $removedRefs ) . '/' . count( $refs ) . ' refs removed successfully';
				if ( !empty( $failedRefs ) ) {
					$summary .= ' (' . count( $failedRefs ) . ' failed)';
				}

				// Update operation based on results
				if ( count( $removedRefs ) === count( $refs ) ) {
					$operationRegistry->completeOperation( $operationId, $summary );
				} elseif ( count( $removedRefs ) > 0 ) {
					$operationRegistry->completeOperation( $operationId, "Partial success: {$summary}" );
				} else {
					throw new \RuntimeException( "No refs were removed successfully" );
				}

			} else {
				// Remove entire repo and all associated refs
				wfDebugLog( 'labkipack', "ApiLabkiReposRemove: removing entire repo" );
				$totalRefs = $contentManager->removeRepo( $normalizedUrl );
				$removedRefs = $totalRefs;
				
				$operationRegistry->completeOperation(
					$operationId,
					"Repository successfully removed ({$totalRefs} refs)"
				);
			}

			$result = $this->getResult();
			$result->addValue( null, 'success', true );
			$result->addValue( null, 'operation_id', $operationId );
			$result->addValue( null, 'status', LabkiOperationRegistry::STATUS_SUCCESS );
			$result->addValue( null, 'message', $refs !== null
				? count( $removedRefs ) . '/' . count( $refs ) . ' ref(s) removed successfully'
				: "Repository successfully removed"
			);
			$result->addValue( null, 'removed_refs', $removedRefs );
			
			if ( !empty( $failedRefs ) ) {
				$result->addValue( null, 'failed_refs', $failedRefs );
			}
			
			$result->addValue( null, '_meta', [
				'schemaVersion' => 1,
				'timestamp' => wfTimestampNow(),
			] );

		} catch ( \Throwable $e ) {
			wfDebugLog( 'labkipack', "ApiLabkiReposRemove: exception during removal - " . $e->getMessage() );

			$operationRegistry->failOperation(
				$operationId,
				"Removal failed: " . $e->getMessage()
			);

			$this->dieWithError( 'labkipackmanager-error-repo-removal-failed', 'repo_removal_failed' );
		}
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-remove-param-url',
			],
			'refs' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-remove-param-refs',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiReposRemove&url=https://github.com/example/repo'
				=> 'apihelp-labkireposremove-example-remove-repo',
			'action=labkiReposRemove&url=https://github.com/example/repo&refs=v1.0.0|v2.0.0'
				=> 'apihelp-labkireposremove-example-remove-refs',
		];
	}

	public function mustBePosted(): bool {
		return true;
	}

	public function isWriteMode(): bool {
		return true;
	}

	public function isInternal(): bool {
		return true;
	}
}
