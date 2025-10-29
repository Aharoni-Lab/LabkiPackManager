<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Jobs\LabkiPackUpdateJob;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\ContentRefId;

/**
 * API endpoint to update one or more installed content packs.
 *
 * ## Purpose
 * Updates installed packs to newer versions from a content repository ref.
 * Performs validation to ensure version compatibility and dependency consistency.
 *
 * ## Action
 * `labkiPacksUpdate`
 *
 * ## Example Request
 * ```
 * POST api.php?action=labkiPacksUpdate
 * {
 *   "repo_id": 1,
 *   "ref": "main",
 *   "packs": "[{\"name\":\"Pack1\",\"target_version\":\"1.2.0\"},{\"name\":\"Pack2\",\"target_version\":\"2.1.0\"}]"
 * }
 * ```
 *
 * ## Validation Rules
 * 1. All packs must be currently installed
 * 2. Major version cannot change (e.g., 1.x.x → 2.0.0 blocked)
 * 3. Dependencies must be satisfied:
 *    - If pack A depends on pack B, and A is being updated, B must be compatible or also updating
 *    - If pack C depends on pack A, and A is being updated, C must remain compatible or also updating
 *
 * ## Process Flow
 * 1. Validate parameters (repo, ref, packs)
 * 2. Resolve repo and ref identifiers
 * 3. Verify all packs are currently installed
 * 4. Validate version changes (major version must not change)
 * 5. Validate dependency compatibility
 * 6. Generate unique operation ID
 * 7. Queue `LabkiPackUpdateJob` for background update
 * 8. Return immediate queued response
 *
 * ## Response Structure
 * ```json
 * {
 *   "success": true,
 *   "operation_id": "pack_update_abc123",
 *   "status": "queued",
 *   "message": "Pack update queued",
 *   "packs": ["Pack1", "Pack2"],
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000"
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 * - Extends PackApiBase for repo/ref resolution
 * - Uses MediaWiki job queue for async operations
 * - Background job performs pack updates and DB updates
 * - Requires `labkipackmanager-manage` permission
 * - Includes comprehensive version and dependency validation
 *
 * @ingroup API
 */
final class ApiLabkiPacksUpdate extends PackApiBase {

	/** @inheritDoc */
	public function __construct( \ApiMain $main, string $name ) {
		wfDebugLog( 'labkipack', "ApiLabkiPacksUpdate::__construct() called with name={$name}" );
		parent::__construct( $main, $name );
	}

	/** Execute the API request. */
	public function execute(): void {
		$this->requireManagePermission();
		$params = $this->extractRequestParams();

		// Get parameters
		$repoId = $params['repo_id'] ?? null;
		$repoUrl = $params['repo_url'] ?? null;
		$refId = $params['ref_id'] ?? null;
		$ref = $params['ref'] ?? null;
		$packsJson = $params['packs'] ?? '[]';

		wfDebugLog( 'labkipack', "ApiLabkiPacksUpdate::execute() repo_id={$repoId}, repo_url={$repoUrl}, ref_id={$refId}, ref={$ref}" );

		// Validate: only one repo identifier
		if ( $repoId !== null && $repoUrl !== null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-multiple-identifiers',
				'multiple_identifiers'
			);
		}

