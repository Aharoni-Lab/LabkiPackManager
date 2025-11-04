<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Domain;

use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRef;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\Page;
use LabkiPackManager\Domain\PageId;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Domain\ContentRepo
 * @covers \LabkiPackManager\Domain\ContentRef
 * @covers \LabkiPackManager\Domain\Pack
 * @covers \LabkiPackManager\Domain\Page
 */
class EntitiesTest extends TestCase {
    public function testContentRepoToArrayAndFromRow(): void {
        // Test TABLE and FIELDS constants
        $this->assertSame( 'labki_content_repo', ContentRepo::TABLE );
        $this->assertSame(
            [ 'content_repo_id', 'content_repo_url', 'default_ref', 'bare_path', 'last_fetched', 'created_at', 'updated_at' ],
            ContentRepo::FIELDS
        );

        // Test toArray()
        $repo = new ContentRepo(
            new ContentRepoId( 1 ),
            'https://github.com/example/repo',
            'main',
            '/path/to/bare',
            1234567890,
            100,
            200
        );
        $repoArr = $repo->toArray();
        $this->assertSame( 1, $repoArr['content_repo_id'] );
        $this->assertSame( 'https://github.com/example/repo', $repoArr['content_repo_url'] );
        $this->assertSame( 'main', $repoArr['default_ref'] );
        $this->assertSame( '/path/to/bare', $repoArr['bare_path'] );
        $this->assertSame( 1234567890, $repoArr['last_fetched'] );
        $this->assertSame( 100, $repoArr['created_at'] );
        $this->assertSame( 200, $repoArr['updated_at'] );

        // Test fromRow()
        $repoRow = (object)[
            'content_repo_id' => 2,
            'content_repo_url' => 'https://github.com/example/other',
            'default_ref' => 'dev',
            'bare_path' => '/other/path',
            'last_fetched' => 9876543210,
            'created_at' => 1,
            'updated_at' => 2,
        ];
        $repo2 = ContentRepo::fromRow( $repoRow );
        $this->assertSame( 2, $repo2->toArray()['content_repo_id'] );
        $this->assertSame( 'dev', $repo2->toArray()['default_ref'] );
        $this->assertSame( '/other/path', $repo2->toArray()['bare_path'] );
    }

