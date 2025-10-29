<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use Wikimedia\ParamValidator\ParamValidator;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\GitContentManager;
use Symfony\Component\Yaml\Yaml;
use MediaWiki\Title\Title;

/**
 * API endpoint to preview pack operations before execution.
 *
 * ## Purpose
 * Provides a detailed preview of what will happen when installing, updating,
 * or removing packs, including:
 * - Dependency resolution (automatic selection of required packs)
 * - Page naming conflict detection
 * - Version compatibility checks
 * - Full breakdown of all pages affected
 *
 * ## Action
 * `labkiPacksPreview`
 *
 * ## Example Request
 * ```
 * POST api.php?action=labkiPacksPreview
 * {
 *   "repo_id": 1,
 *   "ref": "main",
 *   "operations": [
 *     {"action": "update", "pack_name": "Analysis Basics"},
 *     {"action": "install", "pack_name": "Advanced Imaging"}
 *   ]
 * }
 * ```
 *
 * ## Response Structure
 * ```json
 * {
 *   "success": true,
 *   "summary": {
 *     "total_packs": 4,
 *     "packs_requested": 2,
 *     "packs_auto_included": 1,
 *     "total_pages": 25,
 *     "conflicts": 3,
 *     "dependency_issues": 0
 *   },
 *   "packs": [
 *     {
 *       "name": "Advanced Imaging",
 *       "action": "install",
 *       "version": "1.0.0",
 *       "auto_included": false,
 *       "dependencies": [
 *         {
 *           "name": "Hardware Setup",
 *           "status": "included",
 *           "action": "update"
 *         },
 *         {
 *           "name": "Calibration Tools",
 *           "status": "auto_selected",
 *           "action": "install"
 *         }
 *       ],
 *       "pages": [
 *         {
 *           "name": "Processing",
 *           "default_title": "Imaging/Processing",
 *           "conflict": {
 *             "type": "title_exists",
 *             "existing_page_id": 456,
 *             "suggested_title": "Imaging/Advanced_Processing"
 *           }
 *         }
 *       ]
 *     }
 *   ]
 * }
 * ```
 *
 * @ingroup API
 */
final class ApiLabkiPacksPreview extends PackApiBase {

	/** @inheritDoc */
	public function __construct( \ApiMain $main, string $name ) {
		parent::__construct( $main, $name );
	}

	/** Execute the API request. */
	public function execute(): void {
		$params = $this->extractRequestParams();

		// Get parameters
		$repoId = $params['repo_id'] ?? null;
		$repoUrl = $params['repo_url'] ?? null;
		$refId = $params['ref_id'] ?? null;
		$ref = $params['ref'] ?? null;
		$operationsJson = $params['operations'] ?? '[]';

		wfDebugLog( 'labkipack', "ApiLabkiPacksPreview::execute() repo_id={$repoId}, ref={$ref}" );

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

		// Parse operations JSON
		$operations = json_decode( $operationsJson, true );
		if ( !is_array( $operations ) || empty( $operations ) ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-operations',
				'invalid_operations'
			);
		}

		// Validate each operation
		foreach ( $operations as $op ) {
			if ( !isset( $op['action'] ) || !isset( $op['pack_name'] ) ) {
				$this->dieWithError(
					'labkipackmanager-error-invalid-operation-format',
					'invalid_operation_format'
				);
			}
			if ( !in_array( $op['action'], [ 'install', 'update', 'remove' ], true ) ) {
				$this->dieWithError(
					'labkipackmanager-error-invalid-operation-action',
					'invalid_operation_action'
				);
			}
		}

		// Resolve repo and ref
		$identifier = $repoId ?? $repoUrl;
		$resolvedRepoId = $this->resolveRepoId( $identifier );

