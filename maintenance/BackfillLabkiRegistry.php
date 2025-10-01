<?php

declare(strict_types=1);

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
    require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
    require_once __DIR__ . '/../../../../maintenance/Maintenance.php';
}

/**
 * Scans pages for Labki page props and backfills labki_pack_registry and labki_pack_pages.
 */
class BackfillLabkiRegistry extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Backfill Labki pack registry and pages from page_props.' );
    }

    public function execute() {
        $services = MediaWikiServices::getInstance();
        $dbw = $services->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

        $this->output( "Scanning page_props for labki.* keys...\n" );

        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'pp_page', 'pp_propname', 'pp_value' ] )
            ->from( 'page_props' )
            ->where( 'pp_propname IN ( ' . $dbr->makeList( [ 'labki.pack_id', 'labki.pack_version', 'labki.source_repo', 'labki.source_ref', 'labki.source_commit', 'labki.page_key', 'labki.content_hash' ] ) . ' )' )
            ->fetchResultSet();

        $byPage = [];
        foreach ( $res as $row ) {
            $byPage[(int)$row->pp_page][(string)$row->pp_propname] = (string)$row->pp_value;
        }

        $titleFactory = $services->getTitleFactory();
        $revLookup = $services->getRevisionLookup();

        $packs = [];
        foreach ( $byPage as $pageId => $props ) {
            $packId = $props['labki.pack_id'] ?? null;
            if ( !$packId ) { continue; }
            $title = $titleFactory->newFromID( $pageId );
            if ( !$title ) { continue; }
            $rev = $revLookup->getRevisionByTitle( $title );
            $packs[$packId]['pages'][] = [
                'prefixed' => $title->getPrefixedText(),
                'ns' => $title->getNamespace(),
                'page_id' => $pageId,
                'last_rev_id' => $rev ? (int)$rev->getId() : 0,
                'content_hash' => $props['labki.content_hash'] ?? null,
            ];
            $packs[$packId]['version'] = $props['labki.pack_version'] ?? ($packs[$packId]['version'] ?? null);
            $packs[$packId]['source_repo'] = $props['labki.source_repo'] ?? ($packs[$packId]['source_repo'] ?? null);
            $packs[$packId]['source_ref'] = $props['labki.source_ref'] ?? ($packs[$packId]['source_ref'] ?? null);
            $packs[$packId]['source_commit'] = $props['labki.source_commit'] ?? ($packs[$packId]['source_commit'] ?? null);
        }

        foreach ( $packs as $pid => $data ) {
            $dbw->upsert(
                'labki_pack_registry',
                [ 'pack_id' => $pid, 'version' => $data['version'] ?? null, 'source_repo' => $data['source_repo'] ?? null, 'source_ref' => $data['source_ref'] ?? null, 'source_commit' => $data['source_commit'] ?? null, 'installed_at' => time(), 'installed_by' => 0 ],
                [ 'pack_id' ],
                [ 'version' => $data['version'] ?? null, 'source_repo' => $data['source_repo'] ?? null, 'source_ref' => $data['source_ref'] ?? null, 'source_commit' => $data['source_commit'] ?? null ]
            );
            foreach ( $data['pages'] as $pg ) {
                $dbw->upsert(
                    'labki_pack_pages',
                    [
                        'pack_id' => $pid,
                        'page_title' => $pg['prefixed'],
                        'page_namespace' => $pg['ns'],
                        'page_id' => $pg['page_id'],
                        'last_rev_id' => $pg['last_rev_id'],
                        'content_hash' => $pg['content_hash'],
                    ],
                    [ [ 'pack_id', 'page_title' ] ],
                    [ 'page_namespace' => $pg['ns'], 'page_id' => $pg['page_id'], 'last_rev_id' => $pg['last_rev_id'], 'content_hash' => $pg['content_hash'] ]
                );
            }
        }

        $this->output( "Backfill complete: " . count( $packs ) . " packs updated.\n" );
    }
}

$maintClass = BackfillLabkiRegistry::class;
require_once RUN_MAINTENANCE_IF_MAIN;


