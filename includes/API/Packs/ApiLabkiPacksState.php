<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Services\PackStateStore;
use LabkiPackManager\Services\DependencyResolver;
use LabkiPackManager\Services\ManifestStore;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Jobs\LabkiPackApplyJob;

/**
 * API endpoint for unified, stateful pack management.
 *
 * ## Purpose
 * Provides a command-based interface for managing pack selection sessions.
 * Tracks pack selections, page titles, prefixes, dependencies, and conflicts.
 *
 * ## Action
 * `labkiPacksState`
 *
 * ## Commands
 * - `init`: Initialize session with currently installed packs
 * - `select`: Select a pack (auto-resolves dependencies)
 * - `deselect`: Deselect a pack (validates dependents)
 * - `setPageTitle`: Customize a page's final title
 * - `setPackPrefix`: Customize a pack's prefix (recomputes page titles)
 * - `refresh`: Revalidate selections against manifest
 * - `clear`: Clear session state
 * - `apply`: Execute operations and queue background job
 *
 * @ingroup API
 */
final class ApiLabkiPacksState extends PackApiBase {

	private PackStateStore $stateStore;
	private DependencyResolver $resolver;

	/** @inheritDoc */
	public function __construct( \ApiMain $main, string $name ) {
		parent::__construct( $main, $name );
		$this->stateStore = new PackStateStore();
		$this->resolver = new DependencyResolver();
	}

	/** Execute the API request. */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$command = $params['command'];

		wfDebugLog( 'labkipack', "ApiLabkiPacksState::execute() command={$command}" );