		if ( $resolvedRepoId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-not-found',
				'repo_not_found'
			);
		}

		$refIdentifier = $refId ?? $ref;
		$resolvedRefId = $this->resolveRefId( $resolvedRepoId, $refIdentifier );

		if ( $resolvedRefId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-not-found',
				'ref_not_found'
			);
		}

		// Verify ref has worktree
		$refRegistry = $this->getRefRegistry();
		$refObj = $refRegistry->getRefById( $resolvedRefId );

		if ( !$refObj || !$refObj->worktreePath() || !is_dir( $refObj->worktreePath() ) ) {
			$this->dieWithError(
				'labkipackmanager-error-worktree-not-found',
				'worktree_not_found'
			);
		}

		// Generate preview
		$preview = $this->generatePreview( $resolvedRefId, $refObj->worktreePath(), $operations );

		// Build response
		$result = $this->getResult();
		$result->addValue( null, 'success', true );
		$result->addValue( null, 'summary', $preview['summary'] );
		$result->addValue( null, 'packs', $preview['packs'] );
		$result->addValue( null, 'meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		] );
	}

	/**
	 * Generate a preview of the pack operations.
	 *
	 * @param ContentRefId $refId
	 * @param string $worktreePath
	 * @param array $operations
	 * @return array Preview data
	 */
	private function generatePreview(
		ContentRefId $refId,
		string $worktreePath,
		array $operations
	): array {
		$packRegistry = $this->getPackRegistry();
		$gitContentManager = new GitContentManager();

		// Load manifest
		$manifestPath = $worktreePath . '/manifest.yml';
		if ( !file_exists( $manifestPath ) ) {
			$this->dieWithError(
				'labkipackmanager-error-manifest-not-found',
				'manifest_not_found'
			);
		}

		$manifestContent = file_get_contents( $manifestPath );
		$manifest = Yaml::parse( $manifestContent );

		if ( !isset( $manifest['packs'] ) || !is_array( $manifest['packs'] ) ) {
			$this->dieWithError(
				'labkipackmanager-error-invalid-manifest',
				'invalid_manifest'
			);
		}

		// Build pack map from manifest
		$packMap = [];
		foreach ( $manifest['packs'] as $packName => $packDef ) {
			$packMap[$packName] = $packDef;
		}

		// Get installed packs
		$installedPacks = $packRegistry->listPacksByRef( $refId );
		$installedPackMap = [];
		foreach ( $installedPacks as $pack ) {
			$installedPackMap[$pack->name()] = $pack;
		}

		// Resolve dependencies and build operation list
		$resolvedOperations = [];
		$autoIncludedPacks = [];
		
		foreach ( $operations as $op ) {
			$packName = $op['pack_name'];
			$action = $op['action'];

			if ( !isset( $packMap[$packName] ) && $action !== 'remove' ) {
				$this->dieWithError(
					[ 'labkipackmanager-error-pack-not-in-manifest', $packName ],
					'pack_not_found'
				);
			}

			$resolvedOperations[$packName] = [
				'pack_name' => $packName,
				'action' => $action,
				'auto_included' => false,
			];

			// For install/update, check dependencies
			if ( $action === 'install' || $action === 'update' ) {
				$packDef = $packMap[$packName];
				$dependencies = $packDef['depends_on'] ?? [];

				foreach ( $dependencies as $depName ) {
					// Skip if already in operations
					if ( isset( $resolvedOperations[$depName] ) ) {
						continue;
					}

					// Auto-include dependency
					$depAction = isset( $installedPackMap[$depName] ) ? 'update' : 'install';
					$resolvedOperations[$depName] = [
						'pack_name' => $depName,
						'action' => $depAction,
						'auto_included' => true,
						'required_by' => $packName,
					];
					$autoIncludedPacks[] = $depName;
				}
			}
		}

		// Build detailed preview for each pack
		$packsPreview = [];
		$totalPages = 0;
		$totalConflicts = 0;

		foreach ( $resolvedOperations as $packName => $op ) {
			$packPreview = $this->buildPackPreview(
				$refId,
				$packName,
				$op['action'],
				$packMap[$packName] ?? [],
				$installedPackMap[$packName] ?? null,
				$op['auto_included'] ?? false,
				$worktreePath
			);

			$totalPages += count( $packPreview['pages'] ?? [] );
			$totalConflicts += $packPreview['conflict_count'] ?? 0;

			$packsPreview[] = $packPreview;
		}

		return [
			'summary' => [
				'total_packs' => count( $resolvedOperations ),
				'packs_requested' => count( $operations ),
				'packs_auto_included' => count( $autoIncludedPacks ),
				'total_pages' => $totalPages,
				'conflicts' => $totalConflicts,
				'dependency_issues' => 0, // TODO: Implement dependency conflict detection
			],
			'packs' => $packsPreview,
		];
	}

	/**
	 * Build preview for a single pack.
	 *
	 * @param ContentRefId $refId
	 * @param string $packName
	 * @param string $action
	 * @param array $packDef
	 * @param mixed $installedPack
	 * @param bool $autoIncluded
	 * @param string $worktreePath
	 * @return array Pack preview data
	 */
	private function buildPackPreview(
		ContentRefId $refId,
		string $packName,
		string $action,
		array $packDef,
		$installedPack,
		bool $autoIncluded,
		string $worktreePath
	): array {
		$packRegistry = $this->getPackRegistry();

		$preview = [
			'name' => $packName,
			'action' => $action,
			'auto_included' => $autoIncluded,
			'current_version' => $installedPack ? $installedPack->version() : null,
			'target_version' => $packDef['version'] ?? null,
			'dependencies' => [],
			'required_by' => [],
			'pages' => [],
			'conflict_count' => 0,
		];

		// Add dependency info
		if ( isset( $packDef['depends_on'] ) && is_array( $packDef['depends_on'] ) ) {
			foreach ( $packDef['depends_on'] as $depName ) {
				$preview['dependencies'][] = [
					'name' => $depName,
					'status' => 'required',
				];
			}
		}

		// Add required_by info (reverse lookup)
		if ( $installedPack ) {
			$dependents = $packRegistry->getPacksDependingOn( $refId, $installedPack->id() );
			foreach ( $dependents as $dependent ) {
				$preview['required_by'][] = $dependent->name();
			}
		}

		// Build page previews
		if ( $action !== 'remove' && isset( $packDef['pages'] ) ) {
			foreach ( $packDef['pages'] as $pageName => $pageDef ) {
				$pagePreview = $this->buildPagePreview( $packName, $pageName, $pageDef );
				if ( isset( $pagePreview['conflict'] ) ) {
					$preview['conflict_count']++;
				}
				$preview['pages'][] = $pagePreview;
			}
		}

		return $preview;
	}

	/**
	 * Build preview for a single page.
	 *
	 * @param string $packName
	 * @param string $pageName
	 * @param array $pageDef
	 * @return array Page preview data
	 */
	private function buildPagePreview(
		string $packName,
		string $pageName,
		array $pageDef
	): array {
		// Determine default title
		$prefix = $pageDef['prefix'] ?? $packName;
		$defaultTitle = $prefix ? "{$prefix}/{$pageName}" : $pageName;

		// Check for conflicts
		$title = Title::newFromText( $defaultTitle );
		$conflict = null;

		if ( $title && $title->exists() ) {
			$conflict = [
				'type' => 'title_exists',
				'existing_page_id' => $title->getArticleID(),
				'suggested_title' => "{$packName}/{$pageName}", // Fallback suggestion
			];
		}

		return [
			'name' => $pageName,
			'default_title' => $defaultTitle,
			'conflict' => $conflict,
		];
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'repo_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-preview-param-repoid',
			],
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-preview-param-repourl',
			],
			'ref_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-preview-param-refid',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-preview-param-ref',
			],
			'operations' => [
				ParamValidator::PARAM_TYPE => 'text', // JSON-encoded array
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-preview-param-operations',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiPacksPreview&repo_id=1&ref=main&operations=[{"action":"install","pack_name":"Advanced Imaging"}]'
				=> 'apihelp-labkipackspreview-example-basic',
		];
	}

	/** POST required for complex operations. */
	public function mustBePosted(): bool {
		return true;
	}

	/** Read operation (no DB changes). */
	public function isWriteMode(): bool {
		return false;
	}

	/** Internal API. */
	public function isInternal(): bool {
		return true;
	}
}
