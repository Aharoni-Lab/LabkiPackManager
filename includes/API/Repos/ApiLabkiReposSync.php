<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Repos;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Jobs\LabkiRepoSyncJob;
use MediaWiki\Title\Title;
/**
 * ApiLabkiReposSync
 *
 * API endpoint to sync/fetch updates from a content repository.
 *
 * ## Purpose
 * - Fetch latest updates from remote repository
 * - Update all refs in a repository or specific refs
 * - Ensure local worktrees match remote state
 *
 * ## Action
 * `labkiReposSync`
 *
 * ## Behavior
 * - **No refs specified**: Syncs entire repository and all refs
 * - **Refs specified**: Syncs only the specified refs
 * - Bare repository is always fetched regardless of which refs are synced
 * - Operation runs asynchronously via background job
 *
 * ## Example Requests
 *
 * Sync entire repository (all refs):
 * ```
 * POST api.php?action=labkiReposSync&url=https://github.com/Aharoni-Lab/labki-packs
 * ```
 *
 * Sync specific refs:
 * ```
 * POST api.php?action=labkiReposSync
 *   &url=https://github.com/Aharoni-Lab/labki-packs
 *   &refs=main|v2.0.0
 * ```
 *
 * ## Response Structure
 * ```json
 * {
 *   "success": true,
 *   "operation_id": "repo_sync_abc123",
 *   "status": "queued",
 *   "message": "Repository sync queued",
 *   "refs": ["main", "v2.0.0"],
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024140000"
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 * - Executes asynchronously via LabkiRepoSyncJob
 * - Use ApiLabkiOperationsStatus to check sync progress
 * - Bare repository is always updated
 *
 * @ingroup API
 */
final class ApiLabkiReposSync extends RepoApiBase {

	public function __construct( \ApiMain $main, string $name ) {
		wfDebugLog( 'labkipack', "ApiLabkiReposSync::__construct() called with name={$name}" );
		parent::__construct( $main, $name );
	}

	/** Execute the API request. */
	public function execute(): void {
		$this->requireManagePermission();
		$params = $this->extractRequestParams();

		$url = trim( (string)( $params['url'] ?? '' ) );
		$refs = $params['refs'] ?? null;

		wfDebugLog( 'labkipack', "ApiLabkiReposSync::execute() url={$url}, refs=" . json_encode( $refs ) );

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

		// Validate refs if provided
		if ( $refs !== null && !is_array( $refs ) ) {
			$this->dieWithError( [ 'apierror-badvalue', 'refs' ], 'invalid_refs' );
		}

		if ( $refs !== null && empty( $refs ) ) {
			$this->dieWithError( [ 'apierror-missingparam', 'refs' ], 'empty_refs' );
		}

		// Create operation record
		$operationIdStr = 'repo_sync_' . substr( md5( $normalizedUrl . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );
		$userId = $this->getUser()->getId();

		$operationRegistry = new LabkiOperationRegistry();
		$message = $refs !== null 
			? count( $refs ) . ' ref(s) queued for sync'
			: 'Repository queued for sync';
		
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_REPO_SYNC,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			$message
		);

		// Queue the sync job
		$jobParams = [
			'url' => $normalizedUrl,
			'refs' => $refs,
			'operation_id' => $operationIdStr,
			'user_id' => $userId,
		];

		$title = $this->getTitle() ?: Title::newFromText( 'LabkiRepoJob' );
		$job = new LabkiRepoSyncJob( $title, $jobParams );
		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApiLabkiReposSync: queued sync job with operation_id={$operationIdStr}" );

		// Return response
		$result = $this->getResult();
		$result->addValue( null, 'success', true );
		$result->addValue( null, 'operation_id', $operationIdStr );
		$result->addValue( null, 'status', LabkiOperationRegistry::STATUS_QUEUED );
		$result->addValue( null, 'message', $refs !== null
			? 'Repository sync queued for ' . count( $refs ) . ' ref(s)'
			: 'Repository sync queued for all refs'
		);
		
		if ( $refs !== null ) {
			$result->addValue( null, 'refs', $refs );
		}
		
		$result->addValue( null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-sync-param-url',
			],
			'refs' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-sync-param-refs',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiReposSync&url=https://github.com/example/repo'
				=> 'apihelp-labkireposync-example-sync-repo',
			'action=labkiReposSync&url=https://github.com/example/repo&refs=main|v2.0.0'
				=> 'apihelp-labkireposync-example-sync-refs',
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
