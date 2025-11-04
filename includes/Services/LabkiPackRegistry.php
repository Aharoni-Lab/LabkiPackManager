<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\ContentRefId;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * LabkiPackRegistry
 *
 * Pack-level registry service for the labki_pack table.
 *
 * This service manages content pack metadata, where each entry corresponds to
 * an installed pack from a specific content ref (branch/tag). Packs are uniquely
 * identified by (content_ref_id, name) regardless of version.
 *
 * Responsibilities:
 * - Creating and updating pack entries
 * - Tracking pack metadata (name, version, status, installation info)
 * - Querying packs by repository, name, or ID
 * - Listing all packs for a repository
 * - Deleting packs (cascades to pages)
 * - Managing pack status (installed, removed, etc.)
 *
 * Related tables:
 * - labki_content_ref: Parent ref (managed by LabkiRefRegistry)
 * - labki_page: Page metadata within packs (managed by LabkiPageRegistry)
 *
 * Note: Not marked as final to allow mocking in unit tests.
 */
class LabkiPackRegistry {
    private const TABLE = 'labki_pack';

    /**
     * Get current timestamp in DB-specific format.
     * Can be called by external code to get properly formatted timestamps.
     * @return string Formatted timestamp for database insertion
     */
    public function now(): string {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        return $dbw->timestamp( \wfTimestampNow() );
    }

    /**
     * Insert a pack if not present and return pack_id.
     * Uniqueness is by (content_ref_id, name), regardless of version.
     * @param array{version?:?string,source_commit?:?string,installed_at?:?int,installed_by?:?int,status?:?string} $meta
     */
    public function addPack( int|ContentRefId $refId, string $name, array $meta ): PackId {
        // Note: This class only persists registry state. Actual MW page/content operations
        // must be performed by higher layers (API/service) before calling into the registry.
        $existing = $this->getPackIdByName( $refId, $name );
        if ( $existing !== null ) {
            return $existing;
        }

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $row = [
            'content_ref_id' => $refId instanceof ContentRefId ? $refId->toInt() : $refId,
            'name' => $name,
            'version' => $meta['version'] ?? null,
            'source_commit' => $meta['source_commit'] ?? null,
            'installed_at' => $meta['installed_at'] ?? $this->now(),
            'installed_by' => $meta['installed_by'] ?? null,
            'updated_at' => $this->now(),
            'status' => $meta['status'] ?? 'installed',
        ];

        $dbw->newInsertQueryBuilder()
            ->insertInto( self::TABLE )
            ->row( $row )
            ->caller( __METHOD__ )
            ->execute();

        $id = (int)$dbw->insertId();
        wfDebugLog( 'Labki', 'Added pack ' . $name . ' (pack_id=' . $id . ', ref_id=' . $refId . ')' );
        return new PackId( $id );
    }

