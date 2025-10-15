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
use MediaWiki\MediaWikiServices;
use WikiPage;
use WikitextContent;
use Title;

final class ApiLabkiUpdate extends ApiBase {

    public function __construct( ApiMain $main, string $name ) {
        parent::__construct( $main, $name );
    }

    public function execute(): void {
        $params = $this->extractRequestParams();
        $action = isset( $params['actionType'] ) && is_string( $params['actionType'] )
            ? (string)$params['actionType']
            : null;

        // Permission gate
        if ( !$this->getAuthority()->isAllowed( 'labkipackmanager-manage' ) ) {
            $this->dieWithError( 'permissiondenied' );
        }

        $result = [
            'success' => false,
            'action' => $action,
            '_meta' => [ 'schemaVersion' => 1 ]
        ];

        switch ( $action ) {
            case 'importPack':
                $result = $this->handleImportPack();
                break;
            case 'updatePack':
                $result = $this->handleUpdatePack();
                break;
            case 'removePack':
                $result = $this->handleRemovePack();
                break;
            default:
                $this->dieWithError( 'apierror-unknown-action' );
        }

        $this->getResult()->addValue( null, $this->getModuleName(), $result );
    }

    /* -------------------------------------------------------------------------
     * INSTALL PACK
     * ------------------------------------------------------------------------- */
    public function handleImportPack(): array {
        $params = $this->extractRequestParams();
        $repoUrl = (string)( $params['contentRepoUrl'] ?? '' );
        $packsJson = $params['packs'] ?? '[]';
        $packs = json_decode( $packsJson, true ) ?: [];
    
        if ( $repoUrl === '' ) {
            $this->dieWithError( 'apierror-missing-param' );
        }
    
        $repoReg = new LabkiRepoRegistry();
        $packReg = new LabkiPackRegistry();
        $pageReg = new LabkiPageRegistry();
    
        $repoId = $repoReg->ensureRepo( $repoUrl );
    
        // Build global rewrite mapping across both existing and new pages
        $rewriteMap = $this->buildRewriteMap( $repoUrl, $packs );
        
        // Get all manifest pages once for all pack imports - with error handling
        $manifestPages = [];
        try {
            $manifestPages = $this->getManifestPages( $repoUrl );
        } catch ( Exception $e ) {
            wfDebugLog( 'labkipack', 'Failed to get manifest pages: ' . $e->getMessage() );
            // Continue without manifest pages - the import will still work
        }
    
        $imported = [];
        foreach ( $packs as $pack ) {
            $packName = $pack['name'] ?? '';
            $version  = $pack['version'] ?? '';
            if ( $packName === '' ) {
                continue;
            }
    
            // --- Create or update MediaWiki pages ---
            $createdPages = $this->importPackPages( $repoUrl, $pack, $rewriteMap, $manifestPages );
    
            // --- Register pack + pages in DB ---
            $packIdObj = $packReg->registerPack( $repoId, $packName, $version, $this->getUser()->getId() );
            $packId = $packIdObj->toInt();
    
            // Replace previous pages for this pack
            $pageReg->removePagesByPack( $packId );
            foreach ( $createdPages as $p ) {
                $pageReg->recordInstalledPage(
                    $packId,
                    $p['name'],
                    $p['finalTitle'],
                    $p['pageNamespace'],
                    $p['wikiPageId']
                );
            }
    
            $imported[] = [
                'pack' => $packName,
                'pages' => count( $createdPages )
            ];
        }
    
        return [
            'success' => true,
            'action' => 'installPack',
            'imported' => $imported,
            'rewriteEntries' => count( $rewriteMap ),
            '_meta' => [ 'schemaVersion' => 1 ]
        ];
    }    