		// Validate: only one ref identifier
		if ( $refId !== null && $ref !== null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-multiple-identifiers',
				'multiple_identifiers'
			);
		}

		// Validate: repo is required
		if ( $repoId === null && $repoUrl === null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-required',
				'missing_repo'
			);
		}

		// Validate: ref is required
		if ( $refId === null && $ref === null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-required',
				'missing_ref'
			);
		}

		// Parse packs JSON
		$packs = json_decode( $packsJson, true );
		if ( !is_array( $packs ) || empty( $packs ) ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-packs',
				'invalid_packs'
			);
		}

		// Validate each pack has required fields (name and optional target_version)
		foreach ( $packs as $pack ) {
			if ( !isset( $pack['name'] ) || !is_string( $pack['name'] ) ) {
				$this->dieWithError(
					'labkipackmanager-error-invalid-pack-format',
					'invalid_pack_format'
				);
			}
		}

		wfDebugLog( 'labkipack', "ApiLabkiPacksUpdate: received " . count( $packs ) . " pack(s) to update" );

		// Resolve repo identifier
		$identifier = $repoId ?? $repoUrl;
		$resolvedRepoId = $this->resolveRepoId( $identifier );

		if ( $resolvedRepoId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-not-found',
				'repo_not_found'
			);
		}

		// Resolve ref identifier
		$refIdentifier = $refId ?? $ref;
		$resolvedRefId = $this->resolveRefId( $resolvedRepoId, $refIdentifier );

		if ( $resolvedRefId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-not-found',
				'ref_not_found'
			);
		}

		wfDebugLog( 'labkipack', "ApiLabkiPacksUpdate: resolved refId={$resolvedRefId->toInt()}" );

		// Verify ref has worktree
		$refRegistry = $this->getRefRegistry();
		$refObj = $refRegistry->getRefById( $resolvedRefId );

		if ( !$refObj ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-not-found',
				'ref_not_found'
			);
		}

		$worktreePath = $refObj->worktreePath();
		if ( !$worktreePath || !is_dir( $worktreePath ) ) {
			$this->dieWithError(
				'labkipackmanager-error-worktree-not-found',
				'worktree_not_found'
			);
		}

		// Validate pack updates
		$packManager = new \LabkiPackManager\Services\LabkiPackManager();
		$packNames = array_column( $packs, 'name' );
		
		// 1. Verify all packs are installed
		$notInstalledPacks = $packManager->validatePacksInstalled( $resolvedRefId, $packNames );
		if ( !empty( $notInstalledPacks ) ) {
			wfDebugLog( 'labkipack', "Pack update blocked: packs not installed: " . implode( ', ', $notInstalledPacks ) );
			$this->dieWithError(
				[
					'labkipackmanager-error-packs-not-installed',
					implode( ', ', $notInstalledPacks )
				],
				'packs_not_installed'
			);
		}

		// 2. Validate version compatibility (major version must not change)
		$versionErrors = $packManager->validatePackVersions( $resolvedRefId, $packs );
		if ( !empty( $versionErrors ) ) {
			$errorList = [];
			foreach ( $versionErrors as $packName => $error ) {
				$errorList[] = "{$packName}: {$error}";
			}
			wfDebugLog( 'labkipack', "Pack update blocked: version errors: " . implode( '; ', $errorList ) );
			$this->dieWithError(
				[
					'labkipackmanager-error-version-incompatible',
					implode( '; ', $errorList )
				],
				'version_incompatible'
			);
		}

		// 3. Validate dependency compatibility
		$dependencyErrors = $packManager->validatePackUpdateDependencies( $resolvedRefId, $packNames );
		if ( !empty( $dependencyErrors ) ) {
			wfDebugLog( 'labkipack', "Pack update blocked: dependency errors: " . implode( '; ', $dependencyErrors ) );
			$this->dieWithError(
				[
					'labkipackmanager-error-update-dependency-conflict',
					implode( '; ', $dependencyErrors )
				],
				'update_dependency_conflict'
			);
		}

		wfDebugLog( 'labkipack', "Validation passed for " . count( $packNames ) . " pack(s)" );

		// Generate operation ID
		$operationIdStr = 'pack_update_' . substr( md5( $resolvedRefId->toInt() . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );
		$userId = $this->getUser()->getId();

		// Create operation record in database
		$operationRegistry = new LabkiOperationRegistry();
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_PACK_UPDATE,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Pack update queued: ' . implode( ', ', $packNames )
		);

		// Queue background job
		$jobParams = [
			'ref_id' => $resolvedRefId->toInt(),
			'packs' => $packs,
			'operation_id' => $operationIdStr,
			'user_id' => $userId,
		];

		$title = $this->getTitle() ?: Title::newFromText( 'LabkiPackUpdateJob' );
		$job = new LabkiPackUpdateJob( $title, $jobParams );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApiLabkiPacksUpdate: queued job with operation_id={$operationIdStr}" );

		// Build response
		$result = $this->getResult();
		$result->addValue( null, 'success', true );
		$result->addValue( null, 'operation_id', $operationIdStr );
		$result->addValue( null, 'status', LabkiOperationRegistry::STATUS_QUEUED );
		$result->addValue( null, 'message', 'Pack update queued' );
		$result->addValue( null, 'packs', $packNames );
		$result->addValue( null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'repo_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-update-param-repoid',
			],
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-update-param-repourl',
			],
			'ref_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-update-param-refid',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-update-param-ref',
			],
			'packs' => [
				ParamValidator::PARAM_TYPE => 'text', // JSON-encoded array
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-update-param-packs',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiPacksUpdate&repo_id=1&ref=main&packs=[{"name":"Pack1","target_version":"1.2.0"}]'
				=> 'apihelp-labkipacksupdate-example-basic',
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