    public function testContentRefToArrayAndFromRow(): void {
        // Test TABLE and FIELDS constants
        $this->assertSame( 'labki_content_ref', ContentRef::TABLE );
        $this->assertSame(
            [
                'content_ref_id',
                'content_repo_id',
                'source_ref',
                'content_ref_name',
                'last_commit',
                'manifest_hash',
                'manifest_last_parsed',
                'worktree_path',
                'created_at',
                'updated_at',
            ],
            ContentRef::FIELDS
        );

        // Test toArray()
        $ref = new ContentRef(
            new ContentRefId( 5 ),
            new ContentRepoId( 1 ),
            'main',
            'Main Branch',
            'abc123def456',
            'hash789',
            1234567890,
            '/path/to/worktree',
            100,
            200
        );
        $refArr = $ref->toArray();
        $this->assertSame( 5, $refArr['content_ref_id'] );
        $this->assertSame( 1, $refArr['content_repo_id'] );
        $this->assertSame( 'main', $refArr['source_ref'] );
        $this->assertSame( 'Main Branch', $refArr['content_ref_name'] );
        $this->assertSame( 'abc123def456', $refArr['last_commit'] );
        $this->assertSame( 'hash789', $refArr['manifest_hash'] );
        $this->assertSame( 1234567890, $refArr['manifest_last_parsed'] );
        $this->assertSame( '/path/to/worktree', $refArr['worktree_path'] );
        $this->assertSame( 100, $refArr['created_at'] );
        $this->assertSame( 200, $refArr['updated_at'] );

        // Test fromRow()
        $refRow = (object)[
            'content_ref_id' => 6,
            'content_repo_id' => 2,
            'source_ref' => 'v1.0.0',
            'content_ref_name' => 'Version 1.0',
            'last_commit' => 'xyz789',
            'manifest_hash' => 'hash456',
            'manifest_last_parsed' => 9876543210,
            'worktree_path' => '/other/worktree',
            'created_at' => 50,
            'updated_at' => 150,
        ];
        $ref2 = ContentRef::fromRow( $refRow );
        $this->assertSame( 6, $ref2->id()->toInt() );
        $this->assertSame( 2, $ref2->repoId()->toInt() );
        $this->assertSame( 'v1.0.0', $ref2->sourceRef() );
        $this->assertSame( 'Version 1.0', $ref2->refName() );
        $this->assertSame( 'xyz789', $ref2->lastCommit() );
        $this->assertSame( 'hash456', $ref2->manifestHash() );
        $this->assertSame( 9876543210, $ref2->manifestLastParsed() );
        $this->assertSame( '/other/worktree', $ref2->worktreePath() );
        $this->assertSame( 50, $ref2->createdAt() );
        $this->assertSame( 150, $ref2->updatedAt() );

        // Test with null values
        $refRowNull = (object)[
            'content_ref_id' => 7,
            'content_repo_id' => 1,
            'source_ref' => 'dev',
            'content_ref_name' => null,
            'last_commit' => null,
            'manifest_hash' => null,
            'manifest_last_parsed' => null,
            'worktree_path' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
        $ref3 = ContentRef::fromRow( $refRowNull );
        $this->assertSame( 7, $ref3->id()->toInt() );
        $this->assertSame( 1, $ref3->repoId()->toInt() );
        $this->assertSame( 'dev', $ref3->sourceRef() );
        $this->assertNull( $ref3->refName() );
        $this->assertNull( $ref3->lastCommit() );
        $this->assertNull( $ref3->manifestHash() );
        $this->assertNull( $ref3->manifestLastParsed() );
        $this->assertNull( $ref3->worktreePath() );
    }

    public function testPackToArrayAndFromRow(): void {
        // Test TABLE and FIELDS constants
        $this->assertSame( 'labki_pack', Pack::TABLE );
        $this->assertSame(
            [ 'pack_id', 'content_ref_id', 'name', 'version', 'source_commit', 'installed_at', 'installed_by', 'updated_at', 'status' ],
            Pack::FIELDS
        );

        // Test toArray()
        $pack = new Pack(
            new PackId( 10 ),
            new ContentRefId( 2 ),
            'lab-operations',
            '1.0.0',
            'abc123',
            111,
            5,
            2222,
            'installed'
        );
        $packArr = $pack->toArray();
        $this->assertSame( 10, $packArr['pack_id'] );
        $this->assertSame( 2, $packArr['content_ref_id'] );
        $this->assertSame( 'lab-operations', $pack->name() );
        $this->assertSame( '1.0.0', $packArr['version'] );
        $this->assertSame( 'abc123', $packArr['source_commit'] );
        $this->assertSame( 111, $packArr['installed_at'] );
        $this->assertSame( 5, $packArr['installed_by'] );
        $this->assertSame( 2222, $packArr['updated_at'] );
        $this->assertSame( 'installed', $packArr['status'] );

        // Test fromRow()
        $packRow = (object)[
            'pack_id' => 11,
            'content_ref_id' => 2,
            'name' => 'chemistry-pack',
            'version' => '0.1.0',
            'source_commit' => 'def456',
            'installed_at' => 222,
            'installed_by' => null,
            'updated_at' => null,
            'status' => 'installed',
        ];
        $pack2 = Pack::fromRow( $packRow );
        $this->assertSame( 11, $pack2->id()->toInt() );
        $this->assertSame( 2, $pack2->contentRefId()->toInt() );
        $this->assertSame( 'chemistry-pack', $pack2->name() );
        $this->assertSame( '0.1.0', $pack2->version() );
        $this->assertSame( 'def456', $pack2->sourceCommit() );
        $this->assertSame( 222, $pack2->installedAt() );
        $this->assertNull( $pack2->installedBy() );
        $this->assertNull( $pack2->updatedAt() );
        $this->assertSame( 'installed', $pack2->status() );
    }

    public function testPageToArrayAndFromRow(): void {
        // Test TABLE and FIELDS constants
        $this->assertSame( 'labki_page', Page::TABLE );
        $this->assertSame(
            [ 'page_id', 'pack_id', 'name', 'final_title', 'page_namespace', 'wiki_page_id', 'last_rev_id', 'content_hash', 'created_at', 'updated_at' ],
            Page::FIELDS
        );

        // Test toArray()
        $page = new Page(
            new PageId( 100 ),
            new PackId( 11 ),
            'Safety_Protocol',
            'Lab:Safety_Protocol',
            0,
            1,
            5,
            'hash123',
            999,
            12345
        );
        $pageArr = $page->toArray();
        $this->assertSame( 100, $pageArr['page_id'] );
        $this->assertSame( 11, $pageArr['pack_id'] );
        $this->assertSame( 'Safety_Protocol', $pageArr['name'] );
        $this->assertSame( 'Lab:Safety_Protocol', $pageArr['final_title'] );
        $this->assertSame( 0, $pageArr['page_namespace'] );
        $this->assertSame( 1, $pageArr['wiki_page_id'] );
        $this->assertSame( 5, $pageArr['last_rev_id'] );
        $this->assertSame( 'hash123', $pageArr['content_hash'] );
        $this->assertSame( 999, $pageArr['created_at'] );
        $this->assertSame( 12345, $pageArr['updated_at'] );

        // Test fromRow()
        $pageRow = (object)[
            'page_id' => 200,
            'pack_id' => 11,
            'name' => 'Equipment_List',
            'final_title' => 'Lab:Equipment_List',
            'page_namespace' => 0,
            'wiki_page_id' => 2,
            'last_rev_id' => 7,
            'content_hash' => 'hash456',
            'created_at' => 123,
            'updated_at' => 456,
        ];
        $page2 = Page::fromRow( $pageRow );
        $this->assertSame( 200, $page2->id()->toInt() );
        $this->assertSame( 11, $page2->packId()->toInt() );
        $this->assertSame( 'Equipment_List', $page2->name() );
        $this->assertSame( 'Lab:Equipment_List', $page2->finalTitle() );
        $this->assertSame( 0, $page2->namespace() );
        $this->assertSame( 2, $page2->wikiPageId() );
        $this->assertSame( 7, $page2->lastRevId() );
        $this->assertSame( 'hash456', $page2->contentHash() );
        $this->assertSame( 123, $page2->createdAt() );
        $this->assertSame( 456, $page2->updatedAt() );

        // Test with null values
        $pageRowNull = (object)[
            'page_id' => 300,
            'pack_id' => 12,
            'name' => 'New_Page',
            'final_title' => 'New_Page',
            'page_namespace' => 0,
            'wiki_page_id' => null,
            'last_rev_id' => null,
            'content_hash' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
        $page3 = Page::fromRow( $pageRowNull );
        $this->assertSame( 300, $page3->id()->toInt() );
        $this->assertNull( $page3->wikiPageId() );
        $this->assertNull( $page3->lastRevId() );
        $this->assertNull( $page3->contentHash() );
        $this->assertNull( $page3->createdAt() );
        $this->assertNull( $page3->updatedAt() );
    }
}