    /**
     * Fetch pack_id by ref/name (version is ignored for uniqueness).
     */
    public function getPackIdByName( int|ContentRefId $refId, string $name, ?string $version = null ): ?PackId {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $conds = [ 'content_ref_id' => $refId instanceof ContentRefId ? $refId->toInt() : $refId, 'name' => $name ];
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
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
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
     * List packs for a ref.
     * @return array<int,Pack>
     */
    public function listPacksByRef( int|ContentRefId $refId ): array {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $res = $dbr->newSelectQueryBuilder()
            ->select( Pack::FIELDS )
            ->from( self::TABLE )
            ->where( [ 'content_ref_id' => $refId instanceof ContentRefId ? $refId->toInt() : $refId ] )
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
    public function registerPack( int|ContentRefId $refId, string $name, ?string $version, int $installedBy ): ?PackId {
        $existing = $this->getPackIdByName( $refId, $name );
        if ( $existing !== null ) {
            $this->updatePack( $existing, [
                'installed_at' => $this->now(),
                'installed_by' => $installedBy,
                'status' => 'installed',
                'version' => $version,
            ] );
            return $existing;
        }
        return $this->addPack( $refId, $name, [ 'version' => $version, 'installed_by' => $installedBy, 'status' => 'installed' ] );
    }

    /** Remove a pack and its pages. Prefer using from API where pages are removed explicitly. */
    public function removePackAndPages( int|PackId $packId ): void {
        // For safety: API currently removes pages via PageRegistry first; this method ensures cascade
        $this->removePack( $packId );
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
        // Note: Only persists metadata changes. Caller ensures MW changes are applied first.
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        if ( !array_key_exists( 'updated_at', $fields ) ) {
            $fields['updated_at'] = $this->now();
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
        // Note: Caller must have removed MW pages first; this only deletes registry row (pages cascade).
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Removed pack ' . $packId );
    }

    // ===========================================================
    //  Pack Dependency Management
    // ===========================================================

    /**
     * Store pack dependencies as they were at install time.
     * 
     * @param int|PackId $packId The pack that has dependencies
     * @param array<int|PackId> $dependsOnPackIds Pack IDs this pack depends on
     */
    public function storeDependencies( int|PackId $packId, array $dependsOnPackIds ): void {
        if ( empty( $dependsOnPackIds ) ) {
            return; // No dependencies to store
        }

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $packIdInt = $packId instanceof PackId ? $packId->toInt() : $packId;
        
        $rows = [];
        foreach ( $dependsOnPackIds as $depPackId ) {
            $rows[] = [
                'pack_id' => $packIdInt,
                'depends_on_pack_id' => $depPackId instanceof PackId ? $depPackId->toInt() : $depPackId,
                'created_at' => $this->now(),
            ];
        }

        $dbw->newInsertQueryBuilder()
            ->insertInto( 'labki_pack_dependency' )
            ->ignore()  // Skip if dependency already exists
            ->rows( $rows )
            ->caller( __METHOD__ )
            ->execute();
        
        wfDebugLog( 'Labki', 'Stored ' . count( $rows ) . ' dependencies for pack ' . $packIdInt );
    }

    /**
     * Get pack IDs that a given pack depends on.
     * 
     * @param int|PackId $packId
     * @return array<PackId> Array of pack IDs this pack depends on
     */
    public function getDependencies( int|PackId $packId ): array {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $res = $dbr->newSelectQueryBuilder()
            ->select( 'depends_on_pack_id' )
            ->from( 'labki_pack_dependency' )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        
        $dependencies = [];
        foreach ( $res as $row ) {
            $dependencies[] = new PackId( (int)$row->depends_on_pack_id );
        }
        
        return $dependencies;
    }

	/**
	 * Find all packs within a ref that depend on the given pack.
	 * 
	 * @param ContentRefId $refId The ref to search within
	 * @param int|PackId $packId The pack to check dependents for
	 * @return array<Pack> Array of Pack objects that depend on the given pack
	 */
	public function getPacksDependingOn( ContentRefId $refId, int|PackId $packId ): array {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		// Qualify Pack::FIELDS with table alias to avoid ambiguity
		$qualifiedFields = array_map( fn( $field ) => "p.{$field}", Pack::FIELDS );
		
		$res = $dbr->newSelectQueryBuilder()
			->select( $qualifiedFields )
			->from( 'labki_pack_dependency', 'd' )
			->join( 'labki_pack', 'p', 'p.pack_id = d.pack_id' )
			->where( [
				'd.depends_on_pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId,
				'p.content_ref_id' => $refId->toInt(),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		
		$dependents = [];
		foreach ( $res as $row ) {
			$dependents[] = Pack::fromRow( $row );
		}
		
		return $dependents;
	}

    /**
     * Remove all dependencies for a pack.
     * Called when removing or updating a pack.
     * 
     * @param int|PackId $packId
     */
    public function removeDependencies( int|PackId $packId ): void {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( 'labki_pack_dependency' )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->caller( __METHOD__ )
            ->execute();
        
        wfDebugLog( 'Labki', 'Removed dependencies for pack ' . $packId );
    }
}


