<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\ContentRepoId;

/**
 * Pack-level registry service for labki_pack table.
 */
final class LabkiPackRegistry {
    private const TABLE = 'labki_pack';

    /**
     * Insert a pack if not present and return pack_id.
     * @param array{version?:?string,source_ref?:?string,source_commit?:?string,installed_at?:?int,installed_by?:?int,status?:?string} $meta
     */
    public function addPack( int|ContentRepoId $repoId, string $name, array $meta ): PackId {
        $existing = $this->getPackIdByName( $repoId, $name, $meta['version'] ?? null );
        if ( $existing !== null ) {
            return $existing;
        }

        $now = time();
        $dbw = wfGetDB( DB_PRIMARY );
        $row = [
            'repo_id' => $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId,
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
        return new PackId( $id );
    }

    /**
     * Fetch pack_id by repo/name/version (version nullable).
     */
    public function getPackIdByName( int|ContentRepoId $repoId, string $name, ?string $version = null ): ?PackId {
        $dbr = wfGetDB( DB_REPLICA );
        $conds = [ 'repo_id' => $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId, 'name' => $name ];
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
        return new PackId( (int)$row->pack_id );
    }

    /**
     * Fetch full pack record by ID.
     * @return Pack|null
     */
    public function getPack( int|PackId $packId ): ?Pack {
        $dbr = wfGetDB( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( Pack::FIELDS )
            ->from( self::TABLE )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return Pack::fromRow( $row );
    }

    /**
     * List packs for a repository.
     * @return array<int,Pack>
     */
    public function listPacksByRepo( int|ContentRepoId $repoId ): array {
        $dbr = wfGetDB( DB_REPLICA );
        $res = $dbr->newSelectQueryBuilder()
            ->select( Pack::FIELDS )
            ->from( self::TABLE )
            ->where( [ 'repo_id' => $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId ] )
            ->orderBy( 'pack_id' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $out[] = Pack::fromRow( $row );
        }
        return $out;
    }

    /** Alias for API: getPackInfo by ID */
    public function getPackInfo( int|PackId $packId ): ?Pack {
        return $this->getPack( $packId );
    }

    /** Register or update a pack for install; returns pack_id */
    public function registerPack( int|ContentRepoId $repoId, string $name, ?string $version, int $installedBy ): ?PackId {
        $existing = $this->getPackIdByName( $repoId, $name, $version );
        if ( $existing !== null ) {
            $this->updatePack( $existing, [ 'installed_at' => time(), 'installed_by' => $installedBy, 'status' => 'installed' ] );
            return $existing;
        }
        return $this->addPack( $repoId, $name, [ 'version' => $version, 'installed_by' => $installedBy, 'status' => 'installed' ] );
    }

    /** Delete a pack; return success */
    public function deletePack( int|PackId $packId ): bool {
        $this->removePack( $packId );
        return true;
    }

    /**
     * Update pack fields, touching updated_at unless provided.
     * @param array<string,mixed> $fields
     */
    public function updatePack( int|PackId $packId, array $fields ): void {
        $dbw = wfGetDB( DB_PRIMARY );
        if ( !array_key_exists( 'updated_at', $fields ) ) {
            $fields['updated_at'] = time();
        }
        $dbw->newUpdateQueryBuilder()
            ->update( self::TABLE )
            ->set( $fields )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Updated pack ' . $packId );
    }

    /**
     * Remove a pack (cascade pages).
     */
    public function removePack( int|PackId $packId ): void {
        $dbw = wfGetDB( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Removed pack ' . $packId );
    }
}


