<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services {
    use LabkiPackManager\Services\LabkiRepoRegistry;
    use LabkiPackManager\Services\LabkiPackRegistry;
    use LabkiPackManager\Domain\ContentRepoId;
    use LabkiPackManager\Domain\PackId;

    /**
     * @coversDefaultClass \LabkiPackManager\Services\LabkiPackRegistry
     * @group Database
     */
    final class LabkiPackRegistryTest extends \MediaWikiIntegrationTestCase {
        private function newRepo(): ContentRepoId {
            $repos = new LabkiRepoRegistry();
            return $repos->ensureRepo('https://example.com/packs');
        }

        private function newReg(): LabkiPackRegistry { return new LabkiPackRegistry(); }

        /**
         * @covers ::addPack
         * @covers ::getPackIdByName
         * @covers ::getPack
         */
        public function testAddAndGetPack(): void {
            $repoId = $this->newRepo();
            $reg = $this->newReg();

            $pid = $reg->addPack($repoId, 'Alpha', [ 'version' => '1.0.0' ]);
            $this->assertInstanceOf(PackId::class, $pid);

            $foundId = $reg->getPackIdByName($repoId, 'Alpha', '1.0.0');
            $this->assertNotNull($foundId);
            $this->assertSame($pid->toInt(), $foundId->toInt());

            $pack = $reg->getPack($pid);
            $this->assertNotNull($pack);
            $this->assertSame('Alpha', $pack->name());
            $this->assertSame('1.0.0', $pack->version());
        }

        /**
         * @covers ::getPackIdByName
         */
        public function testGetPackIdByName_NullVersionBranches(): void {
            $repoId = $this->newRepo();
            $reg = $this->newReg();
            $a = $reg->addPack($repoId, 'NoVer', [ 'version' => null ]);
            $b = $reg->addPack($repoId, 'HasVer', [ 'version' => '2.0' ]);

            $this->assertSame($a->toInt(), $reg->getPackIdByName($repoId, 'NoVer', null)?->toInt());
            $this->assertSame($b->toInt(), $reg->getPackIdByName($repoId, 'HasVer', '2.0')?->toInt());
            $this->assertNull($reg->getPackIdByName($repoId, 'HasVer', null));
        }

        /**
         * @covers ::listPacksByRepo
         */
        public function testListPacksByRepo(): void {
            $repoId = $this->newRepo();
            $reg = $this->newReg();
            $reg->addPack($repoId, 'P1', []);
            $reg->addPack($repoId, 'P2', [ 'version' => 'x' ]);
            $list = $reg->listPacksByRepo($repoId);
            $this->assertGreaterThanOrEqual(2, count($list));
            $names = array_map(fn($p) => $p->name(), $list);
            $this->assertContains('P1', $names);
            $this->assertContains('P2', $names);
        }

        /**
         * @covers ::registerPack
         * @covers ::updatePack
         */
        public function testRegisterPackUpdatesWhenExists(): void {
            $repoId = $this->newRepo();
            $reg = $this->newReg();
            $pid = $reg->addPack($repoId, 'R', [ 'version' => 'v' ]);

            // Register same pack/version; should update installed fields
            $got = $reg->registerPack($repoId, 'R', 'v', 123);
            $this->assertNotNull($got);
            $this->assertSame($pid->toInt(), $got->toInt());

            $p = $reg->getPack($pid);
            $this->assertNotNull($p);
            $this->assertSame(123, $p->installedBy());
            $this->assertSame('installed', $p->status());
        }

        /**
         * @covers ::updatePack
         */
        public function testUpdatePackTouchUpdatedAt(): void {
            $repoId = $this->newRepo();
            $reg = $this->newReg();
            $pid = $reg->addPack($repoId, 'U', [ 'version' => '1' ]);
            $before = $reg->getPack($pid);
            $this->assertNotNull($before);
            $prev = $before->updatedAt();

            $reg->updatePack($pid, [ 'status' => 'removed' ]);
            $after = $reg->getPack($pid);
            $this->assertNotNull($after);
            $this->assertSame('removed', $after->status());
            $this->assertNotNull($after->updatedAt());
            if ($prev !== null) {
                $this->assertGreaterThanOrEqual($prev, $after->updatedAt());
            }
        }

        /**
         * @covers ::deletePack
         * @covers ::removePackAndPages
         */
        public function testDeletePackRemovesRow(): void {
            $repoId = $this->newRepo();
            $reg = $this->newReg();
            $pid = $reg->addPack($repoId, 'Del', []);

            $ok = $reg->deletePack($pid);
            $this->assertTrue($ok);
            $this->assertNull($reg->getPack($pid));
        }
    }
}