    /* -------------------------------------------------------------------------
     * UPDATE PACK
     * ------------------------------------------------------------------------- */
    private function handleUpdatePack(): array {
        $params = $this->extractRequestParams();
        $contentRepoUrl = (string)( $params['contentRepoUrl'] ?? '' );
        $packName = (string)( $params['packName'] ?? '' );
        $currentVersion = isset( $params['version'] ) ? (string)$params['version'] : null;
        $newVersion = isset( $params['newVersion'] ) ? (string)$params['newVersion'] : null;
        $pagesParam = $params['pages'] ?? null;
        $pages = is_string( $pagesParam )
            ? json_decode( $pagesParam, true )
            : ( is_array( $pagesParam ) ? $pagesParam : [] );

        if ( $contentRepoUrl === '' || $packName === '' ) {
            $this->dieWithError( 'apierror-missing-param' );
        }

        $repoReg = new LabkiRepoRegistry();
        $packReg = new LabkiPackRegistry();
        $pageReg = new LabkiPageRegistry();

        $repoId = $repoReg->ensureRepo( $contentRepoUrl );
        $packIdObj = $packReg->getPackIdByName( $repoId, $packName, null );
        if ( $packIdObj === null ) {
            $this->dieWithError( [ 'apierror-pack-not-found', $packName ], 'pack_not_found' );
        }
        $packId = $packIdObj instanceof PackId ? $packIdObj->toInt() : (int)$packIdObj;

        if ( $newVersion !== null && $newVersion !== '' ) {
            $packReg->updatePack( $packId, [ 'version' => $newVersion ] );
        } else {
            $packReg->updatePack( $packId, [] );
        }

        // Replace pages
        $pageReg->removePagesByPack( $packId );
        $added = 0;
        foreach ( $pages as $p ) {
            if ( !is_array( $p ) ) {
                continue;
            }
            $name = (string)( $p['name'] ?? '' );
            $finalTitle = (string)( $p['finalTitle'] ?? '' );
            $ns = (int)( $p['pageNamespace'] ?? 0 );
            $wikiPageId = (int)( $p['wikiPageId'] ?? 0 );
            if ( $name === '' || $finalTitle === '' ) {
                continue;
            }
            $pageReg->recordInstalledPage( $packId, $name, $finalTitle, $ns, $wikiPageId );
            $added++;
        }

        return [
            'success' => true,
            'action' => 'updatePack',
            'packId' => $packId,
            'pagesAdded' => $added,
            '_meta' => [ 'schemaVersion' => 1 ]
        ];
    }

    /* -------------------------------------------------------------------------
     * REMOVE PACK
     * ------------------------------------------------------------------------- */
    private function handleRemovePack(): array {
        $params = $this->extractRequestParams();
        $packId = (int)( $params['packId'] ?? 0 );
        $contentRepoUrl = (string)( $params['contentRepoUrl'] ?? '' );
        $packName = (string)( $params['packName'] ?? '' );
        $version = isset( $params['version'] ) ? (string)$params['version'] : null;
        $pagesParam = $params['pages'] ?? null;
        $pages = is_string( $pagesParam )
            ? json_decode( $pagesParam, true )
            : ( is_array( $pagesParam ) ? $pagesParam : [] );

        $repoReg = new LabkiRepoRegistry();
        $packReg = new LabkiPackRegistry();
        $pageReg = new LabkiPageRegistry();

        if ( $packId <= 0 ) {
            if ( $contentRepoUrl === '' || $packName === '' ) {
                $this->dieWithError( 'apierror-missing-param' );
            }
            $repoId = $repoReg->ensureRepo( $contentRepoUrl );
            $packIdObj = $packReg->getPackIdByName( $repoId, $packName, null );
            if ( $packIdObj === null ) {
                $this->dieWithError( [ 'apierror-pack-not-found', $packName ], 'pack_not_found' );
            }
            $packId = $packIdObj->toInt();
        }

        // Remove wiki pages
        $existingPages = $pageReg->listPagesByPack( $packId );
        foreach ( $existingPages as $page ) {
            $title = Title::newFromText( $page->getFinalTitle() );
            if ( $title && $title->exists() ) {
                $wikiPage = WikiPage::factory( $title );
                $wikiPage->doDeleteArticle( 'Removed via LabkiPackManager' );
            }
        }

        // Remove from registry
        $pageReg->removePagesByPack( $packId );
        $packReg->removePack( $packId );

        return [
            'success' => true,
            'action' => 'removePack',
            'packId' => $packId,
            'verify' => [
                'providedCount' => is_array( $pages ) ? count( $pages ) : 0,
                'removedCount' => is_array( $existingPages ) ? count( $existingPages ) : 0
            ],
            '_meta' => [ 'schemaVersion' => 1 ]
        ];
    }

    /* -------------------------------------------------------------------------
     * PAGE IMPORT HELPERS
     * ------------------------------------------------------------------------- */
    private function importPackPages( string $repoUrl, array $pack, array $rewriteMap, array $manifestPages ): array {
        $pagesOut = [];
        $pages = $pack['pages'] ?? [];
    
        foreach ( $pages as $p ) {
            $finalTitle = $p['finalTitle'] ?? '';
            $sourceName = $p['original'] ?? $p['name'] ?? '';
            if ( $finalTitle === '' || $sourceName === '' ) {
                continue;
            }
    
            $lookupKey = str_replace( ' ', '_', mb_strtolower( $sourceName ) );
            $relPath = $manifestPages[$lookupKey] ?? null;
            if ( !$relPath ) {
                wfDebugLog( 'labkipack', "No file path found for page: $sourceName" );
                continue;
            }
    
            $fileUrl = rtrim( $repoUrl, '/' ) . '/' . ltrim( $relPath, '/' );
            $wikitext = $this->fetchWikiFile( $fileUrl );
            if ( $wikitext === null ) {
                wfDebugLog( 'labkipack', "Failed to fetch wiki file: $fileUrl" );
                continue;
            }
    
            // Rewrite internal links
            $updatedText = $this->rewriteLinks( $wikitext, $rewriteMap );
    
            $title = \Title::newFromText( $finalTitle );
            if ( !$title ) {
                continue;
            }
    
            $content = new \WikitextContent( $updatedText );
            $page = \WikiPage::factory( $title );
            $editResult = $page->doEditContent( $content, 'Imported via LabkiPackManager' );
    
            if ( !$editResult->isOK() ) {
                wfDebugLog( 'labkipack', "Edit failed for $finalTitle" );
                continue;
            }
    
            $pagesOut[] = [
                'name' => $sourceName,
                'finalTitle' => $finalTitle,
                'pageNamespace' => $title->getNamespace(),
                'wikiPageId' => $page->getId()
            ];
        }
    
        return $pagesOut;
    }
    

