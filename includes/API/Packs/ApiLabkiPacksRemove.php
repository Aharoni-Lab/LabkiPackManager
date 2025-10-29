<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Jobs\LabkiPackRemoveJob;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWiki\Title\Title;

/**
 * API endpoint to remove/uninstall one or more content packs.
 *
 * ## Purpose
 * Removes packs from the system, optionally deleting their MediaWiki pages
 * and cleaning up pack/page metadata from the database.
 *
 * ## Action
 * `labkiPacksRemove`
 *
 * ## Example Request
 * ```
 * POST api.php?action=labkiPacksRemove
 * {
 *   "repo_id": 1,
 *   "ref": "main",
 *   "pack_ids": [1, 2, 3],
 *   "delete_pages": true
 * }
 * ```
 *
 * ## Process Flow
 * 1. Validate parameters (repo, ref, pack_ids)
 * 2. Resolve repo and ref identifiers
 * 3. Validate packs exist and belong to the specified ref
 * 4. Check for dependent packs (can't remove if other packs depend on it)
 * 5. Generate unique operation ID
 * 6. Queue `LabkiPackRemoveJob` for background removal
 * 7. Return immediate queued response
 *
 * ## Response Structure
 * ```json
 * {
 *   "success": true,
 *   "operation_id": "pack_remove_abc123",
 *   "status": "queued",
 *   "message": "Pack removal queued",
 *   "packs": ["Pack1", "Pack2"],
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000"
 *   }
 * }
 * ```
 *
 * ## Dependency Validation
 * Before removal, checks if any installed packs depend on the packs being removed.
 * If dependencies exist and are not also being removed, the operation fails.
 *
 * ## Implementation Notes
 * - Extends PackApiBase for repo/ref resolution
 * - Uses MediaWiki job queue for async operations
 * - Background job performs pack removal and DB updates
 * - Requires `labkipackmanager-manage` permission
 *
 * @ingroup API
 */
final class ApiLabkiPacksRemove extends PackApiBase {

	/** @inheritDoc */
	public function __construct( \ApiMain $main, string $name ) {
		wfDebugLog( 'labkipack', "ApiLabkiPacksRemove::__construct() called with name={$name}" );
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
		$packIdsJson = $params['pack_ids'] ?? '[]';
		$deletePages = $params['delete_pages'] ?? false;

		wfDebugLog( 'labkipack', "ApiLabkiPacksRemove::execute() repo_id={$repoId}, repo_url={$repoUrl}, ref_id={$refId}, ref={$ref}, delete_pages={$deletePages}" );

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

		// Parse pack_ids JSON
		$packIds = json_decode( $packIdsJson, true );
		if ( !is_array( $packIds ) || empty( $packIds ) ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-pack-ids',
				'invalid_pack_ids'
			);
		}

		// Validate each pack ID is an integer
		foreach ( $packIds as $packIdValue ) {
			if ( !is_int( $packIdValue ) || $packIdValue <= 0 ) {
				$this->dieWithError(
					'labkipackmanager-error-invalid-pack-id-format',
					'invalid_pack_id_format'
				);
			}
		}

		wfDebugLog( 'labkipack', "ApiLabkiPacksRemove: received " . count( $packIds ) . " pack(s) to remove" );

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

		wfDebugLog( 'labkipack', "ApiLabkiPacksRemove: resolved refId={$resolvedRefId->toInt()}" );

		// Verify all packs exist and belong to this ref
		$packRegistry = $this->getPackRegistry();
		$packNames = [];
		$packIdObjects = [];

		foreach ( $packIds as $packIdValue ) {
			$packId = new PackId( $packIdValue );
			$pack = $packRegistry->getPack( $packId );

			if ( !$pack ) {
				wfDebugLog( 'labkipack', "Pack not found: {$packIdValue}" );
				$this->dieWithError(
					[ 'labkipackmanager-error-pack-not-found-id', $packIdValue ],
					'pack_not_found'
				);
			}

			// Verify pack belongs to the specified ref
			if ( $pack->contentRefId()->toInt() !== $resolvedRefId->toInt() ) {
				wfDebugLog( 'labkipack', "Pack {$packIdValue} does not belong to ref {$resolvedRefId->toInt()}" );
				$this->dieWithError(
					[ 'labkipackmanager-error-pack-wrong-ref', $pack->name() ],
					'pack_wrong_ref'
				);
			}

			$packNames[] = $pack->name();
			$packIdObjects[] = $packId;
		}

		// Validate pack dependencies (check if other packs depend on these)
		$packManager = new \LabkiPackManager\Services\LabkiPackManager();
		$blockingDeps = $packManager->validatePackRemoval( $resolvedRefId, $packNames );

		if ( !empty( $blockingDeps ) ) {
			wfDebugLog( 'labkipack', "Pack removal blocked: other packs depend on these: " . implode( ', ', array_keys( $blockingDeps ) ) );
			
			// Build detailed error message
			$errorDetails = [];
			foreach ( $blockingDeps as $packName => $dependents ) {
				$errorDetails[] = "{$packName} (required by: " . implode( ', ', $dependents ) . ")";
			}
			
			$this->dieWithError(
				[
					'labkipackmanager-error-pack-has-dependents',
					implode( '; ', $errorDetails )
				],
				'pack_has_dependents'
			);
		}

		wfDebugLog( 'labkipack', "Dependency validation passed for " . count( $packNames ) . " pack(s)" );

		// Generate operation ID
		$operationIdStr = 'pack_remove_' . substr( md5( $resolvedRefId->toInt() . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );
		$userId = $this->getUser()->getId();

		// Create operation record in database
		$operationRegistry = new LabkiOperationRegistry();
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_PACK_REMOVE,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			'Pack removal queued: ' . implode( ', ', $packNames )
		);

		// Queue background job
		$jobParams = [
			'ref_id' => $resolvedRefId->toInt(),
			'pack_ids' => $packIds,
			'delete_pages' => $deletePages,
			'operation_id' => $operationIdStr,
			'user_id' => $userId,
		];

		$title = $this->getTitle() ?: Title::newFromText( 'LabkiPackRemoveJob' );
		$job = new LabkiPackRemoveJob( $title, $jobParams );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApiLabkiPacksRemove: queued job with operation_id={$operationIdStr}" );

		// Build response
		$result = $this->getResult();
		$result->addValue( null, 'success', true );
		$result->addValue( null, 'operation_id', $operationIdStr );
		$result->addValue( null, 'status', LabkiOperationRegistry::STATUS_QUEUED );
		$result->addValue( null, 'message', 'Pack removal queued' );
		$result->addValue( null, 'packs', $packNames );
		$result->addValue( null, 'delete_pages', $deletePages );
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
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-remove-param-repoid',
			],
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-remove-param-repourl',
			],
			'ref_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-remove-param-refid',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-remove-param-ref',
			],
			'pack_ids' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-remove-param-packids',
			],
			'delete_pages' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-remove-param-deletepages',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiPacksRemove&repo_id=1&ref=main&pack_ids=[1,2]&delete_pages=true'
				=> 'apihelp-labkipacksremove-example-basic',
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
