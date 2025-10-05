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
        $repo = new ContentRepo( new ContentRepoId( 1 ), 'https://example.com/repo.yml', 'main', 100, 200 );
        $repoArr = $repo->toArray();
        $this->assertSame( 1, $repoArr['content_repo_id'] );
        $this->assertSame( 'https://example.com/repo.yml', $repoArr['content_repo_url'] );

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

        $pack = new Pack( new PackId( 10 ), new ContentRepoId( 2 ), 'ops', '1.0.0', 'main', 'abc', 111, 5 );
        $packArr = $pack->toArray();
        $this->assertSame( 10, $packArr['pack_id'] );
        $this->assertSame( 2, $packArr['content_repo_id'] );
        $this->assertSame( 'ops', $pack->name() );

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

        $page = new Page( new PageId( 100 ), new PackId( 11 ), 'X', 'X', 0, 1, 5, 'hash', 999 );
        $pageArr = $page->toArray();
        $this->assertSame( 100, $pageArr['page_id'] );
        $this->assertSame( 'X', $pageArr['name'] );

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
    }
}


