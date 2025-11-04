<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Repos;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Jobs\LabkiRepoAddJob;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWiki\Title\Title;
/**
 * API endpoint to add a new content repository and queue background fetch/setup.
 *
 * ## Purpose
 * Adds a new Git-based content repository to the Labki registry, validates the URL,
 * and schedules background setup operations (bare clone + worktrees for refs).
 *
 * ## Action
 * `labkiReposAdd`
 *
 * ## Example Request
 * ```
 * POST api.php?action=labkiReposAdd
 * {
 *   "url": "https://github.com/Aharoni-Lab/labki-packs",
 *   "refs": ["main", "v1.0.0"],
 *   "default_ref": "main"
 * }
 * ```
 *
 * ## Process Flow
 * 1. Validate URL format
 * 2. Check if repository already exists
 * 3. Verify repository accessibility
 * 4. Generate unique operation ID
 * 5. Queue `LabkiRepoAddJob` for background setup
 * 6. Return immediate queued response
 *
 * ## Response Structure
 * ```json
 * {
 *   "success": true,
 *   "operation_id": "repo_add_abc123",
 *   "status": "queued",
 *   "message": "Repository validation started",
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000"
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 * - Extends RepoApiBase for validation and permission handling
 * - Uses MediaWiki job queue for async operations
 * - Background job performs Git operations and DB updates
 *
 * @ingroup API
 */
final class ApiLabkiReposAdd extends RepoApiBase {

	/** @inheritDoc */
	public function __construct( \ApiMain $main, string $name ) {
		parent::__construct( $main, $name );
	}

	/** Execute the API request. */
	public function execute(): void {
        // Require manage permission
		$this->requireManagePermission();
        // Extract parameters
		$params = $this->extractRequestParams();

        // Trim, validate, normalize, and verify accessibility of URL
		$repoUrl = $this->resolveRepoUrl( $params['repo_url'] );
		if ( !$this->verifyGitUrlAccessible( $repoUrl ) ) {
			$this->dieWithError( 'labkipackmanager-error-unreachable-repo', 'unreachable_repo' );
		}

	// Handle refs and defaultRef parameters with fallback
	$refs = $params['refs'] ?? null;
	$defaultRef = $params['default_ref'] ?? null;
	
	if ( $refs === null ) {
		// If refs not set, use defaultRef if present, otherwise 'main'
		$defaultRef = $defaultRef ?? 'main';
		$refs = [ $defaultRef ];
	} else {
		// If defaultRef is missing, use first item from $refs.
		$defaultRef = $defaultRef ?? $refs[0];
		// Ensure defaultRef is in refs
		if ( !in_array( $defaultRef, $refs ) ) {
			$refs[] = $defaultRef;
		}
	}

		wfDebugLog( 'labkipack', "ApiLabkiReposAdd::execute() repoUrl={$repoUrl}, default_ref={$defaultRef}" );

		// Check if repo exists and if any refs are missing
		$repoRegistry = new LabkiRepoRegistry();
		$needsWork = false;
		
		// Determine if repo exists and if any refs are missing
		if ( $repoRegistry->getRepo( $repoUrl ) === null ) {
			// Repo doesn't exist - needs full initialization
			$needsWork = true;
		} else {
			// Repo exists - check if any refs are missing
			$refRegistry = new LabkiRefRegistry();
			$repoId = $repoRegistry->getRepoId( $repoUrl );
			
			foreach ( $refs as $ref ) {
				$existingRefId = $refRegistry->getRefIdByRepoAndRef( $repoId, $ref );
				if ( $existingRefId === null ) {
					$needsWork = true;
					break; // No need to check other refs if one is missing
				}
			}
		}
		
		// If no work needed, return success immediately
		if ( !$needsWork ) {
            wfDebugLog( 'labkipack', "ApiLabkiReposAdd: all refs already exist, nothing to do" );
			$result = $this->getResult();
			$result->addValue( null, 'success', true );
			$result->addValue( null, 'message', 'Repository and all specified refs already exist' );
			$result->addValue( null, 'refs', $refs );
			$result->addValue( null, 'meta', [
				'schemaVersion' => 1,
				'timestamp' => wfTimestampNow(),
			] );
			return;
		}

        // If work is needed, queue the job
		$operationIdStr = 'repo_add_' . substr( md5( $repoUrl . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );
		$userId = $this->getUser()->getId();

		// Create operation record in database
		$operationRegistry = new LabkiOperationRegistry();
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_REPO_ADD,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Repository queued for initialization'
		);

		// Queue background job
		$jobParams = [
			'repo_url' => $repoUrl,
			'refs' => $refs,
			'default_ref' => $defaultRef,
			'operation_id' => $operationIdStr,
			'user_id' => $userId,
		];
        $title = $this->getTitle() ?: Title::newFromText( 'LabkiRepoJob' );
		$job = new LabkiRepoAddJob( $title, $jobParams );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApiLabkiReposAdd: queued job with operation_id={$operationIdStr}" );

		// Build response
		$result = $this->getResult();
		$result->addValue( null, 'success', true );
		$result->addValue( null, 'operation_id', $operationIdStr );
		$result->addValue( null, 'status', LabkiOperationRegistry::STATUS_QUEUED );
		$result->addValue( null, 'message', 'Repository queued for initialization' );
		$result->addValue( null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		] );
	}

	/**
	 * Simple accessibility check for Git repository URL.
	 * Returns true if reachable via HTTP(S) HEAD or ssh check.
	 */
	private function verifyGitUrlAccessible( string $url ): bool {
        // Skip external reachability checks in test or maintenance environments
        if ( defined( 'MW_PHPUNIT_TEST' ) || PHP_SAPI === 'cli' ) {
            return true;
        }

		if ( preg_match( '/^https?:\/\//i', $url ) ) {
			$ch = curl_init( $url );
			curl_setopt( $ch, CURLOPT_NOBODY, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_exec( $ch );
			$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			return $code >= 200 && $code < 400;
		}
		// Skip network check for SSH/scp URLs
		return true;
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-add-param-url',
			],
			'refs' => [
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-add-param-refs',
			],
			'default_ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-add-param-defaultref',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiReposAdd'
				=> 'apihelp-labkireposadd-example-basic',
		];
	}

	/** POST required. */
	public function mustBePosted(): bool {
		return true;
	}

	/** Requires write mode. */
	public function isWriteMode(): bool {
		return true;
	}

	/** Internal API (not exposed publicly). */
	public function isInternal(): bool {
		return true;
	}
}
