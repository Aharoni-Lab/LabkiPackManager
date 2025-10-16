<?php

declare(strict_types=1);

namespace LabkiPackManager\API;

use ApiBase;
use ApiMain;
use Exception;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;
use MediaWiki\MediaWikiServices;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Title\Title;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;

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
        wfDebugLog( 'labkipack', 'handleImportPack() called' );
        $params = $this->extractRequestParams();
        $repoUrl = (string)( $params['contentRepoUrl'] ?? '' );
        $packsJson = $params['packs'] ?? '[]';
        $packs = json_decode( $packsJson, true ) ?: [];
        
        wfDebugLog( 'labkipack', 'Got packs: ' . json_encode( $packs ) );
        if ( $repoUrl === '' ) {
            $this->dieWithError( 'apierror-missing-param' );
        }
    
        wfDebugLog( 'labkipack', 'Ensuring repo ' . $repoUrl );
        $repoReg = new LabkiRepoRegistry();
        $packReg = new LabkiPackRegistry();
        $pageReg = new LabkiPageRegistry();
    
        $repoId = $repoReg->ensureRepo( $repoUrl );
    
        // Build global rewrite mapping across both existing and new pages
        $rewriteMap = $this->buildRewriteMap( $repoUrl, $packs );
        
        wfDebugLog( 'labkipack', 'Building rewrite map for repo ' . $repoUrl . ' (' . count( $packs ) . ' packs)' );
        // Get all manifest pages once for all pack imports - with error handling
        $manifestPages = [];
        try {
            $manifestPages = $this->getManifestPages( $repoUrl );
            wfDebugLog( 'labkipack', 'Got manifest pages for repo ' . $repoUrl . ' (' . count( $manifestPages ) . ' pages)' );
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
            // Need to add a check to make sure import into MW was successful before adding to DB
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
        wfDebugLog( 'labkipack', 'importPackPages() called with repoUrl: ' . $repoUrl . ' and pack: ' . json_encode( $pack ) );
        $pagesOut = [];
        $pages = $pack['pages'] ?? [];
    
        foreach ( $pages as $p ) {
            $finalTitle = $p['finalTitle'] ?? '';
            $sourceName = $p['original'] ?? $p['name'] ?? '';
            if ( $finalTitle === '' || $sourceName === '' ) {
                continue;
            }
    
            // ✅ lookup using *exact* source name key (no normalization)
            $lookupKey = $sourceName;
            $relPath = $manifestPages[$lookupKey] ?? null;
            if ( !$relPath ) {
                wfDebugLog( 'labkipack', "No file path found for page: $sourceName" );
                continue;
            }
    
            $fileUrl = $this->buildPageUrl( $repoUrl, $relPath );
            wfDebugLog( 'labkipack', "Fetching page from URL: $fileUrl" );

            $wikitext = $this->fetchWikiFile( $fileUrl );
            if ( $wikitext === null ) {
                wfDebugLog( 'labkipack', "Failed to fetch wiki file: $fileUrl" );
                continue;
            }
    
            // Rewrite internal links
            wfDebugLog( 'labkipack', "Rewriting links for $finalTitle." );
            $updatedText = $this->rewriteLinks( $wikitext, $rewriteMap );

            wfDebugLog( 'labkipack', "Creating title for $finalTitle" );
            $title = Title::newFromText( $finalTitle );
            wfDebugLog( 'labkipack', "Title for $finalTitle: " . $title );
            if ( !$title ) {
                wfDebugLog( 'labkipack', "Failed to create title for $finalTitle" );
                continue;
            }
            wfDebugLog( 'labkipack', "Creating content for $finalTitle" );
            $content = new WikitextContent( $updatedText );
            wfDebugLog('labkipack', "WikiPageFactory called");
            $wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
            $page = $wikiPageFactory->newFromTitle( $title );
            wfDebugLog( 'labkipack', "Creating page for $finalTitle" );
            $editResult = $this->editPage( $page, $content, 'Imported via LabkiPackManager' );
            wfDebugLog( 'labkipack', "Edit result for $finalTitle: " . $editResult->isOK() );
            if ( !$editResult->isOK() ) {
                wfDebugLog( 'labkipack', "Edit failed for $finalTitle" );
                continue;
            }
    
            // ✅ output uses the same unmodified name key
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
        // Stream context with GitHub-friendly headers and redirect support
        $ctx = stream_context_create( [
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'header' => [
                    'User-Agent: LabkiPackManager/1.0 (+https://github.com/Aharoni-Lab/LabkiPackManager)',
                    'Accept: text/plain, text/x-wiki, text/markdown, */*'
                ],
                'follow_location' => 1,
                'max_redirects' => 5
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                // These lines ensure CA certificates are loaded in minimal containers
                'cafile' => '/etc/ssl/certs/ca-certificates.crt'
            ]
        ] );
    
        wfDebugLog( 'labkipack', "Fetching wiki file: $url" );
    
        $data = @file_get_contents( $url, false, $ctx );
    
        if ( $data === false ) {
            $error = error_get_last();
            wfDebugLog( 'labkipack', "Failed to fetch URL: $url (" . ($error['message'] ?? 'unknown error') . ")" );
            return null;
        }
    
        // Trim BOM or whitespace that sometimes appears in raw GitHub files
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $data);
        wfDebugLog( 'labkipack', "Fetched " . strlen($clean) . " bytes from $url" );
    
        return $clean;
    }
    

    private function rewriteLinks( string $text, array $map ): string {
        foreach ( $map as $orig => $final ) {
            if ( $orig === $final ) {
                continue;
            }
    
            // Match either exact, space, or underscore variant of the original
            $escapedOrig = preg_quote( $orig, '/' );
            $escapedAlt  = preg_quote( str_replace( ' ', '_', $orig ), '/' );
            $pattern = '/\[\[(?:' . $escapedOrig . '|' . $escapedAlt . ')(\|[^]]*)?\]\]/u';
            $text = preg_replace( $pattern, '[[' . $final . '$1]]', $text );
    
            $patternTmpl = '/\{\{(?:' . $escapedOrig . '|' . $escapedAlt . ')(\|[^}]*)?\}\}/u';
            $text = preg_replace( $patternTmpl, '{{' . $final . '$1}}', $text );
        }
        return $text;
    }
    

    private function buildRewriteMap( string $repoUrl, array $incomingPacks ): array {
        $pageReg = new LabkiPageRegistry();
        $repoReg = new LabkiRepoRegistry();
    
        $repoId = $repoReg->ensureRepo( $repoUrl );
        wfDebugLog( 'labkipack', 'Ensuring repo second call ' . $repoUrl );
        wfDebugLog( 'labkipack', 'About to call getRewriteMapForRepo with repoId: ' . $repoId->toInt() );
    
        $map = $pageReg->getRewriteMapForRepo( $repoId->toInt() );
        wfDebugLog( 'labkipack', 'Got rewrite map for repo ' . $repoId . ' (' . count( $map ) . ' entries)' );
    
        foreach ( $incomingPacks as $pack ) {
            foreach ( $pack['pages'] ?? [] as $pg ) {
                $orig = $pg['original'] ?? ($pg['name'] ?? '');
                $final = $pg['finalTitle'] ?? '';
                if ( $orig !== '' && $final !== '' ) {
                    // ✅ store key exactly as original name
                    $map[$orig] = $final;
                }
            }
        }
    
        wfDebugLog( 'labkipack', 'Built rewrite map for repo ' . $repoId . ' (' . count( $map ) . ' entries)' );
        return $map;
    }
    

    private function editPage( \MediaWiki\Page\WikiPage $page, \MediaWiki\Content\Content $content, string $summary ): \Status {
        $comment = CommentStoreComment::newUnsavedComment( $summary );
        $pageUpdater = $page->newPageUpdater( $this->getUser() );
        $pageUpdater->setContent( SlotRecord::MAIN, $content );
        $pageUpdater->saveRevision( $comment );
        return $pageUpdater->getStatus();
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
    
            // ✅ Store using *exact* manifest name (no normalization)
            $map[$name] = $path;
        }
    
        wfDebugLog( 'labkipack', 'getManifestPages() loaded ' . count( $map ) . " entries for repo $repoUrl" );
        return $map;
    }
    
    /**
     * Build a full fetchable URL for a given file path within a content repository.
     * Handles GitHub repos by converting to raw.githubusercontent.com format.
     *
     * Examples:
     *   buildPageUrl("https://github.com/Aharoni-Lab/labki-packs", "pages/Foo Bar.wiki")
     *   → https://raw.githubusercontent.com/Aharoni-Lab/labki-packs/refs/heads/main/pages/Foo%20Bar.wiki
     */
    // This should be moved to utils and have 1 file that helps map in and out of github URLs.
    private function buildPageUrl( string $repoUrl, string $relPath, ?string $ref = null ): string {
        $ref = $ref ?: 'refs/heads/main';
        $cleanPath = ltrim( $relPath, '/' );

        // Handle GitHub
        if ( preg_match( '#^https://github\.com/([^/]+)/([^/]+?)(?:\.git)?(?:/.*)?$#', $repoUrl, $m ) ) {
            $owner = $m[1];
            $repo  = $m[2];
            // Encode each segment safely but keep slashes
            $segments = array_map( 'rawurlencode', explode( '/', $cleanPath ) );
            $encodedPath = implode( '/', $segments );

            return "https://raw.githubusercontent.com/$owner/$repo/$ref/$encodedPath";
        }

        // Fallback: non-GitHub source (just join)
        return rtrim( $repoUrl, '/' ) . '/' . str_replace( ' ', '%20', $cleanPath );
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
