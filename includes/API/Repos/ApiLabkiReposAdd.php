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
		wfDebugLog( 'labkipack', "ApiLabkiReposAdd::__construct() called with name={$name}" );
		parent::__construct( $main, $name );
	}

	/** Execute the API request. */
	public function execute(): void {
		$this->requireManagePermission();
		$params = $this->extractRequestParams();

		$url = trim( (string)( $params['url'] ?? '' ) );

		// Handle refs and defaultRef parameters with various fallbacks
		$refs = $params['refs'] ?? null;
		$defaultRef = isset( $params['default_ref'] ) ? trim( (string)$params['default_ref'] ) : null;

		if ( $refs === null && $defaultRef === null ) {
			// Neither provided - use 'main' for both
			$refs = ['main'];
			$defaultRef = 'main';
		} elseif ( $refs === null && $defaultRef !== null ) {
			// Only defaultRef provided - use it for refs
			$refs = [$defaultRef];
		} elseif ( $refs !== null && $defaultRef === null ) {
			// Only refs provided - use first ref as default
			$defaultRef = $refs[0];
		} else {
			// Both provided - ensure defaultRef is in refs
            // Might want some other sort of operation here to handle 
            // the case where the default ref is not in the refs array
			if ( !in_array( $defaultRef, $refs ) ) {
				$refs[] = $defaultRef;
			}
		}

		wfDebugLog( 'labkipack', "ApiLabkiReposAdd::execute() url={$url}, default_ref={$defaultRef}" );

		// Validate URL
		$normalizedUrl = $this->validateAndNormalizeUrl( $url );

		// Validate refs
		if ( !is_array( $refs ) || empty( $refs ) ) {
			$this->dieWithError( 'labkipackmanager-error-missing-refs', 'missing_refs' );
		}

		// Verify accessibility (basic reachability check)
		if ( !$this->verifyGitUrlAccessible( $normalizedUrl ) ) {
			$this->dieWithError( 'labkipackmanager-error-unreachable-repo', 'unreachable_repo' );
		}

		// Check if repo exists and if any refs are missing
		$repoRegistry = $this->getRepoRegistry();
		$existingRepo = $repoRegistry->getRepo( $normalizedUrl );
		$needsWork = false;
		
		if ( $existingRepo === null ) {
			// Repo doesn't exist - needs full initialization
			$needsWork = true;
			wfDebugLog( 'labkipack', "ApiLabkiReposAdd: repo does not exist, needs initialization" );
		} else {
			// Repo exists - check if any refs are missing
			$refRegistry = new LabkiRefRegistry();
			$repoId = $repoRegistry->getRepoIdByUrl( $normalizedUrl );
			
			foreach ( $refs as $ref ) {
				$existingRefId = $refRegistry->getRefIdByRepoAndRef( $repoId, $ref );
				if ( $existingRefId === null ) {
					$needsWork = true;
					wfDebugLog( 'labkipack', "ApiLabkiReposAdd: ref {$ref} is missing, needs work" );
					break;
				}
			}
			
			if ( !$needsWork ) {
				wfDebugLog( 'labkipack', "ApiLabkiReposAdd: all refs already exist, nothing to do" );
			}
		}
		
		// If no work needed, return success immediately
		if ( !$needsWork ) {
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

		// Generate operation ID
		$operationIdStr = 'repo_add_' . substr( md5( $normalizedUrl . microtime() ), 0, 8 );
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
			'url' => $normalizedUrl,
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
			'url' => [
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
