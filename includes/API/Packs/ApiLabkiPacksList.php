<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use Wikimedia\ParamValidator\ParamValidator;
//TODO: Consider only requiring single id if providing id.. these will directly access a row in the database.
//For example, we don't need to know the repo url and ref if we directly know the pack id.


/**
 * API endpoint to list and query installed packs.
 *
 * This endpoint provides comprehensive information about installed content packs,
 * including their pages, metadata, and installation status.
 *
 * ## Usage Examples
 *
 * List all installed packs:
 * ```
 * api.php?action=labkiPacksList&format=json
 * ```
 *
 * Get packs for a specific repository by ID:
 * ```
 * api.php?action=labkiPacksList&repo_id=1&format=json
 * ```
 *
 * Get packs for a specific repository by URL:
 * ```
 * api.php?action=labkiPacksList&repo_url=https://github.com/user/repo&format=json
 * ```
 *
 * Get packs for a specific ref (by name):
 * ```
 * api.php?action=labkiPacksList&repo_id=1&ref=main&format=json
 * ```
 *
 * Get packs for a specific ref (by ID):
 * ```
 * api.php?action=labkiPacksList&repo_id=1&ref_id=5&format=json
 * ```
 *
 * Get pages for a specific pack (by name):
 * ```
 * api.php?action=labkiPacksList&repo_id=1&ref=main&pack=MyPack&format=json
 * ```
 *
 * Get pages for a specific pack (by ID):
 * ```
 * api.php?action=labkiPacksList&repo_id=1&ref=main&pack_id=10&format=json
 * ```
 *
 * ## Response Structure
 *
 * When listing packs:
 * ```json
 * {
 *   "packs": [
 *     {
 *       "pack_id": 10,
 *       "content_ref_id": 5,
 *       "repo_id": 1,
 *       "repo_url": "https://github.com/user/repo",
 *       "ref": "main",
 *       "name": "MyPack",
 *       "version": "1.0.0",
 *       "source_commit": "abc123",
 *       "installed_at": "20251024120000",
 *       "installed_by": 1,
 *       "updated_at": "20251024120000",
 *       "status": "installed",
 *       "page_count": 5
 *     }
 *   ],
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000"
 *   }
 * }
 * ```
 *
 * When listing pages for a pack:
 * ```json
 * {
 *   "pack": {
 *     "pack_id": 10,
 *     "name": "MyPack",
 *     ...
 *   },
 *   "pages": [
 *     {
 *       "page_id": 42,
 *       "pack_id": 10,
 *       "name": "Page1",
 *       "final_title": "MyPack/Page1",
 *       "page_namespace": 0,
 *       "wiki_page_id": 123,
 *       "last_rev_id": 456,
 *       "content_hash": "def789",
 *       "created_at": "20251024120000",
 *       "updated_at": "20251024120000"
 *     }
 *   ],
 *   "meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000"
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 *
 * - Uses `LabkiPackRegistry` for pack data
 * - Uses `LabkiPageRegistry` for page data
 * - Uses `LabkiRepoRegistry` and `LabkiRefRegistry` for resolution
 * - Supports filtering by repo, ref, and pack
 * - Supports both ID and name lookups for repo, ref, and pack
 * - Returns empty array if no packs found
 *
 * @ingroup API
 */
class ApiLabkiPacksList extends PackApiBase {

	/**
	 * Constructor.
	 *
	 * @param \ApiMain $main Main API object
	 * @param string $name Module name
	 */
	public function __construct( \ApiMain $main, string $name ) {
		wfDebugLog( 'labkipack', "ApiLabkiPacksList::__construct() called with name={$name}" );
		parent::__construct( $main, $name );
	}

