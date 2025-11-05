<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use Wikimedia\ParamValidator\ParamValidator;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Domain\PackId;


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
 * Get packs for a specific repository by URL:
 * ```
 * api.php?action=labkiPacksList&repo_url=https://github.com/user/repo&format=json
 * ```
 *
 * Get packs for a specific ref (by name):
 * ```
 * api.php?action=labkiPacksList&repo_url=https://github.com/user/repo&ref=main&format=json
 * ```
 *
 * Get pages for a specific pack (by name):
 * ```
 * api.php?action=labkiPacksList&repo_url=https://github.com/user/repo&ref=main&pack=MyPack&format=json
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
 * - Supports only URL and name lookups for repo, ref, and pack
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
		parent::__construct( $main, $name );
		$this->refRegistry = new LabkiRefRegistry();
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->pageRegistry = new LabkiPageRegistry();
		$this->packRegistry = new LabkiPackRegistry();
	}

	private LabkiRefRegistry $refRegistry;
	private LabkiRepoRegistry $repoRegistry;
	private LabkiPageRegistry $pageRegistry;
	private LabkiPackRegistry $packRegistry;

	/**
	 * Execute the API request.
	 *
	 * Main entry point for the API. Handles:
	 * - Listing all packs (no parameters)
	 * - Listing packs for a repository (repo_url)
	 * - Listing packs for a specific ref (repo_url + ref)
	 * - Listing a specific pack (repo_url + ref + pack)
	 *
	 * All modes return consistent structure: { packs: [...] }
	 * Use include_pages=true to nest page data within each pack.
	 */
	public function execute(): void {

		// TODO: Should we require manage permission????
		//$this->requireManagePermission();

		$params = $this->extractRequestParams();

		// Get parameters
		$repoUrl = $params['repo_url'];
		$ref = $params['ref'];
		$pack = $params['pack'];
		$includePages = $params['include_pages'];

		// Validate: if ref is specified, repo must be specified
		if ( $ref && !$repoUrl ) {
			$this->dieWithError(
				'labkipackmanager-error-ref-requires-repo',
				'missing_repo'
			);
		}

		// Validate: if pack is specified, ref must be specified
		if ( $pack && !$ref ) {
			$this->dieWithError(
				'labkipackmanager-error-pack-requires-ref',
				'missing_ref'
			);
		}

		// Fetch packs based on provided parameters	
		$packs = $this->getPacks( $repoUrl, $ref, $pack, $includePages );

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
	}

	/**
	 * Consolidated method to fetch packs based on provided filters.
	 *
	 * Handles four query modes based on parameters:
	 * - All null: Get all packs across all repos and refs
	 * - Only $repoId: Get all packs for a specific repository
	 * - $repoId and $refId: Get all packs for a specific ref
	 * - $refId and $pack: Get a single pack by name
	 *
	 * @param string|null $repoUrl Repository URL (optional)
	 * @param string|null $ref Ref name (optional)
	 * @param string|null $pack Pack name (optional)
	 * @param bool $includePages Whether to include page data
	 * @return array Array of pack data structures
	 */
	private function getPacks(
		?string $repoUrl,
		?string $ref,
		?string $pack,
		bool $includePages
	): array {

		$repoId = $repoUrl ? $this->repoRegistry->getRepoId( $repoUrl ) : null;
		$refId = $ref ? $this->refRegistry->getRefIdByRepoAndRef( $repoUrl, $ref ) : null;
		$packId = $pack ? $this->packRegistry->getPackIdByName( $refId, $pack ) : null;

		
		
		if ( $packId ) {
			// Mode 4: Get specific pack by name
			return [ $this->buildPackData( $packId, $includePages ) ];
		}
		if ( $refId ) {
		// Mode 3: Get packs for a specific ref	
			$allPacks = [];
			$packs = $this->packRegistry->listPacksByRef( $refId );

			foreach ( $packs as $pack ) {
				$packData = $this->buildPackData( $pack->id(), $includePages );
				$allPacks[] = $packData;
			}

			return $allPacks;
		}

		// Mode 2: Get packs for a specific repository
		if ( $repoId ) {
			$repo = $this->repoRegistry->getRepo( $repoId->toInt() );
			if ( $repo === null ) {
				return [];
			}

			$allPacks = [];
			$refs = $this->refRegistry->listRefsForRepo( $repoId );

			foreach ( $refs as $ref ) {
				$packs = $this->packRegistry->listPacksByRef( $ref->id() );

				foreach ( $packs as $pack ) {
					$packData = $this->buildPackData( $pack->id(), $includePages );
					$allPacks[] = $packData;
				}
			}

			return $allPacks;
		}

		// Mode 1: Get all packs
		$allPacks = [];
		$repos = $this->repoRegistry->listRepos();

		foreach ( $repos as $repo ) {
			$refs = $this->refRegistry->listRefsForRepo( $repo->id() );

			foreach ( $refs as $ref ) {
				$packs = $this->packRegistry->listPacksByRef( $ref->id() );

				foreach ( $packs as $pack ) {
					$packData = $this->buildPackData( $pack->id(), $includePages );
					$allPacks[] = $packData;
				}
			}
		}

		return $allPacks;
	}


	/**
	 * Build complete pack data structure.
	 *
	 * @param PackId $packId Pack ID
	 * @param bool $includePages Whether to include page data
	 * @return array Complete pack data structure
	 */
	private function buildPackData(
		PackId $packId,
		bool $includePages
	): array {

		// Use class registry properties instead of creating new instances
		$pack = $this->packRegistry->getPack( $packId );
		
		$ref = $this->refRegistry->getRefById( $pack->contentRefId() );
		$refName = $ref->sourceRef();

		$repo = $this->repoRegistry->getRepo( $ref->repoId() );
		$repoUrl = $repo->url();

		$pageCount = $this->pageRegistry->countPagesByPack( $packId );
		
		$data = [
			'repo_url' => $repoUrl,
			'ref' => $refName,
			'name' => $pack->name(),
			'version' => $pack->version(),
			'source_commit' => $pack->sourceCommit(),
			'installed_at' => $pack->installedAt(),
			'installed_by' => $pack->installedBy(),
			'updated_at' => $pack->updatedAt(),
			'status' => $pack->status(),
			'page_count' => $pageCount,
		];

		// Include pages if requested
		if ( $includePages ) {
			$pages = $this->pageRegistry->listPagesByPack( $packId );
			$data['pages'] = array_map( function ( $page ) {
				return [
					'name' => $page->name(),
					'final_title' => $page->finalTitle(),
					'page_namespace' => $page->namespace(),
					'wiki_page_id' => $page->wikiPageId(),
					'last_rev_id' => $page->lastRevId(),
					'content_hash' => $page->contentHash(),
					'created_at' => $page->createdAt(),
					'updated_at' => $page->updatedAt(),
				];
			}, $pages );
		}

		return $data;
	}

	/**
	 * Define allowed parameters for this API endpoint.
	 *
	 * @return array Parameter definitions
	 */
	public function getAllowedParams(): array {
		return [
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-repourl',
			],
			'ref' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-ref',
			],
			'pack' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-packs-list-param-pack',
			],
			'include_pages' => [
				ParamValidator::PARAM_TYPE => 'boolean',
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
			'action=labkiPacksList&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main'
				=> 'apihelp-labkipackslist-example-by-ref',
			'action=labkiPacksList&repo_url=https://github.com/Aharoni-Lab/labki-packs&ref=main&pack=MyPack'
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
