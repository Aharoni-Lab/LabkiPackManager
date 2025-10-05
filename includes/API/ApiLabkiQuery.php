<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\PackId;

/**
 * Action API module for hierarchical, read-only queries across Labki content.
 *
 * This API retrieves structured information about repositories, packs, and pages
 * that have been installed or registered within the Labki system.
 *
 * Supported hierarchy:
 *   1. No parameters → list all repositories.
 *   2. repo=<repo> → return repository metadata and all packs within it.
 *   3. repo=<repo>&pack=<pack> → return pack metadata and all pages in that pack.
 *   4. repo=<repo>&pack=<pack>&page=<page> → return metadata for that page.
 *
 * Example queries:
 *   - api.php?action=labkiquery&format=json
 *   - api.php?action=labkiquery&repo=https://github.com/Aharoni-Lab/labki-packs
 *   - api.php?action=labkiquery&repo=myrepo&pack=lab-operations
 *   - api.php?action=labkiquery&repo=myrepo&pack=lab-operations&page=Safety_Protocol
 *
 * Example result:
 * {
 *   "labkiquery": {
 *     "repo": { "repo_id": 1, "repo_url": "https://github.com/Aharoni-Lab/labki-packs" },
 *     "packs": [
 *       { "pack_id": 10, "name": "lab-operations", "version": "1.0.2" }
 *     ],
 *     "_meta": { "schemaVersion": 1, "timestamp": "20251004..." }
 *   }
 * }
 *
 * @ingroup API
 */
final class ApiLabkiQuery extends ApiBase {

    /**
     * @param ApiMain $main The main API module
     * @param string $name The name of this module (action name)
     */
    public function __construct( ApiMain $main, string $name ) {
        parent::__construct( $main, $name );
    }

    /**
     * Executes the hierarchical read-only query.
     *
     * Query flow:
     *   - If no repo is specified → list all repositories.
     *   - If repo is specified → show repo metadata and all packs in that repo.
     *   - If repo + pack → show pack metadata and all pages in that pack.
     *   - If repo + pack + page → show detailed info for that page.
     *
     * @return void
     */
    public function execute(): void {
        $params = $this->extractRequestParams();
        $repoParam = $params['repo'] ?? null;
        $packParam = $params['pack'] ?? null;
        $pageParam = $params['page'] ?? null;

        $repoReg = new LabkiRepoRegistry();
        $packReg = new LabkiPackRegistry();
        $pageReg = new LabkiPageRegistry();

        $result = [];

        // Case 1: No repo → list all repositories
        if ( !$repoParam ) {
            $repos = $repoReg->listRepos();
            $result['repos'] = $this->mapToArray( $repos );

        } else {
            // Resolve repo ID (integer or URL)
            $repoId = ctype_digit( $repoParam )
                ? (int)$repoParam
                : $repoReg->getRepoIdByUrl( $repoParam );

            if ( $repoId === null ) {
                $this->dieWithError( [ 'apierror-repo-not-found', $repoParam ], 'repo_not_found' );
            }

            // Always include repo metadata
            $repoInfo = $repoReg->getRepoInfo( $repoId );
            $result['repo'] = $repoInfo && method_exists( $repoInfo, 'toArray' )
                ? $repoInfo->toArray()
                : [ 'repo_id' => ($repoId instanceof ContentRepoId ? $repoId->toInt() : (int)$repoId) ];

            // Case 2: Repo only → list packs in repo
            if ( !$packParam ) {
                $packs = $packReg->listPacksByRepo( $repoId ) ?? [];
                $result['packs'] = $this->mapToArray( $packs );

            } else {
                // Case 3: Specific pack (and maybe page)
                $packId = $packReg->getPackIdByName( $repoId, $packParam, null );
                if ( $packId === null ) {
                    $this->dieWithError( [ 'apierror-pack-not-found', $packParam ], 'pack_not_found' );
                }

                // Always include pack metadata
                $packInfo = $packReg->getPackInfo( $packId );
                $result['pack'] = $packInfo && method_exists( $packInfo, 'toArray' )
                    ? $packInfo->toArray()
                    : [ 'pack_id' => ($packId instanceof PackId ? $packId->toInt() : (int)$packId) ];

                // Case 3a: Pack only → list pages
                if ( !$pageParam ) {
                    $pages = $pageReg->listPagesByPack( $packId ) ?? [];
                    $result['pages'] = $this->mapToArray( $pages );

                } else {
                    // Case 4: Specific page
                    $pageInfo = $pageReg->getPageByName( $packId, $pageParam );
                    if ( $pageInfo === null ) {
                        $this->dieWithError( [ 'apierror-page-not-found', $pageParam ], 'page_not_found' );
                    }
                    $result['page'] = method_exists( $pageInfo, 'toArray' ) ? $pageInfo->toArray() : (array)$pageInfo;
                }
            }
        }

        // Metadata block for schema tracking and caching
        //TODO:
        // Layer Schema             location	            Validation timing
        // Content packs	labki-packs-tools/schema/*.yml	During CI and import
        // API responses	labki-pack-manager/schema/api/	During API test runs (PHPUnit or CI)
        $result['_meta'] = [
            'schemaVersion' => 1,
            'timestamp' => wfTimestampNow()
        ];

        $this->getResult()->addValue( null, $this->getModuleName(), $result );
    }

    /**
     * Normalize a list of domain objects or arrays into plain arrays for API output.
     * @param iterable $items
     * @return array<int,array>
     */
    private function mapToArray( iterable $items ): array {
        return array_map(
            static function ( $obj ) {
                return is_object( $obj ) && method_exists( $obj, 'toArray' ) ? $obj->toArray() : (array)$obj;
            },
            is_array( $items ) ? $items : iterator_to_array( $items, false )
        );
    }

    /**
     * Defines the supported query parameters.
     *
     * @return array<string, array>
     */
    public function getAllowedParams(): array {
        return [
            'repo' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'pack' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'page' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
        ];
    }

    /**
     * Marks this API as external (accessible via action=labkiquery).
     *
     * @return bool Always false to allow public access.
     */
    public function isInternal(): bool {
        return false;
    }
}
