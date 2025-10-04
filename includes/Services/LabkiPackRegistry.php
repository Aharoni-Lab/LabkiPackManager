<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * Pack-level registry service for labki_pack table.
 */
final class LabkiPackRegistry {
    private const TABLE = 'labki_pack';

    /**
     * Insert a pack if not present and return pack_id.
     * @param array{version?:?string,source_ref?:?string,source_commit?:?string,installed_at?:?int,installed_by?:?int,status?:?string} $meta
     */
    public function addPack( int $repoId, string $name, array $meta ): int {
        $existing = $this->getPackIdByName( $repoId, $name, $meta['version'] ?? null );
        if ( $existing !== null ) {
            return $existing;
        }

        $now = time();
        $dbw = wfGetDB( DB_PRIMARY );
        $row = [
            'repo_id' => $repoId,
            'name' => $name,
            'version' => $meta['version'] ?? null,
            'source_ref' => $meta['source_ref'] ?? null,
            'source_commit' => $meta['source_commit'] ?? null,
            'installed_at' => $meta['installed_at'] ?? $now,
            'installed_by' => $meta['installed_by'] ?? null,
            'updated_at' => $now,
            'status' => $meta['status'] ?? 'installed',
        ];

        $dbw->newInsertQueryBuilder()
            ->insertInto( self::TABLE )
            ->row( $row )
            ->caller( __METHOD__ )
            ->execute();

        $id = (int)$dbw->insertId();
        wfDebugLog( 'Labki', 'Added pack ' . $name . ' (pack_id=' . $id . ', repo_id=' . $repoId . ')' );
        return $id;
    }

    /**
     * Fetch pack_id by repo/name/version (version nullable).
     */
    public function getPackIdByName( int $repoId, string $name, ?string $version = null ): ?int {
        $dbr = wfGetDB( DB_REPLICA );
        $conds = [ 'repo_id' => $repoId, 'name' => $name ];
        if ( $version !== null ) {
            $conds['version'] = $version;
        } else {
            $conds['version'] = null;
        }
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'pack_id' )
            ->from( self::TABLE )
            ->where( $conds )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return (int)$row->pack_id;
    }

    /**
     * Fetch full pack record by ID.
     * @return array{pack_id:int,repo_id:int,name:string,version:?string,source_ref:?string,source_commit:?string,installed_at:?int,installed_by:?int,updated_at:?int,status:?string}|null
     */
    public function getPack( int $packId ): ?array {
        $dbr = wfGetDB( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( [ 'pack_id','repo_id','name','version','source_ref','source_commit','installed_at','installed_by','updated_at','status' ] )
            ->from( self::TABLE )
            ->where( [ 'pack_id' => $packId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return [
            'pack_id' => (int)$row->pack_id,
            'repo_id' => (int)$row->repo_id,
            'name' => (string)$row->name,
            'version' => $row->version !== null ? (string)$row->version : null,
            'source_ref' => $row->source_ref !== null ? (string)$row->source_ref : null,
            'source_commit' => $row->source_commit !== null ? (string)$row->source_commit : null,
            'installed_at' => $row->installed_at !== null ? (int)$row->installed_at : null,
            'installed_by' => $row->installed_by !== null ? (int)$row->installed_by : null,
            'updated_at' => $row->updated_at !== null ? (int)$row->updated_at : null,
            'status' => $row->status !== null ? (string)$row->status : null,
        ];
    }

    /**
     * List packs for a repository.
     * @return array<int,array{pack_id:int,repo_id:int,name:string,version:?string,source_ref:?string,source_commit:?string,installed_at:?int,installed_by:?int,updated_at:?int,status:?string}>
     */
    public function listPacksByRepo( int $repoId ): array {
        $dbr = wfGetDB( DB_REPLICA );
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'pack_id','repo_id','name','version','source_ref','source_commit','installed_at','installed_by','updated_at','status' ] )
            ->from( self::TABLE )
            ->where( [ 'repo_id' => $repoId ] )
            ->orderBy( 'pack_id' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $out[] = [
                'pack_id' => (int)$row->pack_id,
                'repo_id' => (int)$row->repo_id,
                'name' => (string)$row->name,
                'version' => $row->version !== null ? (string)$row->version : null,
                'source_ref' => $row->source_ref !== null ? (string)$row->source_ref : null,
                'source_commit' => $row->source_commit !== null ? (string)$row->source_commit : null,
                'installed_at' => $row->installed_at !== null ? (int)$row->installed_at : null,
                'installed_by' => $row->installed_by !== null ? (int)$row->installed_by : null,
                'updated_at' => $row->updated_at !== null ? (int)$row->updated_at : null,
                'status' => $row->status !== null ? (string)$row->status : null,
            ];
        }
        return $out;
    }

    /**
     * Update pack fields, touching updated_at unless provided.
     * @param array<string,mixed> $fields
     */
    public function updatePack( int $packId, array $fields ): void {
        $dbw = wfGetDB( DB_PRIMARY );
        if ( !array_key_exists( 'updated_at', $fields ) ) {
            $fields['updated_at'] = time();
        }
        $dbw->newUpdateQueryBuilder()
            ->update( self::TABLE )
            ->set( $fields )
            ->where( [ 'pack_id' => $packId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Updated pack ' . $packId );
    }

    /**
     * Remove a pack (cascade pages).
     */
    public function removePack( int $packId ): void {
        $dbw = wfGetDB( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'pack_id' => $packId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Removed pack ' . $packId );
    }
}


