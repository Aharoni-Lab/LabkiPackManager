<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

/**
 * Page-level registry service for labki_page table.
 */
final class LabkiPageRegistry {
    private const TABLE = 'labki_page';

    /**
     * Add a page to a pack and return page_id.
     * @param array{name:string,final_title:string,page_namespace:int,wiki_page_id?:?int,last_rev_id?:?int,content_hash?:?string,created_at?:?int} $pageData
     */
    public function addPage( int $packId, array $pageData ): int {
        $now = time();
        $dbw = wfGetDB( DB_PRIMARY );
        $row = [
            'pack_id' => $packId,
            'name' => $pageData['name'],
            'final_title' => $pageData['final_title'],
            'page_namespace' => (int)$pageData['page_namespace'],
            'wiki_page_id' => $pageData['wiki_page_id'] ?? null,
            'last_rev_id' => $pageData['last_rev_id'] ?? null,
            'content_hash' => $pageData['content_hash'] ?? null,
            'created_at' => $pageData['created_at'] ?? $now,
            'updated_at' => $now,
        ];
        $dbw->newInsertQueryBuilder()
            ->insertInto( self::TABLE )
            ->row( $row )
            ->caller( __METHOD__ )
            ->execute();
        $id = (int)$dbw->insertId();
        wfDebugLog( 'Labki', 'Added page ' . $pageData['final_title'] . ' (page_id=' . $id . ', pack_id=' . $packId . ')' );
        return $id;
    }

    /**
     * Find an installed page by final title.
     * @return array{page_id:int,pack_id:int,name:string,final_title:string,page_namespace:int,wiki_page_id:?int,last_rev_id:?int,content_hash:?string,created_at:?int,updated_at:?int}|null
     */
    public function getPageByTitle( string $finalTitle ): ?array {
        $dbr = wfGetDB( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( [ 'page_id','pack_id','name','final_title','page_namespace','wiki_page_id','last_rev_id','content_hash','created_at','updated_at' ] )
            ->from( self::TABLE )
            ->where( [ 'final_title' => $finalTitle ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return [
            'page_id' => (int)$row->page_id,
            'pack_id' => (int)$row->pack_id,
            'name' => (string)$row->name,
            'final_title' => (string)$row->final_title,
            'page_namespace' => (int)$row->page_namespace,
            'wiki_page_id' => $row->wiki_page_id !== null ? (int)$row->wiki_page_id : null,
            'last_rev_id' => $row->last_rev_id !== null ? (int)$row->last_rev_id : null,
            'content_hash' => $row->content_hash !== null ? (string)$row->content_hash : null,
            'created_at' => $row->created_at !== null ? (int)$row->created_at : null,
            'updated_at' => $row->updated_at !== null ? (int)$row->updated_at : null,
        ];
    }

    /**
     * List pages for a pack.
     * @return array<int,array{page_id:int,pack_id:int,name:string,final_title:string,page_namespace:int,wiki_page_id:?int,last_rev_id:?int,content_hash:?string,created_at:?int,updated_at:?int}>
     */
    public function listPagesByPack( int $packId ): array {
        $dbr = wfGetDB( DB_REPLICA );
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'page_id','pack_id','name','final_title','page_namespace','wiki_page_id','last_rev_id','content_hash','created_at','updated_at' ] )
            ->from( self::TABLE )
            ->where( [ 'pack_id' => $packId ] )
            ->orderBy( 'page_id' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $out[] = [
                'page_id' => (int)$row->page_id,
                'pack_id' => (int)$row->pack_id,
                'name' => (string)$row->name,
                'final_title' => (string)$row->final_title,
                'page_namespace' => (int)$row->page_namespace,
                'wiki_page_id' => $row->wiki_page_id !== null ? (int)$row->wiki_page_id : null,
                'last_rev_id' => $row->last_rev_id !== null ? (int)$row->last_rev_id : null,
                'content_hash' => $row->content_hash !== null ? (string)$row->content_hash : null,
                'created_at' => $row->created_at !== null ? (int)$row->created_at : null,
                'updated_at' => $row->updated_at !== null ? (int)$row->updated_at : null,
            ];
        }
        return $out;
    }

    /**
     * Update a page record; updates updated_at unless provided.
     * @param array<string,mixed> $fields
     */
    public function updatePage( int $pageId, array $fields ): void {
        $dbw = wfGetDB( DB_PRIMARY );
        if ( !array_key_exists( 'updated_at', $fields ) ) {
            $fields['updated_at'] = time();
        }
        $dbw->newUpdateQueryBuilder()
            ->update( self::TABLE )
            ->set( $fields )
            ->where( [ 'page_id' => $pageId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Updated page ' . $pageId );
    }

    /**
     * Remove all pages for a pack.
     */
    public function removePagesByPack( int $packId ): void {
        $dbw = wfGetDB( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'pack_id' => $packId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Removed pages for pack ' . $packId );
    }

    /**
     * Detect collisions with existing wiki pages by title.
     * @param string[] $titles
     * @return array<string,int> map of title => page_id (core page table)
     */
    public function getPageCollisions( array $titles ): array {
        if ( $titles === [] ) {
            return [];
        }
        $dbr = wfGetDB( DB_REPLICA );
        // Query core page table; title is stored as page_namespace + page_title
        // We'll check for any final_title collisions ignoring namespace differences only when titles include namespace.
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'page_id', 'page_namespace', 'page_title' ] )
            ->from( 'page' )
            ->where( [ 'page_title' => array_map( [ self::class, 'titleToDBKey' ], $this->stripNamespaces( $titles ) ) ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $title = self::dbKeyToTitle( (int)$row->page_namespace, (string)$row->page_title );
            $out[$title] = (int)$row->page_id;
        }
        return $out;
    }

    /**
     * Convert a text title to DB key (spaces to underscores)
     */
    private static function titleToDBKey( string $t ): string {
        return str_replace( ' ', '_', $t );
    }

    /**
     * Combine namespace and dbkey into a prefixed title string
     */
    private static function dbKeyToTitle( int $ns, string $dbk ): string {
        $text = str_replace( '_', ' ', $dbk );
        if ( $ns === 0 ) {
            return $text;
        }
        // Minimal mapping; MW usually needs Title/NamespaceName, but fine for collision signal
        return $ns . ':' . $text;
    }

    /**
     * For collision query convenience, strip explicit namespace prefix from titles
     * and just return the text part so we can massage into page_title dbkey.
     * @param string[] $titles
     * @return string[]
     */
    private function stripNamespaces( array $titles ): array {
        $out = [];
        foreach ( $titles as $t ) {
            $parts = explode( ':', $t, 2 );
            $out[] = count( $parts ) === 2 ? $parts[1] : $parts[0];
        }
        return $out;
    }
}


