<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

/**
 * Computes a pre-flight plan for selected packs: creates, updates, and collisions.
 */
final class PreflightPlanner {
    /**
     * @param array{packs:string[],pages:string[]} $resolved SelectionResolver result
     * @return array{create:int,update_unchanged:int,update_modified:int,pack_pack_conflicts:int,external_collisions:int}
     */
    public function plan( array $resolved ): array {
        $services = MediaWikiServices::getInstance();
        $titleFactory = $services->getTitleFactory();
        $revLookup = $services->getRevisionLookup();
        $dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

        $create = 0; $updateUnchanged = 0; $updateModified = 0; $packPack = 0; $external = 0;

        foreach ( $resolved['pages'] as $prefixed ) {
            $title = $titleFactory->newFromText( $prefixed );
            if ( !$title ) { continue; }
            $pageId = (int)$title->getArticleID();
            if ( $pageId === 0 ) { $create++; continue; }

            // Check page_props for provenance
            $pp = $dbr->newSelectQueryBuilder()
                ->select( [ 'pp_propname', 'pp_value' ] )
                ->from( 'page_props' )
                ->where( [ 'pp_page' => $pageId, 'pp_propname' => [ 'labki.pack_id', 'labki.content_hash' ] ] )
                ->fetchResultSet();
            $props = [];
            foreach ( $pp as $row ) { $props[(string)$row->pp_propname] = (string)$row->pp_value; }

            $packId = $props['labki.pack_id'] ?? null;
            if ( $packId === null ) { $external++; continue; }

            // Same pack → candidate update; compare local drift vs last labki.content_hash
            $rev = $revLookup->getRevisionByTitle( $title );
            $curHash = null;
            if ( $rev ) {
                $content = $rev->getContent( SlotRecord::MAIN );
                if ( $content && method_exists( $content, 'getText' ) ) {
                    $curText = (string)$content->getText();
                } else {
                    $curText = '';
                }
                $curHash = hash( 'sha256', self::normalizeText( $curText ) );
            }
            $lastHash = $props['labki.content_hash'] ?? null;
            if ( $curHash !== null && $lastHash !== null && $curHash !== $lastHash ) { $updateModified++; }
            else { $updateUnchanged++; }
        }

        // Pack-pack conflicts are identified when two packs target the same page.
        // Detect duplicates in resolved pages (same prefixed title appearing multiple times via different packs).
        // For now, approximate by counting duplicates in input (SelectionResolver ensures unique pages per closure),
        // so conflicts are 0 unless we later include pack->page mapping in resolved payload.

        return [
            'create' => $create,
            'update_unchanged' => $updateUnchanged,
            'update_modified' => $updateModified,
            'pack_pack_conflicts' => $packPack,
            'external_collisions' => $external,
        ];
    }

    private static function normalizeText( string $text ): string {
        $norm = preg_replace( "/\r\n?|\x{2028}|\x{2029}/u", "\n", $text );
        $norm = preg_replace( '/[ \t]+\n/', "\n", (string)$norm );
        return (string)$norm;
    }
}


