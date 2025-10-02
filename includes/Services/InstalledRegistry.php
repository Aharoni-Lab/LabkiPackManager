<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;

/**
 * Read-only registry access for installed packs and their pages.
 */
final class InstalledRegistry {
    /**
     * @return array<string,array{version:?string,source_repo:?string,source_ref:?string,source_commit:?string,installed_at:?int,installed_by:?int}>
     */
    public function getInstalledMap(): array {
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $db = $lb->getConnection( DB_REPLICA );
        $res = $db->newSelectQueryBuilder()
            ->select( [ 'pack_uid','pack_id','version','source_repo','source_ref','source_commit','installed_at','installed_by' ] )
            ->from( 'labki_pack_registry' )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $uid = (string)$row->pack_uid;
            $out[$uid] = [
                'pack_id' => $row->pack_id !== null ? (string)$row->pack_id : null,
                'version' => $row->version !== null ? (string)$row->version : null,
                'source_repo' => $row->source_repo !== null ? (string)$row->source_repo : null,
                'source_ref' => $row->source_ref !== null ? (string)$row->source_ref : null,
                'source_commit' => $row->source_commit !== null ? (string)$row->source_commit : null,
                'installed_at' => $row->installed_at !== null ? (int)$row->installed_at : null,
                'installed_by' => $row->installed_by !== null ? (int)$row->installed_by : null,
            ];
        }
        return $out;
    }
}


