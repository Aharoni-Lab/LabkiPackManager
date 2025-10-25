<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Repos;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Jobs\LabkiRepoRemoveJob;
use MediaWiki\Title\Title;
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
 *   "status": "queued",
 *   "message": "Repository removal queued",
 *   "refs": ["v1.0.0", "v2.0.0"],
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024140000"
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 * - Executes asynchronously via LabkiRepoRemoveJob
 * - Use ApiLabkiOperationsStatus to check removal progress
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

		// Validate refs if provided
		if ( $refs !== null && !is_array( $refs ) ) {
			$this->dieWithError( [ 'apierror-badvalue', 'refs' ], 'invalid_refs' );
		}

		if ( $refs !== null && empty( $refs ) ) {
			$this->dieWithError( [ 'apierror-missingparam', 'refs' ], 'empty_refs' );
		}

		// Create operation record
		$operationIdStr = 'repo_remove_' . substr( md5( $normalizedUrl . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );
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

		// Queue the removal job
		$jobParams = [
			'url' => $normalizedUrl,
			'refs' => $refs,
			'operation_id' => $operationIdStr,
			'user_id' => $userId,
		];

		$title = $this->getTitle() ?: Title::newFromText( 'LabkiRepoJob' );
		$job = new LabkiRepoRemoveJob( $title, $jobParams );
		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApiLabkiReposRemove: queued removal job with operation_id={$operationIdStr}" );

		// Return response
		$result = $this->getResult();
		$result->addValue( null, 'success', true );
		$result->addValue( null, 'operation_id', $operationIdStr );
		$result->addValue( null, 'status', LabkiOperationRegistry::STATUS_QUEUED );
		$result->addValue( null, 'message', $refs !== null
			? 'Repository removal queued for ' . count( $refs ) . ' ref(s)'
			: 'Repository removal queued'
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
