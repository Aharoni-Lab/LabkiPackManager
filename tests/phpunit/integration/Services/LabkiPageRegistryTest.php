<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services {
    use LabkiPackManager\Services\LabkiRepoRegistry;
    use LabkiPackManager\Services\LabkiPackRegistry;
    use LabkiPackManager\Services\LabkiPageRegistry;
    use LabkiPackManager\Domain\PackId;
    use LabkiPackManager\Domain\PageId;

    /**
     * @coversDefaultClass \LabkiPackManager\Services\LabkiPageRegistry
     * @group Database
     */
    final class LabkiPageRegistryTest extends \MediaWikiIntegrationTestCase {
        private function newPack(): PackId {
            $repos = new LabkiRepoRegistry();
            $repoId = $repos->ensureRepo('https://example.com/repo-pages');
            $packs = new LabkiPackRegistry();
            return $packs->addPack($repoId, 'PackWithPages', []);
        }

        private function reg(): LabkiPageRegistry { return new LabkiPageRegistry(); }

        /**
         * @covers ::addPage
         * @covers ::getPageById
         * @covers ::getPageByTitle
         * @covers ::getPageByName
         */
        public function testAddAndGet(): void {
            $packId = $this->newPack();
            $reg = $this->reg();
            $pid = $reg->addPage($packId, [
                'name' => 'Home',
                'final_title' => 'Main Page',
                'page_namespace' => 0,
                'wiki_page_id' => 10,
            ]);

            $this->assertInstanceOf(PageId::class, $pid);
            $byId = $reg->getPageById($pid);
            $this->assertNotNull($byId);
            $this->assertSame('Home', $byId->name());
            $this->assertSame('Main Page', $byId->finalTitle());

            $byTitle = $reg->getPageByTitle('Main Page');
            $this->assertNotNull($byTitle);
            $this->assertSame($byId->id()->toInt(), $byTitle->id()->toInt());

            $byName = $reg->getPageByName($packId, 'Home');
            $this->assertNotNull($byName);
            $this->assertSame($byId->id()->toInt(), $byName->id()->toInt());
        }

        /**
         * @covers ::listPagesByPack
         * @covers ::countPagesByPack
         */
        public function testListAndCount(): void {
            $packId = $this->newPack();
            $reg = $this->reg();
            $reg->addPage($packId, [ 'name' => 'A', 'final_title' => 'A', 'page_namespace' => 0 ]);
            $reg->addPage($packId, [ 'name' => 'B', 'final_title' => 'B', 'page_namespace' => 0 ]);

            $list = $reg->listPagesByPack($packId);
            $this->assertCount(2, $list);
            $count = $reg->countPagesByPack($packId);
            $this->assertSame(2, $count);
        }

        /**
         * @covers ::updatePage
         */
        public function testUpdateTouchesUpdatedAt(): void {
            $packId = $this->newPack();
            $reg = $this->reg();
            $pid = $reg->addPage($packId, [ 'name' => 'U', 'final_title' => 'U', 'page_namespace' => 0 ]);
            $before = $reg->getPageById($pid);
            $this->assertNotNull($before);
            $prev = $before->updatedAt();

            $reg->updatePage($pid, [ 'content_hash' => 'abc' ]);
            $after = $reg->getPageById($pid);
            $this->assertNotNull($after);
            $this->assertSame('abc', $after->contentHash());
            $this->assertNotNull($after->updatedAt());
            if ($prev !== null) {
                $this->assertGreaterThanOrEqual($prev, $after->updatedAt());
            }
        }

        /**
         * @covers ::removePageById
         * @covers ::removePageByName
         * @covers ::removePageByFinalTitle
         * @covers ::removePagesByPack
         */
        public function testRemovals(): void {
            $packId = $this->newPack();
            $reg = $this->reg();
            $p1 = $reg->addPage($packId, [ 'name' => 'X', 'final_title' => 'X', 'page_namespace' => 0 ]);
            $p2 = $reg->addPage($packId, [ 'name' => 'Y', 'final_title' => 'Y', 'page_namespace' => 0 ]);

            $this->assertTrue($reg->removePageById($p1));
            $this->assertNull($reg->getPageById($p1));

            $this->assertTrue($reg->removePageByName($packId, 'Y'));
            $this->assertNull($reg->getPageByName($packId, 'Y'));

            $reg->addPage($packId, [ 'name' => 'Z', 'final_title' => 'Z', 'page_namespace' => 0 ]);
            $this->assertTrue($reg->removePageByFinalTitle('Z'));
            $this->assertNull($reg->getPageByTitle('Z'));

            $reg->addPage($packId, [ 'name' => 'A', 'final_title' => 'A', 'page_namespace' => 0 ]);
            $reg->addPage($packId, [ 'name' => 'B', 'final_title' => 'B', 'page_namespace' => 0 ]);
            $reg->removePagesByPack($packId);
            $this->assertSame(0, $reg->countPagesByPack($packId));
        }

        /**
         * @covers ::getPageCollisions
         */
        public function testGetPageCollisionsAgainstCorePageTable(): void {
            // Create core page via services to ensure compatibility with modern MW
            $services = \MediaWiki\MediaWikiServices::getInstance();
            $title = $services->getTitleFactory()->newFromText('Collision Title');
            $wikiPage = $services->getWikiPageFactory()->newFromTitle($title);
            $user = $this->getTestUser()->getUser();
            $content = new \WikitextContent('x');
            $status = $wikiPage->doUserEditContent($content, $user, 'summary');
            $this->assertTrue($status->isOK());

            $reg = $this->reg();
            $map = $reg->getPageCollisions(['Collision Title', 'Other Title']);
            $this->assertArrayHasKey('Collision Title', $map);
            $this->assertIsInt($map['Collision Title']);
        }
    }
}


