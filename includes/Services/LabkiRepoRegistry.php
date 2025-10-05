<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * Repository-level registry service for labki_content_repo table.
 */
final class LabkiRepoRegistry {
    private const TABLE = 'labki_content_repo';

    /**
     * Insert a repository if not present and return repo_id.
     */
    public function addRepo( string $url, ?string $defaultRef = null ): int {
        $existingId = $this->getRepoIdByUrl( $url );
        if ( $existingId !== null ) {
            return $existingId;
        }

        $now = time();
        $dbw = wfGetDB( DB_PRIMARY );

        $dbw->newInsertQueryBuilder()
            ->insertInto( self::TABLE )
            ->row( [
                'repo_url' => $url,
                'default_ref' => $defaultRef,
                'created_at' => $now,
                'updated_at' => $now,
            ] )
            ->caller( __METHOD__ )
            ->execute();

        $id = (int)$dbw->insertId();
        wfDebugLog( 'Labki', 'Added repo ' . $url . ' (repo_id=' . $id . ')' );
        return $id;
    }

    /**
     * Get a repository ID by its URL.
     */
    public function getRepoIdByUrl( string $url ): ?int {
        $dbr = wfGetDB( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'repo_id' )
            ->from( self::TABLE )
            ->where( [ 'repo_url' => $url ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return (int)$row->repo_id;
    }

    /**
     * Fetch full repository record by ID.
     * @return array{repo_id:int,repo_url:string,default_ref:?string,created_at:?int,updated_at:?int}|null
     */
    public function getRepoById( int $repoId ): ?array {
        $dbr = wfGetDB( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( [ 'repo_id', 'repo_url', 'default_ref', 'created_at', 'updated_at' ] )
            ->from( self::TABLE )
            ->where( [ 'repo_id' => $repoId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return [
            'repo_id' => (int)$row->repo_id,
            'repo_url' => (string)$row->repo_url,
            'default_ref' => $row->default_ref !== null ? (string)$row->default_ref : null,
            'created_at' => $row->created_at !== null ? (int)$row->created_at : null,
            'updated_at' => $row->updated_at !== null ? (int)$row->updated_at : null,
        ];
    }

    /** Small helper used by API: return same as getRepoById but named getRepoInfo */
    // We can see which function name we like more and remove the other probably
    public function getRepoInfo( int $repoId ): ?array {
        return $this->getRepoById( $repoId );
    }

    /** Ensure repo exists by URL and return its ID. */
    public function ensureRepo( string $url ): int {
        $id = $this->getRepoIdByUrl( $url );
        if ( $id !== null ) {
            return $id;
        }
        return $this->addRepo( $url, null );
    }

    /**
     * List all repositories.
     * @return array<int,array{repo_id:int,repo_url:string,default_ref:?string,created_at:?int,updated_at:?int}>
     */
    public function listRepos(): array {
        $dbr = wfGetDB( DB_REPLICA );
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'repo_id', 'repo_url', 'default_ref', 'created_at', 'updated_at' ] )
            ->from( self::TABLE )
            ->orderBy( 'repo_id', SelectQueryBuilder::SORT_ASC )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $out[] = [
                'repo_id' => (int)$row->repo_id,
                'repo_url' => (string)$row->repo_url,
                'default_ref' => $row->default_ref !== null ? (string)$row->default_ref : null,
                'created_at' => $row->created_at !== null ? (int)$row->created_at : null,
                'updated_at' => $row->updated_at !== null ? (int)$row->updated_at : null,
            ];
        }
        return $out;
    }

    /**
     * Update repository fields; always touches updated_at unless explicitly provided.
     * @param array<string,mixed> $fields
     */
    public function updateRepo( int $repoId, array $fields ): void {
        $dbw = wfGetDB( DB_PRIMARY );
        if ( !array_key_exists( 'updated_at', $fields ) ) {
            $fields['updated_at'] = time();
        }
        $dbw->newUpdateQueryBuilder()
            ->update( self::TABLE )
            ->set( $fields )
            ->where( [ 'repo_id' => $repoId ] )
            ->caller( __METHOD__ )
            ->execute();
    }

    /**
     * Delete repository (cascade removes packs/pages).
     */
    public function deleteRepo( int $repoId ): void {
        $dbw = wfGetDB( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'repo_id' => $repoId ] )
            ->caller( __METHOD__ )
            ->execute();
    }
}


