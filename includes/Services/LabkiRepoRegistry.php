<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use MediaWiki\MediaWikiServices;

/**
 * Repository-level registry service for labki_content_repo table.
 */
final class LabkiRepoRegistry {
    private const TABLE = 'labki_content_repo';

    /** Normalize repo URLs for consistent storage and lookup */
    private function normalizeUrl( string $url ): string {
        $u = trim( $url );
        // Trim trailing slashes; leave case as-is to avoid altering path semantics
        $u = rtrim( $u, "/" );
        return $u;
    }

    /**
     * Insert a repository if not present and return repo_id.
     */
    public function addRepo( string $url, ?string $defaultRef = null ): ContentRepoId {
        wfDebugLog( 'labkipack', 'addRepo() called with url: ' . $url );
        $normUrl = $this->normalizeUrl( $url );
        $existingId = $this->getRepoIdByUrl( $normUrl );
        if ( $existingId !== null ) {
            wfDebugLog( 'labkipack', 'addRepo() found existing repo with ID: ' . $existingId->toInt() );
            return $existingId;
        }

        wfDebugLog( 'labkipack', 'addRepo() inserting new repo: ' . $normUrl );
        try {
            $now = \wfTimestampNow();
            $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

            $dbw->newInsertQueryBuilder()
                ->insertInto( self::TABLE )
                ->row( [
                    'content_repo_url' => $normUrl,
                    'default_ref' => $defaultRef,
                    'created_at' => $now,
                    'updated_at' => $now,
                ] )
                ->caller( __METHOD__ )
                ->execute();

            $id = (int)$dbw->insertId();
            wfDebugLog( 'labkipack', 'Successfully added repo ' . $normUrl . ' (repo_id=' . $id . ')' );
            return new ContentRepoId( $id );
        } catch ( Exception $e ) {
            wfDebugLog( 'labkipack', 'addRepo() failed with exception: ' . $e->getMessage() );
            wfDebugLog( 'labkipack', 'Stack trace: ' . $e->getTraceAsString() );
            throw $e;
        }
    }

    /**
     * Get a repository ID by its URL.
     */
    public function getRepoIdByUrl( string $url ): ?ContentRepoId {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $normUrl = $this->normalizeUrl( $url );
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'content_repo_id' )
            ->from( self::TABLE )
            ->where( [ 'content_repo_url' => $normUrl ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return new ContentRepoId( (int)$row->content_repo_id );
    }

    /**
     * Fetch full repository record by ID.
     * @return ContentRepo|null
     */
    public function getRepoById( int|ContentRepoId $repoId ): ?ContentRepo {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( ContentRepo::FIELDS )
            ->from( self::TABLE )
            ->where( [ 'content_repo_id' => $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return ContentRepo::fromRow( $row );
    }

    /** Small helper used by API: return same as getRepoById but named getRepoInfo */
    // We can see which function name we like more and remove the other probably
    public function getRepoInfo( int|ContentRepoId $repoId ): ?ContentRepo {
        return $this->getRepoById( $repoId );
    }

    /** Ensure repo exists by URL and return its ID. */
    public function ensureRepo( string $url ): ContentRepoId {
        wfDebugLog( 'labkipack', 'ensureRepo() called with url: ' . $url );
        $normUrl = $this->normalizeUrl( $url );
        wfDebugLog( 'labkipack', 'ensureRepo() called with url: ' . $url . ', normalized: ' . $normUrl );
        
        $id = $this->getRepoIdByUrl( $normUrl );
        if ( $id !== null ) {
            wfDebugLog( 'labkipack', 'Found existing repo with ID: ' . $id->toInt() );
            return $id;
        }
        
        wfDebugLog( 'labkipack', 'No existing repo found, calling addRepo' );
        return $this->addRepo( $normUrl, null );
    }

    /**
     * List all repositories.
     * @return array<int,ContentRepo>
     */
    public function listRepos(): array {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $res = $dbr->newSelectQueryBuilder()
            ->select( ContentRepo::FIELDS )
            ->from( self::TABLE )
            ->orderBy( 'content_repo_id' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $out[] = ContentRepo::fromRow( $row );
        }
        return $out;
    }

    /**
     * Update repository fields; always touches updated_at unless explicitly provided.
     * @param array<string,mixed> $fields
     */
    public function updateRepo( int|ContentRepoId $repoId, array $fields ): void {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        if ( !array_key_exists( 'updated_at', $fields ) ) {
            $fields['updated_at'] = \wfTimestampNow();
        }
        $dbw->newUpdateQueryBuilder()
            ->update( self::TABLE )
            ->set( $fields )
            ->where( [ 'content_repo_id' => $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId ] )
            ->caller( __METHOD__ )
            ->execute();
    }

    /**
     * Delete repository (cascade removes packs/pages).
     */
    public function deleteRepo( int|ContentRepoId $repoId ): void {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'content_repo_id' => $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId ] )
            ->caller( __METHOD__ )
            ->execute();
    }
}


