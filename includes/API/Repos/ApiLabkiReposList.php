<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Repos;

use Wikimedia\ParamValidator\ParamValidator;

/**
 * API endpoint to list and query content repositories.
 *
 * This endpoint provides comprehensive information about all configured content repositories,
 * including their associated refs (branches/tags), sync status, and metadata.
 *
 * ## Usage Examples
 *
 * List all repositories:
 * ```
 * api.php?action=labkiReposList&format=json
 * ```
 *
 * Get single repository by ID:
 * ```
 * api.php?action=labkiReposList&repo_id=1&format=json
 * ```
 *
 * Get single repository by URL:
 * ```
 * api.php?action=labkiReposList&repo_url=https://github.com/user/repo&format=json
 * ```
 *
 * Note: repo_id and repo_url are mutually exclusive - only provide one.
 *
 * ## Response Structure
 *
 * ```json
 * {
 *   "repos": [
 *     {
 *       "repo_id": 1,
 *       "url": "https://github.com/Aharoni-Lab/labki-packs",
 *       "default_ref": "main",
 *       "bare_path": "/path/to/cache/repo.git",
 *       "refs": [
 *         {
 *           "ref_id": 1,
 *           "ref": "main",
 *           "is_default": true,
 *           "last_commit": "abc123",
 *           "manifest_name": "Labki Base Packs",
 *           "worktree_path": "/path/to/worktree",
 *           "last_synced": "20251024120000"
 *         }
 *       ],
 *       "last_synced": "20251024120000",
 *       "created_at": "20251001000000"
 *     }
 *   ],
 *   "_meta": {
 *     "schemaVersion": 1,
 *     "timestamp": "20251024120000"
 *   }
 * }
 * ```
 *
 * ## Implementation Notes
 *
 * - Uses `LabkiRepoRegistry` for repository data
 * - Uses `LabkiRefRegistry` for ref data
 * - Joins repo and ref data efficiently
 * - Computes `last_synced` from most recent ref sync
 * - Returns empty array if no repositories configured
 * - Supports filtering by single repo_id
 *
 * @ingroup API
 */

class ApiLabkiReposList extends RepoApiBase {

	/**
	 * Constructor.
	 *
	 * @param \ApiMain $main Main API object
	 * @param string $name Module name
	 */
	public function __construct( \ApiMain $main, string $name ) {
		wfDebugLog( 'labkipack', "ApiLabkiReposList::__construct() called with name={$name}" );
		parent::__construct( $main, $name );
	}

	/**
	 * Execute the API request.
	 *
	 * Main entry point for the API. Handles:
	 * - Listing all repositories (no parameters)
	 * - Fetching a single repository by ID (repo_id parameter)
	 * - Fetching a single repository by URL (repo_url parameter)
	 *
	 * Note: repo_id and repo_url are mutually exclusive.
	 */
	public function execute(): void {
		wfDebugLog( 'labkipack', "ApiLabkiReposList::execute() started" );
		
		// Get parameters
		$repoId = $this->getParameter( 'repo_id' );
		$repoUrl = $this->getParameter( 'repo_url' );
		
		wfDebugLog( 'labkipack', "ApiLabkiReposList::execute() repoId={$repoId}, repoUrl={$repoUrl}" );

		// Validate: only one identifier should be provided
		if ( $repoId !== null && $repoUrl !== null ) {
			$this->dieWithError(
				'labkipackmanager-error-repo-multiple-identifiers',
				'multiple_identifiers'
			);
		}

		// Fetch repository data
		if ( $repoId !== null ) {
			wfDebugLog( 'labkipack', "ApiLabkiReposList::execute() fetching single repo by ID" );
			$repos = $this->getSingleRepo( $repoId );
		} elseif ( $repoUrl !== null ) {
			wfDebugLog( 'labkipack', "ApiLabkiReposList::execute() fetching single repo by URL" );
			$normalizedUrl = $this->validateAndNormalizeUrl( $repoUrl );
			$repos = $this->getSingleRepo( $normalizedUrl );
		} else {
			wfDebugLog( 'labkipack', "ApiLabkiReposList::execute() fetching all repos" );
			$repos = $this->getAllRepos();
		}

		wfDebugLog( 'labkipack', "ApiLabkiReposList::execute() found " . count( $repos ) . " repos" );

		// Build response
		$result = $this->getResult();
		$result->addValue( null, 'repos', $repos );
		$result->addValue( null, '_meta', [
			'schemaVersion' => 1,
			'timestamp' => wfTimestampNow(),
		] );
		
		wfDebugLog( 'labkipack', "ApiLabkiReposList::execute() completed successfully" );
	}

	/**
	 * Get all repositories with their refs.
	 *
	 * Fetches all repositories from the database and enriches each with:
	 * - Associated refs (branches/tags)
	 * - Computed last_synced timestamp
	 * - Metadata from registries
	 *
	 * @return array Array of repository data structures
	 */
	private function getAllRepos(): array {
		$repoRegistry = $this->getRepoRegistry();
		$refRegistry = $this->getRefRegistry();

		// Get all repositories
		$repos = $repoRegistry->listRepos();
		$result = [];

		foreach ( $repos as $repo ) {
			$repoData = $this->buildRepoData( $repo, $refRegistry );
			$result[] = $repoData;
		}

		return $result;
	}

