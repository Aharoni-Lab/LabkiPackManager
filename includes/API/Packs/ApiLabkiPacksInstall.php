<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Jobs\LabkiPackInstallJob;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWiki\Title\Title;

/**
 * API endpoint to install one or more content packs.
 *
 * ## Purpose
 * Installs packs from a content repository ref, creating MediaWiki pages
 * and registering pack/page metadata in the database.
 *
 * ## Action
 * `labkiPacksInstall`
 *
 * ## Example Request
 * ```
 * POST api.php?action=labkiPacksInstall
 * {
 *   "repo_id": 1,
 *   "ref": "main",
 *   "packs": [
 *     {
 *       "name": "MyPack",
 *       "version": "1.0.0",
 *       "pages": [
 *         {
 *           "name": "Page1",
 *           "original": "Page1",
 *           "finalTitle": "MyPack/Page1"
 *         }
 *       ]
 *     }
 *   ]
 * }
 * ```
 *
 * ## Process Flow
 * 1. Validate parameters (repo, ref, packs)
 * 2. Resolve repo and ref identifiers
 * 3. Verify ref has worktree
 * 4. Generate unique operation ID
 * 5. Queue `LabkiPackInstallJob` for background installation
 * 6. Return immediate queued response
 *
 * ## Response Structure
 * ```json
 * {
 *   "success": true,
 *   "operation_id": "pack_install_abc123",
 *   "status": "queued",
 *   "message": "Pack installation queued",
 *   "packs": ["MyPack"],
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
 * - Background job performs pack installation and DB updates
 * - Requires `labkipackmanager-manage` permission
 *
 * @ingroup API
 */
final class ApiLabkiPacksInstall extends PackApiBase {

	/** @inheritDoc */
	public function __construct( \ApiMain $main, string $name ) {
		wfDebugLog( 'labkipack', "ApiLabkiPacksInstall::__construct() called with name={$name}" );
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

		wfDebugLog( 'labkipack', "ApiLabkiPacksInstall::execute() repo_id={$repoId}, repo_url={$repoUrl}, ref_id={$refId}, ref={$ref}" );

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

		// Validate each pack has required fields
		foreach ( $packs as $pack ) {
			if ( !isset( $pack['name'] ) || !is_array( $pack['pages'] ?? null ) ) {
				$this->dieWithError(
					'labkipackmanager-error-invalid-pack-format',
					'invalid_pack_format'
				);
			}
		}

		wfDebugLog( 'labkipack', "ApiLabkiPacksInstall: received " . count( $packs ) . " pack(s) to install" );

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

		wfDebugLog( 'labkipack', "ApiLabkiPacksInstall: resolved refId={$resolvedRefId->toInt()}" );

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

		// Validate pack dependencies
		$packManager = new \LabkiPackManager\Services\LabkiPackManager();
		$packNames = array_column( $packs, 'name' );
		$missingDeps = $packManager->validatePackDependencies( $resolvedRefId, $packNames );

		if ( !empty( $missingDeps ) ) {
			wfDebugLog( 'labkipack', "Pack installation blocked: missing dependencies: " . implode( ', ', $missingDeps ) );
			$this->dieWithError(
				[
					'labkipackmanager-error-missing-pack-dependencies',
					implode( ', ', $missingDeps )
				],
				'missing_pack_dependencies'
			);
		}

		wfDebugLog( 'labkipack', "Dependency validation passed for " . count( $packNames ) . " pack(s)" );

		// Generate operation ID
		$operationIdStr = 'pack_install_' . substr( md5( $resolvedRefId->toInt() . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );
		$userId = $this->getUser()->getId();

		// Create operation record in database
		$operationRegistry = new LabkiOperationRegistry();
		$packNames = array_column( $packs, 'name' );
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_PACK_INSTALL,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Pack installation queued: ' . implode( ', ', $packNames )
		);

		// Queue background job
		$jobParams = [
			'ref_id' => $resolvedRefId->toInt(),
			'packs' => $packs,
			'operation_id' => $operationIdStr,
			'user_id' => $userId,
		];

		$title = $this->getTitle() ?: Title::newFromText( 'LabkiPackInstallJob' );
		$job = new LabkiPackInstallJob( $title, $jobParams );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApiLabkiPacksInstall: queued job with operation_id={$operationIdStr}" );

		// Build response
		$result = $this->getResult();
		$result->addValue( null, 'success', true );
		$result->addValue( null, 'operation_id', $operationIdStr );
		$result->addValue( null, 'status', LabkiOperationRegistry::STATUS_QUEUED );
		$result->addValue( null, 'message', 'Pack installation queued' );
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
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-install-param-repoid',
			],
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-install-param-repourl',
			],
			'ref_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-install-param-refid',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-install-param-ref',
			],
			'packs' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-install-param-packs',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiPacksInstall&repo_id=1&ref=main&packs=[...]'
				=> 'apihelp-labkipacksinstall-example-basic',
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

	//TODO: Consider requiring CSRF token for write operations.
	// /**
	//  * Requires CSRF token for write operations.
	//  *
	//  * @return bool True
	//  */
	// public function needsToken(): string {
	// 	return 'csrf';
	// }
}
