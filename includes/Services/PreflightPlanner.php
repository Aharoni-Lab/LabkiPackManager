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
     * @param array{packs:string[],pages:string[],pageOwners?:array<string,string[]>,pageOwnerUids?:array<string,string[]>,repoUrl?:string} $resolved SelectionResolver result plus optional owners and repo
     * @return array{
     *   create:int,update_unchanged:int,update_modified:int,pack_pack_conflicts:int,external_collisions:int,
     *   lists:array{
     *     create:string[],update_unchanged:string[],update_modified:string[],pack_pack_conflicts:string[],external_collisions:string[]
     *   },
     *   selection_conflicts?:array<int,array{page:string,owners:string[]}>,
     *   owners?:array<string,array{pack_id:?string,source_repo:?string,pack_uid:?string}>
     * }
     */
    public function plan( array $resolved ): array {
        $services = MediaWikiServices::getInstance();
        $titleFactory = $services->getTitleFactory();
        $revLookup = $services->getRevisionLookup();
        $dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

        $create = 0; $updateUnchanged = 0; $updateModified = 0; $packPack = 0; $external = 0;
        $createList = []; $updateUnchangedList = []; $updateModifiedList = []; $packPackList = []; $externalList = [];

        $currentRepo = isset($resolved['repoUrl']) && is_string($resolved['repoUrl']) ? (string)$resolved['repoUrl'] : null;
        foreach ( $resolved['pages'] as $prefixed ) {
            $title = $titleFactory->newFromText( $prefixed );
            if ( !$title ) { continue; }
            $pageId = (int)$title->getArticleID();
            if ( $pageId === 0 ) { $create++; $createList[] = $prefixed; continue; }

            // Check page_props for provenance
            $pp = $dbr->newSelectQueryBuilder()
                ->select( [ 'pp_propname', 'pp_value' ] )
                ->from( 'page_props' )
                ->where( [ 'pp_page' => $pageId, 'pp_propname' => [ 'labki.pack_id', 'labki.content_hash', 'labki.source_repo', 'labki.pack_uid' ] ] )
                ->fetchResultSet();
            $props = [];
            foreach ( $pp as $row ) { $props[(string)$row->pp_propname] = (string)$row->pp_value; }

            $packId = $props['labki.pack_id'] ?? null;
            if ( $packId === null ) { $external++; $externalList[] = $prefixed; continue; }

            // If existing page is owned by a different repo, treat as pack-pack conflict
            $existingRepo = $props['labki.source_repo'] ?? null;
            if ( $existingRepo && $currentRepo && $existingRepo !== $currentRepo ) {
                $packPack++; $packPackList[] = $prefixed; continue;
            }

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
            if ( $curHash !== null && $lastHash !== null && $curHash !== $lastHash ) { $updateModified++; $updateModifiedList[] = $prefixed; }
            else { $updateUnchanged++; $updateUnchangedList[] = $prefixed; }
        }

        // Pack-pack conflicts are identified when two packs target the same page.
        // Detect duplicates in resolved pages (same prefixed title appearing multiple times via different packs).
        // For now, approximate by counting duplicates in input (SelectionResolver ensures unique pages per closure),
        // so conflicts are 0 unless we later include pack->page mapping in resolved payload.

        // Intra-selection conflicts: multiple selected packs own the same page in this selection
        $selectionConflicts = [];
        if ( isset($resolved['pageOwners']) && is_array($resolved['pageOwners']) ) {
            foreach ( $resolved['pageOwners'] as $p => $owners ) {
                if ( is_array($owners) && count($owners) > 1 ) {
                    $selectionConflicts[] = [ 'page' => $p, 'owners' => array_values(array_unique($owners)) ];
                }
            }
        }

        return [
            'create' => $create,
            'update_unchanged' => $updateUnchanged,
            'update_modified' => $updateModified,
            'pack_pack_conflicts' => $packPack,
            'external_collisions' => $external,
            'lists' => [
                'create' => $createList,
                'update_unchanged' => $updateUnchangedList,
                'update_modified' => $updateModifiedList,
                'pack_pack_conflicts' => $packPackList,
                'external_collisions' => $externalList,
            ],
            'selection_conflicts' => $selectionConflicts,
        ];
    }

    private static function normalizeText( string $text ): string {
        $norm = preg_replace( "/\r\n?|\x{2028}|\x{2029}/u", "\n", $text );
        $norm = preg_replace( '/[ \t]+\n/', "\n", (string)$norm );
        return (string)$norm;
    }
}