	/**
	 * Execute the API request.
	 *
	 * Main entry point for the API. Handles:
	 * - Listing all packs (no parameters)
	 * - Listing packs for a repository (repo_id or repo_url)
	 * - Listing packs for a specific ref (repo + ref or ref_id)
	 * - Listing a specific pack (repo + ref + pack or pack_id)
	 *
	 * All modes return consistent structure: { packs: [...] }
	 * Use include_pages=true to nest page data within each pack.
	 */
	public function execute(): void {
		wfDebugLog( 'labkipack', "ApiLabkiPacksList::execute() started" );

		// Get parameters
		$repoId = $this->getParameter( 'repo_id' );
		$repoUrl = $this->getParameter( 'repo_url' );
		$refId = $this->getParameter( 'ref_id' );
		$ref = $this->getParameter( 'ref' );
		$packId = $this->getParameter( 'pack_id' );
		$pack = $this->getParameter( 'pack' );
		$includePages = $this->getParameter( 'include_pages' );

		wfDebugLog( 'labkipack', "ApiLabkiPacksList::execute() params: repo_id={$repoId}, repo_url={$repoUrl}, ref_id={$refId}, ref={$ref}, pack_id={$packId}, pack={$pack}, include_pages={$includePages}" );

		// Validate: only one repo identifier should be provided
		if ( $repoId !== null && $repoUrl !== null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-multiple-identifiers',
				'multiple_identifiers'
			);
		}

