<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\PackId;
use MediaWikiIntegrationTestCase;

/**
 * Tests for LabkiPackRegistry
 *
 * @coversDefaultClass \LabkiPackManager\Services\LabkiPackRegistry
 * @group Database
 */
final class LabkiPackRegistryTest extends MediaWikiIntegrationTestCase {

    private function newRef(): ContentRefId {
        $repos = new LabkiRepoRegistry();
        $repoId = $repos->ensureRepoEntry('https://example.com/packs');
        
        $refs = new LabkiRefRegistry();
        return $refs->ensureRefEntry($repoId, 'main');
    }

    private function newReg(): LabkiPackRegistry {
        return new LabkiPackRegistry();
    }

    /**
     * @covers ::addPack
     * @covers ::getPackIdByName
     * @covers ::getPack
     */
    public function testAddAndGetPack(): void {
        $refId = $this->newRef();
        $reg = $this->newReg();

        $pid = $reg->addPack($refId, 'Alpha', ['version' => '1.0.0']);
        $this->assertInstanceOf(PackId::class, $pid);

        $foundId = $reg->getPackIdByName($refId, 'Alpha', '1.0.0');
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
    public function testGetPackIdByName_IgnoresVersion(): void {
        $refId = $this->newRef();
        $reg = $this->newReg();
        
        $a = $reg->addPack($refId, 'NoVer', ['version' => null]);
        $b = $reg->addPack($refId, 'HasVer', ['version' => '2.0']);

        // Should return same pack id regardless of version argument
        $this->assertSame($a->toInt(), $reg->getPackIdByName($refId, 'NoVer', null)?->toInt());
        $this->assertSame($a->toInt(), $reg->getPackIdByName($refId, 'NoVer', 'x')?->toInt());
        $this->assertSame($b->toInt(), $reg->getPackIdByName($refId, 'HasVer', '2.0')?->toInt());
        $this->assertSame($b->toInt(), $reg->getPackIdByName($refId, 'HasVer', null)?->toInt());
    }

    /**
     * @covers ::listPacksByRef
     */
    public function testListPacksByRef(): void {
        $refId = $this->newRef();
        $reg = $this->newReg();
        
        $reg->addPack($refId, 'P1', []);
        $reg->addPack($refId, 'P2', ['version' => 'x']);
        
        $list = $reg->listPacksByRef($refId);
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
        $refId = $this->newRef();
        $reg = $this->newReg();
        
        $pid = $reg->addPack($refId, 'R', ['version' => 'v']);

        // Register same pack (version ignored for uniqueness); should update installed fields and version
        $got = $reg->registerPack($refId, 'R', 'v2', 123);
        $this->assertNotNull($got);
        $this->assertSame($pid->toInt(), $got->toInt());

        $p = $reg->getPack($pid);
        $this->assertNotNull($p);
        $this->assertSame(123, $p->installedBy());
        $this->assertSame('installed', $p->status());
        $this->assertSame('v2', $p->version());
    }

    /**
     * @covers ::updatePack
     */
    public function testUpdatePackTouchUpdatedAt(): void {
        $refId = $this->newRef();
        $reg = $this->newReg();
        
        $pid = $reg->addPack($refId, 'U', ['version' => '1']);
        $before = $reg->getPack($pid);
        $this->assertNotNull($before);
        $prev = $before->updatedAt();

        $reg->updatePack($pid, ['status' => 'removed']);
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
        $refId = $this->newRef();
        $reg = $this->newReg();
        
        $pid = $reg->addPack($refId, 'Del', []);

        $ok = $reg->deletePack($pid);
        $this->assertTrue($ok);
        $this->assertNull($reg->getPack($pid));
    }
}
