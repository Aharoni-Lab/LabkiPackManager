<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;

final class ApiLabkiUpdate extends ApiBase {
    public function __construct( ApiMain $main, string $name ) {
        parent::__construct( $main, $name );
    }

    public function execute(): void {
        $params = $this->extractRequestParams();
        $action = isset( $params['actionType'] ) && is_string( $params['actionType'] ) ? (string)$params['actionType'] : null;

        $repoReg = new LabkiRepoRegistry();
        $packReg = new LabkiPackRegistry();
        $pageReg = new LabkiPageRegistry();

        $result = [ 'success' => false, 'action' => $action, '_meta' => [ 'schemaVersion' => 1 ] ];

        // Permission gate
        if ( !$this->getAuthority()->isAllowed( 'labkipackmanager-manage' ) ) {
            $this->dieWithError( 'permissiondenied' );
        }

        switch ( $action ) {
            case 'installPack':
                // Integration point: BEFORE persisting state, ensure MW pages are created/updated
                // 1) For each page in $pages, create/update the wiki page (Title/WikiPage/EditPage logic)
                // 2) Only when those actions succeed, persist rows below
                $contentRepoUrl = (string)( $params['contentRepoUrl'] ?? '' );
                $packName = (string)( $params['packName'] ?? '' );
                $version = isset( $params['version'] ) ? (string)$params['version'] : null;
                $pagesParam = $params['pages'] ?? null; // JSON string or array
                $pages = is_string( $pagesParam ) ? json_decode( $pagesParam, true ) : ( ( is_array( $pagesParam ) ) ? $pagesParam : [] );

                if ( $contentRepoUrl === '' || $packName === '' ) {
                    $this->dieWithError( 'apierror-missing-param' );
                }

                $repoId = $repoReg->ensureRepo( $contentRepoUrl );
                $packIdObj = $packReg->registerPack( $repoId, $packName, $version, $this->getUser()->getId() );
                $packId = $packIdObj instanceof PackId ? $packIdObj->toInt() : (int)$packIdObj;

                // Persist state: Replace pages with provided list (idempotent install state)
                $pageReg->removePagesByPack( $packId );
                $added = 0;
                foreach ( $pages as $p ) {
                    if ( !is_array( $p ) ) { continue; }
                    $name = (string)($p['name'] ?? '');
                    $finalTitle = (string)($p['finalTitle'] ?? '');
                    $ns = (int)($p['pageNamespace'] ?? 0);
                    $wikiPageId = (int)($p['wikiPageId'] ?? 0);
                    if ( $name === '' || $finalTitle === '' ) { continue; }
                    $pageReg->recordInstalledPage( $packId, $name, $finalTitle, $ns, $wikiPageId );
                    $added++;
                }

                $result['success'] = true;
                $result['packId'] = $packId;
                $result['pagesAdded'] = $added;
                break;

            case 'removePack':
                // Integration point: BEFORE removing from registry, delete wiki pages for this pack
                // 1) Iterate existing pages for $packId and delete from core (WikiPage::doDeleteArticle)
                // 2) Only when deletions succeed, remove registry rows below
                // Remove pack by repo+pack(+version) or by packId; optionally verify page list
                $packId = (int)( $params['packId'] ?? 0 );
                $contentRepoUrl = (string)( $params['contentRepoUrl'] ?? '' );
                $packName = (string)( $params['packName'] ?? '' );
                $version = isset( $params['version'] ) ? (string)$params['version'] : null;
                $pagesParam = $params['pages'] ?? null; // optional, for verification
                $pages = is_string( $pagesParam ) ? json_decode( $pagesParam, true ) : ( ( is_array( $pagesParam ) ) ? $pagesParam : [] );

                if ( $packId <= 0 ) {
                    if ( $contentRepoUrl === '' || $packName === '' ) {
                        $this->dieWithError( 'apierror-missing-param' );
                    }
                    $repoId = $repoReg->ensureRepo( $contentRepoUrl );
                    $packIdObj = $packReg->getPackIdByName( $repoId, $packName, $version );
                    if ( $packIdObj === null ) {
                        $this->dieWithError( [ 'apierror-pack-not-found', $packName ], 'pack_not_found' );
                    }
                    $packId = $packIdObj->toInt();
                }

                // Persist state: Remove all pages first
                $existingPages = $pageReg->listPagesByPack( $packId );
                $pageReg->removePagesByPack( $packId );

                // Verification: compare provided pages (if any)
                $result['verify'] = [ 'providedCount' => is_array( $pages ) ? count( $pages ) : 0, 'removedCount' => is_array( $existingPages ) ? count( $existingPages ) : 0 ];

                // Remove pack
                $packReg->removePack( $packId );
                $result['success'] = true;
                break;

            case 'recordPageInstall':
                // Deprecated: single page install not supported; use installPack
                $this->dieWithError( 'apierror-unsupported', 'unsupported' );
                break;

            case 'removePage':
                // Deprecated: single page removal not supported; use removePack
                $this->dieWithError( 'apierror-unsupported', 'unsupported' );
                break;

            case 'updatePack':
                // Integration point: BEFORE updating registry, update MW pages (rename/move/edit)
                // 1) Diff old vs new pages; create/rename/delete as needed in core
                // 2) Only when those actions succeed, update registry below
                // Update pack metadata (e.g., version) and replace its pages with provided list
                $contentRepoUrl = (string)( $params['contentRepoUrl'] ?? '' );
                $packName = (string)( $params['packName'] ?? '' );
                $currentVersion = isset( $params['version'] ) ? (string)$params['version'] : null;
                $newVersion = isset( $params['newVersion'] ) ? (string)$params['newVersion'] : null;
                $pagesParam = $params['pages'] ?? null; // JSON or array
                $pages = is_string( $pagesParam ) ? json_decode( $pagesParam, true ) : ( ( is_array( $pagesParam ) ) ? $pagesParam : [] );
                if ( $contentRepoUrl === '' || $packName === '' ) {
                    $this->dieWithError( 'apierror-missing-param' );
                }
                $repoId = $repoReg->ensureRepo( $contentRepoUrl );
                $packIdObj = $packReg->getPackIdByName( $repoId, $packName, $currentVersion );
                if ( $packIdObj === null ) {
                    $this->dieWithError( [ 'apierror-pack-not-found', $packName ], 'pack_not_found' );
                }
                $packId = $packIdObj instanceof PackId ? $packIdObj->toInt() : (int)$packIdObj;
                if ( $newVersion !== null && $newVersion !== '' ) {
                    $packReg->updatePack( $packId, [ 'version' => $newVersion ] );
                } else {
                    $packReg->updatePack( $packId, [] ); // touch updated_at
                }
                // Replace pages
                $pageReg->removePagesByPack( $packId );
                $added = 0;
                foreach ( $pages as $p ) {
                    if ( !is_array( $p ) ) { continue; }
                    $name = (string)($p['name'] ?? '');
                    $finalTitle = (string)($p['finalTitle'] ?? '');
                    $ns = (int)($p['pageNamespace'] ?? 0);
                    $wikiPageId = (int)($p['wikiPageId'] ?? 0);
                    if ( $name === '' || $finalTitle === '' ) { continue; }
                    $pageReg->recordInstalledPage( $packId, $name, $finalTitle, $ns, $wikiPageId );
                    $added++;
                }
                $result['success'] = true;
                $result['packId'] = $packId;
                $result['pagesAdded'] = $added;
                break;

            default:
                $result['error'] = 'unknown_action';
        }

        $this->getResult()->addValue( null, $this->getModuleName(), $result );
    }

    public function getAllowedParams(): array {
        return [
            'actionType'    => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'contentRepoUrl'       => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'packName'      => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'version'       => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'newVersion'    => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'packId'        => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => null ],
            'name'          => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'finalTitle'    => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'pageNamespace' => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 0 ],
            'wikiPageId'    => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 0 ],
            // removePage convenience
            'pageId'        => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => null ],
            // Bulk pages for install/update/remove verification
            'pages'         => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
        ];
    }
}


