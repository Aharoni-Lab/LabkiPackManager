<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Domain;

use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\Page;
use LabkiPackManager\Domain\PageId;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Domain\ContentRepo
 * @covers \LabkiPackManager\Domain\Pack
 * @covers \LabkiPackManager\Domain\Page
 */
class EntitiesTest extends TestCase {
    public function testToArrayAndFromRow(): void {
        // ContentRepo
        $this->assertSame( 'labki_content_repo', ContentRepo::TABLE );
        $this->assertSame(
            [ 'content_repo_id', 'content_repo_url', 'default_ref', 'created_at', 'updated_at' ],
            ContentRepo::FIELDS
        );

        $repo = new ContentRepo( new ContentRepoId( 1 ), 'https://example.com/repo.yml', 'main', 100, 200 );
        $repoArr = $repo->toArray();
        $this->assertSame( 1, $repoArr['content_repo_id'] );
        $this->assertSame( 'https://example.com/repo.yml', $repoArr['content_repo_url'] );
        $this->assertSame( 'main', $repoArr['default_ref'] );
        $this->assertSame( 100, $repoArr['created_at'] );
        $this->assertSame( 200, $repoArr['updated_at'] );

        $repoRow = (object)[
            'content_repo_id' => 2,
            'content_repo_url' => 'https://example.com/r.yml',
            'default_ref' => 'dev',
            'created_at' => 1,
            'updated_at' => 2,
        ];
        $repo2 = ContentRepo::fromRow( $repoRow );
        $this->assertSame( 2, $repo2->id()->toInt() );
        $this->assertSame( 'dev', $repo2->defaultRef() );

        // Pack
        $this->assertSame( 'labki_pack', Pack::TABLE );
        $this->assertSame(
            [ 'pack_id', 'content_repo_id', 'name', 'version', 'source_ref', 'source_commit', 'installed_at', 'installed_by', 'updated_at', 'status' ],
            Pack::FIELDS
        );

        $pack = new Pack( new PackId( 10 ), new ContentRepoId( 2 ), 'ops', '1.0.0', 'main', 'abc', 111, 5, 2222, 'installed' );
        $packArr = $pack->toArray();
        $this->assertSame( 10, $packArr['pack_id'] );
        $this->assertSame( 2, $packArr['content_repo_id'] );
        $this->assertSame( 'ops', $pack->name() );
        $this->assertSame( '1.0.0', $packArr['version'] );
        $this->assertSame( 'main', $packArr['source_ref'] );
        $this->assertSame( 'abc', $packArr['source_commit'] );
        $this->assertSame( 111, $packArr['installed_at'] );
        $this->assertSame( 5, $packArr['installed_by'] );
        $this->assertSame( 2222, $packArr['updated_at'] );
        $this->assertSame( 'installed', $packArr['status'] );

        $packRow = (object)[
            'pack_id' => 11,
            'content_repo_id' => 2,
            'name' => 'chem',
            'version' => '0.1',
            'source_ref' => null,
            'source_commit' => 'def',
            'installed_at' => 222,
            'installed_by' => null,
            'updated_at' => null,
            'status' => 'installed',
        ];
        $pack2 = Pack::fromRow( $packRow );
        $this->assertSame( 11, $pack2->id()->toInt() );
        $this->assertSame( 'chem', $pack2->name() );
        $this->assertSame( '0.1', $pack2->version() );
        $this->assertNull( $pack2->sourceRef() );
        $this->assertSame( 'def', $pack2->sourceCommit() );
        $this->assertSame( 222, $pack2->installedAt() );
        $this->assertNull( $pack2->installedBy() );
        $this->assertNull( $pack2->updatedAt() );
        $this->assertSame( 'installed', $pack2->status() );

        // Page
        $this->assertSame( 'labki_page', Page::TABLE );
        $this->assertSame(
            [ 'page_id', 'pack_id', 'name', 'final_title', 'page_namespace', 'wiki_page_id', 'last_rev_id', 'content_hash', 'created_at', 'updated_at' ],
            Page::FIELDS
        );

        $page = new Page( new PageId( 100 ), new PackId( 11 ), 'X', 'X', 0, 1, 5, 'hash', 999, 12345 );
        $pageArr = $page->toArray();
        $this->assertSame( 100, $pageArr['page_id'] );
        $this->assertSame( 'X', $pageArr['name'] );
        $this->assertSame( 999, $pageArr['created_at'] );
        $this->assertSame( 12345, $pageArr['updated_at'] );

        $pageRow = (object)[
            'page_id' => 200,
            'pack_id' => 11,
            'name' => 'Y',
            'final_title' => 'Y',
            'page_namespace' => 0,
            'wiki_page_id' => 2,
            'last_rev_id' => 7,
            'content_hash' => 'zzz',
            'created_at' => 123,
            'updated_at' => 456,
        ];
        $page2 = Page::fromRow( $pageRow );
        $this->assertSame( 200, $page2->id()->toInt() );
        $this->assertSame( 'Y', $page2->name() );
        $this->assertSame( 456, $page2->updatedAt() );
    }
}