		// Validate: only one ref identifier should be provided
		if ( $refId !== null && $ref !== null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-multiple-identifiers',
				'multiple_identifiers'
			);
		}

		// Validate: only one pack identifier should be provided
		if ( $packId !== null && $pack !== null ) {
			$this->dieWithError(
				'labkipackmanager-error-pack-multiple-identifiers',
				'multiple_identifiers'
			);
		}

		// Validate: if ref is specified, repo must be specified
		if ( ( $refId !== null || $ref !== null ) && $repoId === null && $repoUrl === null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-requires-repo',
				'missing_repo'
			);
		}

		// Validate: if pack is specified, ref must be specified (unless using pack_id)
		if ( $pack !== null && $refId === null && $ref === null ) {
			$this->dieWithError(
				'labkipackmanager-error-pack-requires-ref',
				'missing_ref'
			);
		}

		// Determine query mode and execute
		$packs = [];
		
		if ( $packId !== null ) {
			// Mode 4: Get specific pack by pack_id
			wfDebugLog( 'labkipack', "ApiLabkiPacksList::execute() mode: single pack by ID" );
			$packs = $this->getSinglePackById( $packId, $includePages );
		} elseif ( $pack !== null ) {
			// Mode 4: Get specific pack by name
			wfDebugLog( 'labkipack', "ApiLabkiPacksList::execute() mode: single pack by name" );
			$resolvedRepoId = $this->resolveRepoIdentifier( $repoId, $repoUrl );
			$resolvedRefId = $this->resolveRefIdentifier( $resolvedRepoId, $refId, $ref );
			$packs = $this->getSinglePackByName( $resolvedRefId, $pack, $includePages );
		} elseif ( $refId !== null || $ref !== null ) {
			// Mode 3: Get packs for a specific ref
			wfDebugLog( 'labkipack', "ApiLabkiPacksList::execute() mode: packs for ref" );
			$resolvedRepoId = $this->resolveRepoIdentifier( $repoId, $repoUrl );
			$resolvedRefId = $this->resolveRefIdentifier( $resolvedRepoId, $refId, $ref );
			$packs = $this->getPacksForRef( $resolvedRefId, $includePages );
		} elseif ( $repoId !== null || $repoUrl !== null ) {
			// Mode 2: Get packs for a repository
			wfDebugLog( 'labkipack', "ApiLabkiPacksList::execute() mode: packs for repo" );
			$resolvedRepoId = $this->resolveRepoIdentifier( $repoId, $repoUrl );
			$packs = $this->getPacksForRepo( $resolvedRepoId, $includePages );
		} else {
			// Mode 1: Get all packs
			wfDebugLog( 'labkipack', "ApiLabkiPacksList::execute() mode: all packs" );
			$packs = $this->getAllPacks( $includePages );
		}

		// Build response with consistent structure
		$result = [
			'packs' => $packs,
			'meta' => [
				'schemaVersion' => 1,
				'timestamp' => wfTimestampNow(),
			],
		];

		// Add to API result
		$apiResult = $this->getResult();
		$apiResult->addValue( null, 'packs', $result['packs'] );
		$apiResult->addValue( null, 'meta', $result['meta'] );

		wfDebugLog( 'labkipack', "ApiLabkiPacksList::execute() completed successfully" );
	}

	/**
	 * Resolve repository identifier (ID or URL) to ContentRepoId.
	 *
	 * @param int|null $repoId Repository ID
	 * @param string|null $repoUrl Repository URL
	 * @return \LabkiPackManager\Domain\ContentRepoId Repository ID
	 */
	private function resolveRepoIdentifier( ?int $repoId, ?string $repoUrl ): \LabkiPackManager\Domain\ContentRepoId {
		$identifier = $repoId ?? $repoUrl;
		$resolvedId = $this->resolveRepoId( $identifier );

		if ( $resolvedId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-not-found',
				'repo_not_found'
			);
		}

		return $resolvedId;
	}

	/**
	 * Resolve ref identifier (ID or name) to ContentRefId.
	 *
	 * @param \LabkiPackManager\Domain\ContentRepoId $repoId Parent repository ID
	 * @param int|null $refId Ref ID
	 * @param string|null $ref Ref name
	 * @return \LabkiPackManager\Domain\ContentRefId Ref ID
	 */
	private function resolveRefIdentifier(
		\LabkiPackManager\Domain\ContentRepoId $repoId,
		?int $refId,
		?string $ref
	): \LabkiPackManager\Domain\ContentRefId {
		$identifier = $refId ?? $ref;
		$resolvedId = $this->resolveRefId( $repoId, $identifier );

		if ( $resolvedId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-not-found',
				'ref_not_found'
			);
		}

		return $resolvedId;
	}

	/**
	 * Get all installed packs across all repositories and refs.
	 *
	 * @param bool $includePages Whether to include page data
	 * @return array Array of pack data structures
	 */
	private function getAllPacks( bool $includePages ): array {
		$repoRegistry = $this->getRepoRegistry();
		$refRegistry = $this->getRefRegistry();
		$packRegistry = $this->getPackRegistry();
		$pageRegistry = $this->getPageRegistry();

		$allPacks = [];

		// Get all repositories
		$repos = $repoRegistry->listRepos();

		foreach ( $repos as $repo ) {
			// Get all refs for this repo
			$refs = $refRegistry->listRefsForRepo( $repo->id() );

			foreach ( $refs as $ref ) {
				// Get all packs for this ref
				$packs = $packRegistry->listPacksByRef( $ref->id() );

				foreach ( $packs as $pack ) {
					$packData = $this->buildPackData( $pack, $repo, $ref, $pageRegistry, $includePages );
					$allPacks[] = $packData;
				}
			}
		}

		return $allPacks;
	}

	/**
	 * Get all packs for a specific repository.
	 *
	 * @param \LabkiPackManager\Domain\ContentRepoId $repoId Repository ID
	 * @param bool $includePages Whether to include page data
	 * @return array Array of pack data structures
	 */
	private function getPacksForRepo( \LabkiPackManager\Domain\ContentRepoId $repoId, bool $includePages ): array {
		$repoRegistry = $this->getRepoRegistry();
		$refRegistry = $this->getRefRegistry();
		$packRegistry = $this->getPackRegistry();
		$pageRegistry = $this->getPageRegistry();

		// Get the repository
		$repo = $repoRegistry->getRepo( $repoId->toInt() );
		if ( $repo === null ) {
			return [];
		}

		$allPacks = [];

		// Get all refs for this repo
		$refs = $refRegistry->listRefsForRepo( $repoId );

		foreach ( $refs as $ref ) {
			// Get all packs for this ref
			$packs = $packRegistry->listPacksByRef( $ref->id() );

			foreach ( $packs as $pack ) {
				$packData = $this->buildPackData( $pack, $repo, $ref, $pageRegistry, $includePages );
				$allPacks[] = $packData;
			}
		}

		return $allPacks;
	}

	/**
	 * Get all packs for a specific ref.
	 *
	 * @param \LabkiPackManager\Domain\ContentRefId $refId Ref ID
	 * @param bool $includePages Whether to include page data
	 * @return array Array of pack data structures
	 */
	private function getPacksForRef( \LabkiPackManager\Domain\ContentRefId $refId, bool $includePages ): array {
		$repoRegistry = $this->getRepoRegistry();
		$refRegistry = $this->getRefRegistry();
		$packRegistry = $this->getPackRegistry();
		$pageRegistry = $this->getPageRegistry();

		// Get the ref
		$ref = $refRegistry->getRefById( $refId );
		if ( $ref === null ) {
			return [];
		}

		// Get the repository
		$repo = $repoRegistry->getRepo( $ref->contentRepoId()->toInt() );
		if ( $repo === null ) {
			return [];
		}

		$allPacks = [];

		// Get all packs for this ref
		$packs = $packRegistry->listPacksByRef( $refId );

		foreach ( $packs as $pack ) {
			$packData = $this->buildPackData( $pack, $repo, $ref, $pageRegistry, $includePages );
			$allPacks[] = $packData;
		}

		return $allPacks;
	}

	/**
	 * Get a specific pack by pack ID.
	 *
	 * @param int $packId Pack ID
	 * @param bool $includePages Whether to include page data
	 * @return array Array with single pack data structure
	 */
	private function getSinglePackById( int $packId, bool $includePages ): array {
		$packRegistry = $this->getPackRegistry();
		$pageRegistry = $this->getPageRegistry();
		$repoRegistry = $this->getRepoRegistry();
		$refRegistry = $this->getRefRegistry();

		// Get the pack
		$pack = $packRegistry->getPack( $packId );
		if ( $pack === null ) {
			$this->dieWithError(
				'labkipackmanager-error-pack-not-found',
				'pack_not_found'
			);
		}

		// Get the ref and repo for context
		$ref = $refRegistry->getRefById( $pack->contentRefId() );
		$repo = $ref ? $repoRegistry->getRepo( $ref->contentRepoId()->toInt() ) : null;

		// Build and return as array
		return [ $this->buildPackData( $pack, $repo, $ref, $pageRegistry, $includePages ) ];
	}

	/**
	 * Get a specific pack by name.
	 *
	 * @param \LabkiPackManager\Domain\ContentRefId $refId Ref ID
	 * @param string $packName Pack name
	 * @param bool $includePages Whether to include page data
	 * @return array Array with single pack data structure
	 */
	private function getSinglePackByName(
		\LabkiPackManager\Domain\ContentRefId $refId,
		string $packName,
		bool $includePages
	): array {
		$packRegistry = $this->getPackRegistry();
		$pageRegistry = $this->getPageRegistry();
		$repoRegistry = $this->getRepoRegistry();
		$refRegistry = $this->getRefRegistry();

		// Get the pack by name
		$packId = $packRegistry->getPackIdByName( $refId, $packName );
		if ( $packId === null ) {
			$this->dieWithError(
				'labkipackmanager-error-pack-not-found',
				'pack_not_found'
			);
		}

		$pack = $packRegistry->getPack( $packId );
		if ( $pack === null ) {
			$this->dieWithError(
				'labkipackmanager-error-pack-not-found',
				'pack_not_found'
			);
		}

		// Get the ref and repo for context
		$ref = $refRegistry->getRefById( $refId );
		$repo = $ref ? $repoRegistry->getRepo( $ref->contentRepoId()->toInt() ) : null;

		// Build and return as array
		return [ $this->buildPackData( $pack, $repo, $ref, $pageRegistry, $includePages ) ];
	}

	/**
	 * Build complete pack data structure.
	 *
	 * @param \LabkiPackManager\Domain\Pack $pack Pack domain object
	 * @param \LabkiPackManager\Domain\ContentRepo|null $repo Repository domain object
	 * @param \LabkiPackManager\Domain\ContentRef|null $ref Ref domain object
	 * @param \LabkiPackManager\Services\LabkiPageRegistry $pageRegistry Page registry
	 * @param bool $includePages Whether to include page data
	 * @return array Complete pack data structure
	 */
	private function buildPackData(
		\LabkiPackManager\Domain\Pack $pack,
		?\LabkiPackManager\Domain\ContentRepo $repo,
		?\LabkiPackManager\Domain\ContentRef $ref,
		\LabkiPackManager\Services\LabkiPageRegistry $pageRegistry,
		bool $includePages
	): array {
		// Count pages for this pack
		$pageCount = $pageRegistry->countPagesByPack( $pack->id() );

		$data = [
			'pack_id' => $pack->id()->toInt(),
			'content_ref_id' => $pack->contentRefId()->toInt(),
			'name' => $pack->name(),
			'version' => $pack->version(),
			'source_commit' => $pack->sourceCommit(),
			'installed_at' => $pack->installedAt(),
			'installed_by' => $pack->installedBy(),
			'updated_at' => $pack->updatedAt(),
			'status' => $pack->status(),
			'page_count' => $pageCount,
		];

		// Add repo and ref context if available
		if ( $repo !== null ) {
			$data['repo_id'] = $repo->id()->toInt();
			$data['repo_url'] = $repo->url();
		}

		if ( $ref !== null ) {
			$data['ref'] = $ref->sourceRef();
			$data['ref_name'] = $ref->refName();
		}

		// Include pages if requested
		if ( $includePages ) {
			$pages = $pageRegistry->listPagesByPack( $pack->id() );
			$data['pages'] = array_map( [ $this, 'buildPageData' ], $pages );
		}

		return $data;
	}

	/**
	 * Build page data structure.
	 *
	 * @param \LabkiPackManager\Domain\Page $page Page domain object
	 * @return array Page data structure
	 */
	private function buildPageData( \LabkiPackManager\Domain\Page $page ): array {
		return [
			'page_id' => $page->id()->toInt(),
			'pack_id' => $page->packId()->toInt(),
			'name' => $page->name(),
			'final_title' => $page->finalTitle(),
			'page_namespace' => $page->namespace(),
			'wiki_page_id' => $page->wikiPageId(),
			'last_rev_id' => $page->lastRevId(),
			'content_hash' => $page->contentHash(),
			'created_at' => $page->createdAt(),
			'updated_at' => $page->updatedAt(),
		];
	}

	/**
	 * Define allowed parameters for this API endpoint.
	 *
	 * @return array Parameter definitions
	 */
	public function getAllowedParams(): array {
		return [
			'repo_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-repoid',
			],
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-repourl',
			],
			'ref_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-refid',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-ref',
			],
			'pack_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-packid',
			],
			'pack' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-pack',
			],
			'include_pages' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-includepages',
			],
		];
	}

	/**
	 * Provide example queries for API documentation.
	 *
	 * @return array Array of example queries
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiPacksList'
				=> 'apihelp-labkipackslist-example-all',
			'action=labkiPacksList&repo_id=1'
				=> 'apihelp-labkipackslist-example-by-repo-id',
			'action=labkiPacksList&repo_url=https://github.com/Aharoni-Lab/labki-packs'
				=> 'apihelp-labkipackslist-example-by-repo-url',
			'action=labkiPacksList&repo_id=1&ref=main'
				=> 'apihelp-labkipackslist-example-by-ref',
			'action=labkiPacksList&repo_id=1&ref_id=5'
				=> 'apihelp-labkipackslist-example-by-ref-id',
			'action=labkiPacksList&repo_id=1&ref=main&pack=MyPack'
				=> 'apihelp-labkipackslist-example-pack-pages',
			'action=labkiPacksList&pack_id=10'
				=> 'apihelp-labkipackslist-example-pack-by-id',
		];
	}

	/**
	 * Indicate that this API does not require POST requests.
	 *
	 * @return bool False for read-only operation
	 */
	public function mustBePosted(): bool {
		return false; // This is a read-only operation, GET is acceptable
	}

	/**
	 * Indicate that this API does not require write mode.
	 *
	 * @return bool False since this is read-only
	 */
	public function isWriteMode(): bool {
		return false;
	}

	/**
	 * Mark this API as an internal API.
	 *
	 * @return bool True to mark as internal
	 */
	public function isInternal(): bool {
		return true;
	}
}
