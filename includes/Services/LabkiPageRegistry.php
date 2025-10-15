<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\Page as DomainPage;
use LabkiPackManager\Domain\PageId;
use LabkiPackManager\Domain\PackId;
use MediaWiki\MediaWikiServices;

/**
 * Page-level registry service for labki_page table.
 */
final class LabkiPageRegistry {
    private const TABLE = 'labki_page';

    /**
     * Add a page to a pack and return page_id.
     * @param array{name:string,final_title:string,page_namespace:int,wiki_page_id?:?int,last_rev_id?:?int,content_hash?:?string,created_at?:?int} $pageData
     */
    public function addPage( int|PackId $packId, array $pageData ): PageId {
        // Note: This persists registry state for an installed page. Caller must ensure
        // the corresponding MW page exists/was modified successfully before calling this.
        $now = \wfTimestampNow();
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $row = [
            'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId,
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
        return new PageId( $id );
    }

    /**
     * Find an installed page by final title.
     * @return DomainPage|null
     */
    public function getPageByTitle( string $finalTitle ): ?DomainPage {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( DomainPage::FIELDS )
            ->from( self::TABLE )
            ->where( [ 'final_title' => $finalTitle ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return DomainPage::fromRow( $row );
    }

    /** Convenience for API: get page by pack and name */
    public function getPageByName( int|PackId $packId, string $name ): ?DomainPage {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( DomainPage::FIELDS )
            ->from( self::TABLE )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId, 'name' => $name ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return DomainPage::fromRow( $row );
    }

    /**
     * List pages for a pack.
     * @return array<int,DomainPage>
     */
    public function listPagesByPack( int|PackId $packId ): array {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $res = $dbr->newSelectQueryBuilder()
            ->select( DomainPage::FIELDS )
            ->from( self::TABLE )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->orderBy( 'page_id' )
            ->caller( __METHOD__ )
            ->fetchResultSet();
        $out = [];
        foreach ( $res as $row ) {
            $out[] = DomainPage::fromRow( $row );
        }
        return $out;
    }

    /** Fetch a page by its internal page_id */
    public function getPageById( int|PageId $pageId ): ?DomainPage {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( DomainPage::FIELDS )
            ->from( self::TABLE )
            ->where( [ 'page_id' => $pageId instanceof PageId ? $pageId->toInt() : $pageId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        if ( !$row ) {
            return null;
        }
        return DomainPage::fromRow( $row );
    }

    /** Count pages for a given pack_id */
    public function countPagesByPack( int|PackId $packId ): int {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $row = $dbr->newSelectQueryBuilder()
            ->select( 'COUNT(*) AS cnt' )
            ->from( self::TABLE )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->caller( __METHOD__ )
            ->fetchRow();
        return $row ? (int)$row->cnt : 0;
    }

    /**
     * Update a page record; updates updated_at unless provided.
     * @param array<string,mixed> $fields
     */
    public function updatePage( int|PageId $pageId, array $fields ): void {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        if ( !array_key_exists( 'updated_at', $fields ) ) {
            $fields['updated_at'] = \wfTimestampNow();
        }
        $dbw->newUpdateQueryBuilder()
            ->update( self::TABLE )
            ->set( $fields )
            ->where( [ 'page_id' => $pageId instanceof PageId ? $pageId->toInt() : $pageId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Updated page ' . $pageId );
    }

    /**
     * Remove all pages for a pack.
     */
    public function removePagesByPack( int|PackId $packId ): void {
        // Note: Caller should first remove MW pages. This only clears registry records.
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Removed pages for pack ' . $packId );
    }

    /** Remove a single page by its internal page_id */
    public function removePageById( int|PageId $pageId ): bool {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'page_id' => $pageId instanceof PageId ? $pageId->toInt() : $pageId ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Removed page ' . ( $pageId instanceof PageId ? $pageId->toInt() : $pageId ) );
        return true;
    }

    /** Remove a single page by (pack_id, name) */
    public function removePageByName( int|PackId $packId, string $name ): bool {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [
                'pack_id' => $packId instanceof PackId ? $packId->toInt() : $packId,
                'name' => $name,
            ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Removed page by name ' . $name . ' for pack ' . ( $packId instanceof PackId ? $packId->toInt() : $packId ) );
        return true;
    }

    /** Remove a single page by its recorded final title */
    public function removePageByFinalTitle( string $finalTitle ): bool {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbw->newDeleteQueryBuilder()
            ->deleteFrom( self::TABLE )
            ->where( [ 'final_title' => $finalTitle ] )
            ->caller( __METHOD__ )
            ->execute();
        wfDebugLog( 'Labki', 'Removed page by final title ' . $finalTitle );
        return true;
    }

    /** Record a page installation event with required fields */
    public function recordInstalledPage( int|PackId $packId, string $name, string $finalTitle, int $pageNamespace, int $wikiPageId ): PageId {
        return $this->addPage( $packId, [
            'name' => $name,
            'final_title' => $finalTitle,
            'page_namespace' => $pageNamespace,
            'wiki_page_id' => $wikiPageId,
        ] );
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
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
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

    public function getRewriteMapForRepo( int $repoId ): array {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
    
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'lp.name', 'lp.final_title' ] )
            ->from( self::TABLE, 'lp' )
            ->join( 'labki_pack', 'pack', [ 'lp.pack_id = pack.pack_id' ] )
            ->where( [ 'pack.content_repo_id' => $repoId ] )
            ->distinct()
            ->caller( __METHOD__ )
            ->fetchResultSet();
    
        if ( !$res->numRows() ) {
            wfDebugLog( 'Labki', "No rewrite map rows found for repo_id=$repoId" );
            return [];
        }
    
        $map = [];
        foreach ( $res as $row ) {
            $orig = str_replace( ' ', '_', (string)$row->name );
            $final = (string)$row->final_title;
            if ( $orig !== '' && $final !== '' ) {
                $map[$orig] = $final;
            }
        }
    
        wfDebugLog( 'Labki', 'Built rewrite map for repo ' . $repoId . ' (' . count( $map ) . ' entries)' );
    
        return $map;
    }
    
    
}


