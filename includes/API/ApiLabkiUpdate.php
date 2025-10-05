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
                $contentRepoUrl = (string)( $params['contentRepoUrl'] ?? '' );
                $packName = (string)( $params['packName'] ?? '' );
                $version = (string)( $params['version'] ?? '' );
                $repoId = $repoReg->ensureRepo( $contentRepoUrl );
                $packId = $packReg->registerPack( $repoId, $packName, $version, $this->getUser()->getId() );
                $result['success'] = $packId !== null;
                $result['packId'] = $packId instanceof PackId ? $packId->toInt() : (int)$packId;
                $packInfo = $packReg->getPackInfo( $packId );
                if ( $packInfo && method_exists( $packInfo, 'toArray' ) ) {
                    $result['pack'] = $packInfo->toArray();
                }
                break;

            case 'removePack':
                $packId = (int)( $params['packId'] ?? 0 );
                $result['success'] = $packReg->deletePack( $packId );
                break;

            case 'recordPageInstall':
                $packId = (int)( $params['packId'] ?? 0 );
                $name = (string)( $params['name'] ?? '' );
                $finalTitle = (string)( $params['finalTitle'] ?? '' );
                $ns = (int)( $params['pageNamespace'] ?? 0 );
                $wikiPageId = (int)( $params['wikiPageId'] ?? 0 );
                $pageId = $pageReg->recordInstalledPage( $packId, $name, $finalTitle, $ns, $wikiPageId );
                $result['success'] = $pageId !== null;
                $result['pageId'] = $pageId instanceof PageId ? $pageId->toInt() : (int)$pageId;
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
            'packId'        => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => null ],
            'name'          => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'finalTitle'    => [ self::PARAM_TYPE => 'string', self::PARAM_DFLT => null ],
            'pageNamespace' => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 0 ],
            'wikiPageId'    => [ self::PARAM_TYPE => 'integer', self::PARAM_DFLT => 0 ],
        ];
    }
}