    private function fetchWikiFile( string $url ): ?string {
        $ctx = stream_context_create( [ 'http' => [ 'timeout' => 10 ] ] );
        $data = @file_get_contents( $url, false, $ctx );
        return $data !== false ? $data : null;
    }

    private function rewriteLinks( string $text, array $map ): string {
        foreach ( $map as $orig => $final ) {
            if ( $orig === $final ) {
                continue;
            }
            $escapedOrig = preg_quote( $orig, '/' );

            // Replace [[Page]] or [[Page|...]]
            $text = preg_replace(
                '/\[\[' . $escapedOrig . '(\|[^]]*)?\]\]/u',
                '[[' . $final . '$1]]',
                $text
            );

            // Replace {{Template}} or {{Template|...}}
            $text = preg_replace(
                '/\{\{' . $escapedOrig . '(\|[^}]*)?\}\}/u',
                '{{' . $final . '$1}}',
                $text
            );
        }
        return $text;
    }

    private function buildRewriteMap( string $repoUrl, array $incomingPacks ): array {
        $pageReg = new LabkiPageRegistry();
        $repoReg = new LabkiRepoRegistry();

        $repoId = $repoReg->ensureRepo( $repoUrl );
        $map = $pageReg->getRewriteMapForRepo( $repoId );

        foreach ( $incomingPacks as $pack ) {
            foreach ( $pack['pages'] ?? [] as $pg ) {
                $orig = str_replace( ' ', '_', $pg['original'] ?? $pg['name'] ?? '' );
                $final = $pg['finalTitle'] ?? '';
                if ( $orig && $final ) {
                    $map[$orig] = $final;
                }
            }
        }

        wfDebugLog( 'labkipack', 'Built rewrite map for repo ' . $repoId . ' (' . count( $map ) . ' entries)' );
        return $map;
    }

    /**
     * Retrieve the "pages" section from the cached manifest for a given repo,
     * returning a simple map of pageName => relative file path.
     */
    private function getManifestPages( string $repoUrl ): array {
        $store = new \LabkiPackManager\Services\ManifestStore( $repoUrl );
        $status = $store->get( false ); // prefer cache

        if ( !$status->isOK() ) {
            wfDebugLog( 'labkipack', "Failed to load manifest for repo: $repoUrl" );
            return [];
        }

        $data = $status->getValue();
        $manifestPages = $data['manifest']['pages'] ?? [];

        if ( !is_array( $manifestPages ) ) {
            wfDebugLog( 'labkipack', "Manifest missing or invalid pages section for repo: $repoUrl" );
            return [];
        }

        $map = [];
        foreach ( $manifestPages as $name => $info ) {
            if ( !is_array( $info ) ) {
                continue;
            }

            $path = $info['file'] ?? null;
            if ( !$path ) {
                wfDebugLog( 'labkipack', "No file path found for page '$name' in manifest" );
                continue;
            }

            // Normalize for consistency (case/spacing)
            //$normalized = str_replace( ' ', '_', mb_strtolower( $name ) );
            $normalized = $name;
            $map[$normalized] = $path;
        }

        wfDebugLog( 'labkipack', 'getManifestPages() loaded ' . count( $map ) . " entries for repo $repoUrl" );
        return $map;
    }

    

    /* -------------------------------------------------------------------------
     * PARAMS
     * ------------------------------------------------------------------------- */
    public function getAllowedParams(): array {
        return [
            'actionType'    => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'contentRepoUrl' => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'packName'      => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'version'       => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'newVersion'    => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'packId'        => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => null ],
            'name'          => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'finalTitle'    => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'pageNamespace' => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 0 ],
            'wikiPageId'    => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 0 ],
            'pageId'        => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => null ],
            'pages'         => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'packs'         => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => '[]', self::PARAM_REQUIRED => false ],
        ];
    }
}