		// Route to command handler
		switch ( $command ) {
			case 'init':
				$this->handleInit( $params );
				break;
			case 'select':
				$this->handleSelect( $params );
				break;
			case 'deselect':
				$this->handleDeselect( $params );
				break;
			case 'setPageTitle':
				$this->handleSetPageTitle( $params );
				break;
			case 'setPackPrefix':
				$this->handleSetPackPrefix( $params );
				break;
			case 'refresh':
				$this->handleRefresh( $params );
				break;
			case 'clear':
				$this->handleClear( $params );
				break;
			case 'apply':
				$this->handleApply( $params );
				break;
			default:
				$this->dieWithError(
					'labkipackmanager-error-unknown-command',
					'unknown_command'
				);
		}
	}

	/**
	 * Handle init command.
	 *
	 * Initializes session with all packs from manifest, marking installed ones as selected.
	 * Returns full state on init (not a diff).
	 *
	 * @param array $params Request parameters
	 */
	private function handleInit( array $params ): void {
		// Resolve repo and ref
		list( $resolvedRefId, $manifest ) = $this->resolveRepoRefAndManifest( $params );

		$userId = $this->getUser()->getId();
		$packRegistry = $this->getPackRegistry();

		// Get installed packs
		$installedPacks = $packRegistry->listPacksByRef( $resolvedRefId );
		$installedPackMap = [];
		foreach ( $installedPacks as $pack ) {
			$installedPackMap[$pack->name()] = $pack;
		}

		// Build pack states from manifest
		$packs = [];
		$manifestPacks = $manifest['packs'] ?? [];

		foreach ( $manifestPacks as $packName => $packDef ) {
			$currentVersion = isset( $installedPackMap[$packName] )
				? $installedPackMap[$packName]->version()
				: null;

			$packs[$packName] = PackSessionState::createPackState(
				$packName,
				$packDef,
				$currentVersion
			);
		}

		// Create state
		$state = new PackSessionState( $resolvedRefId, $userId, $packs );

		// Save state
		$this->stateStore->save( $state );

		wfDebugLog( 'labkipack', "ApiLabkiPacksState::handleInit() created state with " . count( $packs ) . " packs" );

		// Build response - init returns full state, not a diff
		$this->addResponse( [
			'ok' => true,
			'packs' => $state->packs(),
			'state_hash' => $state->hash(),
		] );
	}

	/**
	 * Handle select command.
	 *
	 * Selects a pack and auto-resolves dependencies.
	 * Returns deep diff of only changed fields.
	 *
	 * @param array $params Request parameters
	 */
	private function handleSelect( array $params ): void {
		$this->requireManagePermission();

		$payload = $this->parsePayload( $params );
		$packName = $payload['pack_name'] ?? null;

		if ( !$packName || !is_string( $packName ) ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-pack-name',
				'invalid_pack_name'
			);
		}

		// Load state and manifest
		list( $state, $manifest ) = $this->loadStateAndManifest( $params );

		// Capture old state for diff
		$oldPacks = $state->packs();

		// Check if pack exists in state
		if ( !$state->hasPack( $packName ) ) {
			$this->dieWithError(
				[ 'labkipackmanager-error-pack-not-in-manifest', $packName ],
				'pack_not_found'
			);
		}

		// Mark as selected
		$state->selectPack( $packName );

		// Resolve dependencies and auto-select
		$this->resolveDependencies( $state, $manifest );

		// Detect conflicts
		$warnings = $this->detectPageConflicts( $state );

		// Compute diff
		$newPacks = $state->packs();
		$diff = $this->computePacksDiff( $oldPacks, $newPacks );

		// Save state
		$this->stateStore->save( $state );

		// Build response - only return changed fields
		$this->addResponse( [
			'ok' => true,
			'packs' => $diff,
			'warnings' => $warnings,
			'state_hash' => $state->hash(),
		] );
	}

	/**
	 * Handle deselect command.
	 *
	 * Deselects a pack and validates dependents.
	 * Returns deep diff of only changed fields.
	 *
	 * @param array $params Request parameters
	 */
	private function handleDeselect( array $params ): void {
		$this->requireManagePermission();

		$payload = $this->parsePayload( $params );
		$packName = $payload['pack_name'] ?? null;
		$cascade = $payload['cascade'] ?? false;

		if ( !$packName || !is_string( $packName ) ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-pack-name',
				'invalid_pack_name'
			);
		}

		// Load state and manifest
		list( $state, $manifest ) = $this->loadStateAndManifest( $params );

		// Capture old state for diff
		$oldPacks = $state->packs();

		// Check if pack exists
		if ( !$state->hasPack( $packName ) ) {
			$this->dieWithError(
				[ 'labkipackmanager-error-pack-not-found', $packName ],
				'pack_not_found'
			);
		}

		// Check if other selected packs depend on this one
		$manifestPacks = $manifest['packs'] ?? [];
		$dependents = [];

		foreach ( $state->getSelectedPackNames() as $selectedPack ) {
			if ( $selectedPack === $packName ) {
				continue;
			}

			$dependencies = $manifestPacks[$selectedPack]['depends_on'] ?? [];
			if ( in_array( $packName, $dependencies, true ) ) {
				$dependents[] = $selectedPack;
			}
		}

		// Handle dependents
		$cascadeDeselected = [];
		if ( !empty( $dependents ) && !$cascade ) {
			// Error: cannot deselect without cascade
			$this->dieWithError(
				'labkipackmanager-error-cascade-deselect-required',
				'cascade_required',
				[ 'dependents' => $dependents ]
			);
		} elseif ( !empty( $dependents ) && $cascade ) {
			// Cascade deselect dependents
			foreach ( $dependents as $dependent ) {
				$state->deselectPack( $dependent );
				$cascadeDeselected[] = $dependent;
			}
		}

		// Deselect the pack
		$state->deselectPack( $packName );

		// Re-resolve dependencies for remaining selections
		$this->resolveDependencies( $state, $manifest );

		// Compute diff
		$newPacks = $state->packs();
		$diff = $this->computePacksDiff( $oldPacks, $newPacks );

		// Save state
		$this->stateStore->save( $state );

		// Build response - only return changed fields
		$this->addResponse( [
			'ok' => true,
			'packs' => $diff,
			'cascade_deselected' => $cascadeDeselected,
			'state_hash' => $state->hash(),
		] );
	}

	/**
	 * Handle setPageTitle command.
	 *
	 * Updates the final title for a specific page.
	 * Returns deep diff of only changed fields.
	 *
	 * @param array $params Request parameters
	 */
	private function handleSetPageTitle( array $params ): void {
		$this->requireManagePermission();

		$payload = $this->parsePayload( $params );
		$packName = $payload['pack_name'] ?? null;
		$pageName = $payload['page_name'] ?? null;
		$finalTitle = $payload['final_title'] ?? null;

		if ( !$packName || !$pageName || !$finalTitle ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-payload',
				'invalid_payload'
			);
		}

		// Load state
		list( $state, $manifest ) = $this->loadStateAndManifest( $params );

		// Capture old state for diff
		$oldPacks = $state->packs();

		// Validate pack and page exist
		$pack = $state->getPack( $packName );
		if ( !$pack || !isset( $pack['pages'][$pageName] ) ) {
			$this->dieWithError(
				'labkipackmanager-error-page-not-found',
				'page_not_found'
			);
		}

		// Update page title
		$state->setPageFinalTitle( $packName, $pageName, $finalTitle );

		// Detect conflicts with new title
		$warnings = $this->detectPageConflicts( $state );

		// Compute diff
		$newPacks = $state->packs();
		$diff = $this->computePacksDiff( $oldPacks, $newPacks );

		// Save state
		$this->stateStore->save( $state );

		// Build response - only return changed fields
		$this->addResponse( [
			'ok' => true,
			'packs' => $diff,
			'warnings' => $warnings,
			'state_hash' => $state->hash(),
		] );
	}

	/**
	 * Handle setPackPrefix command.
	 *
	 * Updates the prefix for a pack and recomputes all page titles.
	 * Returns deep diff of only changed fields.
	 *
	 * @param array $params Request parameters
	 */
	private function handleSetPackPrefix( array $params ): void {
		$this->requireManagePermission();

		$payload = $this->parsePayload( $params );
		$packName = $payload['pack_name'] ?? null;
		$prefix = $payload['prefix'] ?? null;

		if ( !$packName || $prefix === null ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-payload',
				'invalid_payload'
			);
		}

		// Load state
		list( $state, $manifest ) = $this->loadStateAndManifest( $params );

		// Capture old state for diff
		$oldPacks = $state->packs();

		// Validate pack exists
		if ( !$state->hasPack( $packName ) ) {
			$this->dieWithError(
				[ 'labkipackmanager-error-pack-not-found', $packName ],
				'pack_not_found'
			);
		}

		// Update pack prefix (this recomputes page titles automatically)
		$state->setPackPrefix( $packName, $prefix );

		// Detect conflicts with new titles
		$warnings = $this->detectPageConflicts( $state );

		// Compute diff
		$newPacks = $state->packs();
		$diff = $this->computePacksDiff( $oldPacks, $newPacks );

		// Save state
		$this->stateStore->save( $state );

		// Build response - only return changed fields
		$this->addResponse( [
			'ok' => true,
			'packs' => $diff,
			'warnings' => $warnings,
			'state_hash' => $state->hash(),
		] );
	}

	/**
	 * Handle refresh command.
	 *
	 * Revalidates selections against manifest.
	 * Returns full state (not a diff) since anything could have changed.
	 *
	 * @param array $params Request parameters
	 */
	private function handleRefresh( array $params ): void {
		// Load state and manifest
		list( $state, $manifest ) = $this->loadStateAndManifest( $params );

		// Re-resolve dependencies
		$this->resolveDependencies( $state, $manifest );

		// Detect conflicts
		$warnings = $this->detectPageConflicts( $state );

		// Save state
		$this->stateStore->save( $state );

		// Build response - refresh returns full state since anything could change
		$this->addResponse( [
			'ok' => true,
			'packs' => $state->packs(),
			'warnings' => $warnings,
			'state_hash' => $state->hash(),
		] );
	}

	/**
	 * Handle clear command.
	 *
	 * Clears session state.
	 *
	 * @param array $params Request parameters
	 */
	private function handleClear( array $params ): void {
		$this->requireManagePermission();

		// Resolve repo and ref
		list( $resolvedRefId, $manifest ) = $this->resolveRepoRefAndManifest( $params );
		$userId = $this->getUser()->getId();

		// Clear state
		$this->stateStore->clear( $userId, $resolvedRefId );

		$this->addResponse( [
			'ok' => true,
			'message' => 'Session state cleared',
		] );
	}

	/**
	 * Handle apply command.
	 *
	 * Converts state to operations and queues background job.
	 *
	 * @param array $params Request parameters
	 */
	private function handleApply( array $params ): void {
		$this->requireManagePermission();

		// Load state and manifest
		list( $state, $manifest ) = $this->loadStateAndManifest( $params );

		$refId = $state->refId();

		// Build operations array from state
		$operations = [];
		$summary = [
			'installs' => 0,
			'updates' => 0,
			'removes' => 0,
		];

		foreach ( $state->packs() as $packName => $packState ) {
			$action = $packState['action'] ?? 'unchanged';
			$selected = ( $packState['selected'] ?? false ) || ( $packState['auto_selected'] ?? false );

			// Skip unchanged packs
			if ( $action === 'unchanged' ) {
				continue;
			}

			// Only include selected packs for install/update
			if ( ( $action === 'install' || $action === 'update' ) && !$selected ) {
				continue;
			}

			// Build operation
			if ( $action === 'install' ) {
				$operations[] = [
					'action' => 'install',
					'pack_name' => $packName,
					'pages' => $this->buildPagesArray( $packState['pages'] ?? [] ),
				];
				$summary['installs']++;
			} elseif ( $action === 'update' ) {
				$operations[] = [
					'action' => 'update',
					'pack_name' => $packName,
					'target_version' => $packState['target_version'] ?? '0.0.0',
					'pages' => $this->buildPagesArray( $packState['pages'] ?? [] ),
				];
				$summary['updates']++;
			} elseif ( $action === 'remove' && !$selected ) {
				// Remove operations for deselected installed packs
				// We need the pack_id from the registry
				$packRegistry = $this->getPackRegistry();
				$packId = $packRegistry->getPackIdByName( $refId, $packName );
				if ( $packId !== null ) {
					$operations[] = [
						'action' => 'remove',
						'pack_id' => $packId->toInt(),
					];
					$summary['removes']++;
				}
			}
		}

		if ( empty( $operations ) ) {
			$this->dieWithError(
				'labkipackmanager-error-no-operations',
				'no_operations'
			);
		}

		// Generate operation ID
		$operationIdStr = 'pack_apply_' . substr( md5( $refId->toInt() . microtime() ), 0, 8 );
		$operationId = new OperationId( $operationIdStr );
		$userId = $this->getUser()->getId();

		// Create operation record
		$operationRegistry = new LabkiOperationRegistry();
		$operationMessage = sprintf(
			'Pack operations queued: %d installs, %d updates, %d removes',
			$summary['installs'],
			$summary['updates'],
			$summary['removes']
		);
		$operationRegistry->createOperation(
			$operationId,
			LabkiOperationRegistry::TYPE_PACK_APPLY,
			$userId,
			LabkiOperationRegistry::STATUS_QUEUED,
			$operationMessage
		);

		// Queue background job
		$jobParams = [
			'ref_id' => $refId->toInt(),
			'operations' => $operations,
			'operation_id' => $operationIdStr,
			'user_id' => $userId,
		];

		$title = $this->getTitle() ?: Title::newFromText( 'LabkiPackApplyJob' );
		$job = new LabkiPackApplyJob( $title, $jobParams );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		wfDebugLog( 'labkipack', "ApiLabkiPacksState: queued job with operation_id={$operationIdStr}" );

		// Clear state
		$this->stateStore->clear( $userId, $refId );

		// Build response
		$this->addResponse( [
			'ok' => true,
			'operation_id' => $operationIdStr,
			'status' => LabkiOperationRegistry::STATUS_QUEUED,
			'message' => 'Pack operations queued',
			'summary' => [
				'total_operations' => count( $operations ),
				'installs' => $summary['installs'],
				'updates' => $summary['updates'],
				'removes' => $summary['removes'],
			],
		] );
	}

	/**
	 * Resolve dependencies and auto-select required packs.
	 *
	 * @param PackSessionState $state Session state
	 * @param array $manifest Manifest data
	 * @return array Array of pack names that were auto-selected (for tracking changes)
	 */
	private function resolveDependencies( PackSessionState $state, array $manifest ): array {
		$manifestPacks = $manifest['packs'] ?? [];
		$autoSelectedPacks = [];

		// Clear all auto-selections first
		foreach ( $state->packs() as $packName => $packState ) {
			if ( $packState['auto_selected'] ?? false ) {
				$state->deselectPack( $packName );
			}
		}

		// Get user-selected packs
		$selectedPacks = $state->getUserSelectedPackNames();

		// Recursively resolve dependencies
		$processed = [];
		$toProcess = $selectedPacks;

		while ( !empty( $toProcess ) ) {
			$packName = array_shift( $toProcess );

			if ( isset( $processed[$packName] ) ) {
				continue;
			}
			$processed[$packName] = true;

			if ( !isset( $manifestPacks[$packName] ) ) {
				continue;
			}

			$dependencies = $manifestPacks[$packName]['depends_on'] ?? [];

			foreach ( $dependencies as $depName ) {
				// Skip if already selected by user
				if ( in_array( $depName, $selectedPacks, true ) ) {
					continue;
				}

				// Auto-select this dependency
				$pack = $state->getPack( $depName );
				if ( $pack && !( $pack['selected'] ?? false ) ) {
					$state->autoSelectPack( $depName, "Required by {$packName}" );
					$autoSelectedPacks[] = $depName;
					// Add to queue to process its dependencies
					$toProcess[] = $depName;
				}
			}
		}

		return $autoSelectedPacks;
	}

	/**
	 * Detect page title conflicts with existing wiki pages.
	 *
	 * @param PackSessionState $state Session state
	 * @return array Array of warning messages
	 */
	private function detectPageConflicts( PackSessionState $state ): array {
		$warnings = [];

		foreach ( $state->packs() as $packName => $packState ) {
			$selected = ( $packState['selected'] ?? false ) || ( $packState['auto_selected'] ?? false );

			if ( !$selected ) {
				continue;
			}

			foreach ( $packState['pages'] ?? [] as $pageName => $pageState ) {
				$finalTitle = $pageState['final_title'] ?? '';
				$title = Title::newFromText( $finalTitle );

				if ( $title && $title->exists() ) {
					$warnings[] = "Page '{$finalTitle}' already exists (pack: {$packName}, page: {$pageName})";
				}
			}
		}

		return $warnings;
	}

	/**
	 * Compute deep diff between two states.
	 *
	 * Returns only the fields that changed, preserving hierarchy.
	 * Example: If only page "test page" has_conflict changed from false to true:
	 * {
	 *   "packs": {
	 *     "test pack": {
	 *       "pages": {
	 *         "test page": {
	 *           "has_conflict": true,
	 *           "conflict_type": "title_exists"
	 *         }
	 *       }
	 *     }
	 *   }
	 * }
	 *
	 * @param array $oldPacks Old packs state
	 * @param array $newPacks New packs state
	 * @return array Diff containing only changed fields with hierarchy
	 */
	private function computePacksDiff( array $oldPacks, array $newPacks ): array {
		$diff = [];

		foreach ( $newPacks as $packName => $newPack ) {
			$oldPack = $oldPacks[$packName] ?? null;

			if ( $oldPack === null ) {
				// New pack - include everything
				$diff[$packName] = $newPack;
				continue;
			}

			$packDiff = $this->computePackDiff( $oldPack, $newPack );
			if ( !empty( $packDiff ) ) {
				$diff[$packName] = $packDiff;
			}
		}

		return $diff;
	}

	/**
	 * Compute diff for a single pack.
	 *
	 * @param array $oldPack Old pack state
	 * @param array $newPack New pack state
	 * @return array Pack diff with only changed fields
	 */
	private function computePackDiff( array $oldPack, array $newPack ): array {
		$diff = [];

		// Check top-level pack fields
		$topLevelFields = [ 'selected', 'auto_selected', 'auto_selected_reason', 'action', 'current_version', 'target_version', 'prefix' ];

		foreach ( $topLevelFields as $field ) {
			$oldValue = $oldPack[$field] ?? null;
			$newValue = $newPack[$field] ?? null;

			if ( $oldValue !== $newValue ) {
				$diff[$field] = $newValue;
			}
		}

		// Check pages
		$oldPages = $oldPack['pages'] ?? [];
		$newPages = $newPack['pages'] ?? [];

		$pagesDiff = $this->computePagesDiff( $oldPages, $newPages );
		if ( !empty( $pagesDiff ) ) {
			$diff['pages'] = $pagesDiff;
		}

		return $diff;
	}

	/**
	 * Compute diff for pages within a pack.
	 *
	 * @param array $oldPages Old pages state
	 * @param array $newPages New pages state
	 * @return array Pages diff with only changed fields
	 */
	private function computePagesDiff( array $oldPages, array $newPages ): array {
		$diff = [];

		foreach ( $newPages as $pageName => $newPage ) {
			$oldPage = $oldPages[$pageName] ?? null;

			if ( $oldPage === null ) {
				// New page - include everything
				$diff[$pageName] = $newPage;
				continue;
			}

			$pageDiff = [];
			$pageFields = [ 'name', 'default_title', 'final_title', 'has_conflict', 'conflict_type' ];

			foreach ( $pageFields as $field ) {
				$oldValue = $oldPage[$field] ?? null;
				$newValue = $newPage[$field] ?? null;

				if ( $oldValue !== $newValue ) {
					$pageDiff[$field] = $newValue;
				}
			}

			if ( !empty( $pageDiff ) ) {
				$diff[$pageName] = $pageDiff;
			}
		}

		return $diff;
	}

	/**
	 * Build pages array for job operations.
	 *
	 * @param array $pages Pages from state
	 * @return array Pages array for job
	 */
	private function buildPagesArray( array $pages ): array {
		$result = [];
		foreach ( $pages as $pageName => $pageState ) {
			$result[] = [
				'name' => $pageName,
				'final_title' => $pageState['final_title'] ?? $pageName,
			];
		}
		return $result;
	}

	/**
	 * Resolve repo and ref, and load manifest.
	 *
	 * @param array $params Request parameters
	 * @return array [ContentRefId, manifest_array]
	 */
	private function resolveRepoRefAndManifest( array $params ): array {
		// Get parameters
		$repoId = $params['repo_id'] ?? null;
		$repoUrl = $params['repo_url'] ?? null;
		$refId = $params['ref_id'] ?? null;
		$ref = $params['ref'] ?? null;

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

		// Load manifest
		$refRegistry = $this->getRefRegistry();
		$refObj = $refRegistry->getRefById( $resolvedRefId );

		if ( !$refObj ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-not-found',
				'ref_not_found'
			);
		}

		$repoRegistry = $this->getRepoRegistry();
		$repoObj = $repoRegistry->getRepo( $resolvedRepoId->toInt() );

		if ( !$repoObj ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-not-found',
				'repo_not_found'
			);
		}

		$manifestStore = new ManifestStore(
			$repoObj->url(),
			$refObj->sourceRef()
		);

		$manifestStatus = $manifestStore->get();
		if ( !$manifestStatus->isOK() ) {
			$this->dieWithError(
				'labkipackmanager-error-manifest-not-found',
				'manifest_not_found'
			);
		}

		$manifestData = $manifestStatus->getValue();
		$manifest = $manifestData['manifest'] ?? [];

		return [ $resolvedRefId, $manifest ];
	}

	/**
	 * Load state and manifest for current user and resolved ref.
	 *
	 * @param array $params Request parameters
	 * @return array [PackSessionState, manifest_array]
	 */
	private function loadStateAndManifest( array $params ): array {
		list( $resolvedRefId, $manifest ) = $this->resolveRepoRefAndManifest( $params );

		$userId = $this->getUser()->getId();
		$state = $this->stateStore->get( $userId, $resolvedRefId );

		if ( $state === null ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-state',
				'no_state'
			);
		}

		return [ $state, $manifest ];
	}

	/**
	 * Parse JSON payload parameter.
	 *
	 * @param array $params Request parameters
	 * @return array Parsed payload
	 */
	private function parsePayload( array $params ): array {
		$payloadJson = $params['payload'] ?? '{}';

		$payload = json_decode( $payloadJson, true );
		if ( !is_array( $payload ) ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-payload',
				'invalid_payload'
			);
		}

		return $payload;
	}

	/**
	 * Add response data to result.
	 *
	 * @param array $data Response data
	 */
	private function addResponse( array $data ): void {
		$result = $this->getResult();

		// Add meta
		$data['meta'] = [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		];

		foreach ( $data as $key => $value ) {
			$result->addValue( null, $key, $value );
		}
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'command' => [
				ParamValidator::PARAM_TYPE => [ 'init', 'select', 'deselect', 'setPageTitle', 'setPackPrefix', 'refresh', 'clear', 'apply' ],
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-state-param-command',
			],
			'repo_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-state-param-repoid',
			],
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-state-param-repourl',
			],
			'ref_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-state-param-refid',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-state-param-ref',
			],
			'payload' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-state-param-payload',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiPacksState&command=init&repo_id=1&ref=main'
				=> 'apihelp-labkipacksstate-example-init',
			'action=labkiPacksState&command=select&payload={"pack_name":"Advanced Imaging"}'
				=> 'apihelp-labkipacksstate-example-select',
			'action=labkiPacksState&command=setPageTitle&payload={"pack_name":"test pack","page_name":"test page","final_title":"Custom/Title"}'
				=> 'apihelp-labkipacksstate-example-setpagetitle',
			'action=labkiPacksState&command=apply'
				=> 'apihelp-labkipacksstate-example-apply',
		];
	}

	/** POST required for state-changing operations. */
	public function mustBePosted(): bool {
		return true;
	}

	/** All commands except init/refresh are write operations. */
	public function isWriteMode(): bool {
		$params = $this->extractRequestParams();
		$command = $params['command'] ?? '';
		return !in_array( $command, [ 'init', 'refresh' ], true );
	}

	/** Internal API. */
	public function isInternal(): bool {
		return true;
	}
}