	/**
	 * Get a single repository by ID or URL.
	 *
	 * @param int|string $identifier Repository ID or URL to fetch
	 * @return array Array containing single repository data, or empty array if not found
	 */
	private function getSingleRepo( int|string $identifier ): array {
		$repoRegistry = $this->getRepoRegistry();
		$refRegistry = $this->getRefRegistry();

		// Get repository (works with int ID or string URL)
		$repo = $repoRegistry->getRepo( $identifier );
		if ( $repo === null ) {
			wfDebugLog( 'labkipack', "Repository with identifier '{$identifier}' not found" );
			return [];
		}

		$repoData = $this->buildRepoData( $repo, $refRegistry );
		return [ $repoData ];
	}

	/**
	 * Build complete repository data structure.
	 *
	 * Constructs a comprehensive data structure for a repository including:
	 * - Basic repository information (ID, URL, paths)
	 * - All associated refs with their metadata
	 * - Computed timestamps (last_synced from most recent ref)
	 *
	 * @param \LabkiPackManager\Domain\ContentRepo $repo Repository domain object
	 * @param \LabkiPackManager\Services\LabkiRefRegistry $refRegistry Ref registry for fetching refs
	 * @return array Complete repository data structure
	 */
	private function buildRepoData(
		\LabkiPackManager\Domain\ContentRepo $repo,
		\LabkiPackManager\Services\LabkiRefRegistry $refRegistry
	): array {
		$repoId = $repo->id();

		// Get all refs for this repository
		$refs = $refRegistry->listRefsForRepo( $repoId );
		$refsData = [];
		$mostRecentSync = null;

		foreach ( $refs as $ref ) {
			$refData = [
				'ref_id' => $ref->id()->toInt(),
				'ref' => $ref->sourceRef(),
				'ref_name' => $ref->refName(),
				'is_default' => ( $ref->sourceRef() === $repo->defaultRef() ),
				'last_commit' => $ref->lastCommit(),
				'manifest_hash' => $ref->manifestHash(),
				'manifest_last_parsed' => $ref->manifestLastParsed(),
				'worktree_path' => $ref->worktreePath(),
				'created_at' => $ref->createdAt(),
				'updated_at' => $ref->updatedAt(),
			];

			$refsData[] = $refData;

			// Track most recent sync time across all refs
			if ( $ref->updatedAt() !== null ) {
				$refTimestamp = \wfTimestamp( TS_UNIX, $ref->updatedAt() );
				$mostRecentTimestamp = $mostRecentSync !== null ? \wfTimestamp( TS_UNIX, $mostRecentSync ) : null;
				if ( $mostRecentTimestamp === null || $refTimestamp > $mostRecentTimestamp ) {
					$mostRecentSync = $ref->updatedAt();
				}
			}
		}

		// Build final repository data structure
		return [
			'repo_id' => $repoId->toInt(),
			'url' => $repo->url(),
			'default_ref' => $repo->defaultRef(),
			'bare_path' => $repo->barePath(),
			'last_fetched' => $repo->lastFetched(),
			'refs' => $refsData,
			'ref_count' => count( $refsData ),
			'last_synced' => $mostRecentSync ?? $repo->lastFetched(),
			'created_at' => $repo->createdAt(),
			'updated_at' => $repo->updatedAt(),
		];
	}

	/**
	 * Define allowed parameters for this API endpoint.
	 *
	 * Specifies all parameters that can be passed to this API, including:
	 * - Parameter types
	 * - Required vs optional
	 * - Default values
	 * - Validation rules
	 *
	 * @return array Parameter definitions
	 */
	public function getAllowedParams(): array {
		return [
			'repo_id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-list-param-repoid',
			],
			'repo_url' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_HELP_MSG => 'labkipackmanager-api-repos-list-param-repourl',
			],
		];
	}

	/**
	 * Provide example queries for API documentation.
	 *
	 * These examples appear in the auto-generated API documentation
	 * and help users understand how to use this endpoint.
	 *
	 * @return array Array of example queries
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=labkiReposList'
				=> 'apihelp-labkireposlist-example-all',
			'action=labkiReposList&repo_id=1'
				=> 'apihelp-labkireposlist-example-by-id',
			'action=labkiReposList&repo_url=https://github.com/Aharoni-Lab/labki-packs'
				=> 'apihelp-labkireposlist-example-by-url',
		];
	}

	/**
	 * Indicate that this API requires POST requests.
	 *
	 * While this is a read operation, MediaWiki Action API convention
	 * is to use POST for consistency and to avoid caching issues.
	 *
	 * @return bool True to require POST
	 */
	public function mustBePosted(): bool {
		return false; // This is a read-only operation, GET is acceptable
	}

	/**
	 * Indicate that this API does not require write mode.
	 *
	 * Read-only operations don't need write mode, which allows them
	 * to work even when the wiki is in read-only mode.
	 *
	 * @return bool False since this is read-only
	 */
	public function isWriteMode(): bool {
		return false;
	}

	/**
	 * Mark this API as an internal API.
	 *
	 * Internal APIs are not exposed in the public API documentation
	 * and are intended for use by the extension's own frontend.
	 *
	 * @return bool True to mark as internal
	 */
	public function isInternal(): bool {
		return true;
	}
}
