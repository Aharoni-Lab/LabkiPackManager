<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services {
    use LabkiPackManager\Services\LabkiRepoRegistry;
    use LabkiPackManager\Domain\ContentRepoId;

    /**
     * @coversDefaultClass \LabkiPackManager\Services\LabkiRepoRegistry
     * @group Database
     */
    final class LabkiRepoRegistryTest extends \MediaWikiIntegrationTestCase {
        private function newRegistry(): LabkiRepoRegistry {
            return new LabkiRepoRegistry();
        }

        /**
         * @covers ::addRepo
         * @covers ::getRepoIdByUrl
         * @covers ::getRepoById
         */
        public function testAddAndGetRepo(): void {
            $r = $this->newRegistry();
            $url = 'https://example.com/repo/';

            $id = $r->addRepo($url, 'main');
            $this->assertInstanceOf(ContentRepoId::class, $id);

            // Normalization strips trailing slash
            $fetchedId = $r->getRepoIdByUrl('https://example.com/repo');
            $this->assertNotNull($fetchedId);
            $this->assertSame($id->toInt(), $fetchedId->toInt());

            $info = $r->getRepoById($id);
            $this->assertNotNull($info);
            $this->assertSame('https://example.com/repo', $info->url());
            $this->assertSame('main', $info->defaultRef());
        }

        /**
         * @covers ::ensureRepo
         * @covers ::getRepoIdByUrl
         */
        public function testEnsureRepoIdempotent(): void {
            $r = $this->newRegistry();
            $url = 'https://example.com/ensure';
            $id1 = $r->ensureRepo($url);
            $id2 = $r->ensureRepo($url . '/');
            $this->assertSame($id1->toInt(), $id2->toInt());
        }

        /**
         * @covers ::updateRepo
         * @covers ::getRepoById
         */
        public function testUpdateRepoTouchesUpdatedAt(): void {
            $r = $this->newRegistry();
            $id = $r->addRepo('https://example.com/upd', null);

            $before = $r->getRepoById($id);
            $this->assertNotNull($before);
            $beforeUpdated = $before->updatedAt();

            // Update default_ref and rely on auto-updated updated_at
            $r->updateRepo($id, [ 'default_ref' => 'dev' ]);
            $after = $r->getRepoById($id);
            $this->assertNotNull($after);
            $this->assertSame('dev', $after->defaultRef());
            $this->assertNotNull($after->updatedAt());
            if ($beforeUpdated !== null) {
                $this->assertGreaterThanOrEqual($beforeUpdated, $after->updatedAt());
            }
        }

        /**
         * @covers ::listRepos
         * @covers ::deleteRepo
         */
        public function testListAndDelete(): void {
            $r = $this->newRegistry();
            $idA = $r->ensureRepo('https://ex.test/a');
            $idB = $r->ensureRepo('https://ex.test/b');

            $list = $r->listRepos();
            $this->assertGreaterThanOrEqual(2, count($list));

            // Delete one and ensure it's gone
            $r->deleteRepo($idA);
            $this->assertNull($r->getRepoById($idA));
            $this->assertNotNull($r->getRepoById($idB));
        }
    }
}


