<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRefId;
use MediaWikiIntegrationTestCase;

/**
 * Tests for LabkiRefRegistry
 *
 * @coversDefaultClass \LabkiPackManager\Services\LabkiRefRegistry
 * @group Database
 */
final class LabkiRefRegistryTest extends MediaWikiIntegrationTestCase {

    private function newRepo(): ContentRepoId {
        $repos = new LabkiRepoRegistry();
        return $repos->ensureRepoEntry('https://example.com/test-repo');
    }

    private function newRegistry(): LabkiRefRegistry {
        return new LabkiRefRegistry();
    }

    /**
     * @covers ::__construct
     */
    public function testConstruct_WithoutRepoRegistry_CreatesDefault(): void {
        $registry = new LabkiRefRegistry();
        
        $this->assertInstanceOf(LabkiRefRegistry::class, $registry);
    }

    /**
     * @covers ::__construct
     */
    public function testConstruct_WithRepoRegistry_UsesProvided(): void {
        $repoRegistry = new LabkiRepoRegistry();
        $registry = new LabkiRefRegistry($repoRegistry);
        
        $this->assertInstanceOf(LabkiRefRegistry::class, $registry);
    }

    /**
     * @covers ::addRefEntry
     * @covers ::getRefById
     */
    public function testAddRefEntry_CreatesNewRef(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main');
        
        $this->assertInstanceOf(ContentRefId::class, $refId);
        $this->assertGreaterThan(0, $refId->toInt());
    }

    /**
     * @covers ::addRefEntry
     * @covers ::getRefById
     */
    public function testAddRefEntry_CanBeRetrievedById(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main');
        $ref = $registry->getRefById($refId);
        
        $this->assertNotNull($ref);
        $this->assertSame('main', $ref->sourceRef());
        $this->assertSame($repoId->toInt(), $ref->contentRepoId()->toInt());
    }

    /**
     * @covers ::addRefEntry
     * @covers ::getRefById
     */
    public function testAddRefEntry_WithExtraFields_StoresMetadata(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main', [
            'worktree_path' => '/path/to/worktree',
            'last_commit' => 'abc123',
        ]);
        
        $ref = $registry->getRefById($refId);
        $this->assertNotNull($ref);
        $this->assertSame('/path/to/worktree', $ref->worktreePath());
        $this->assertSame('abc123', $ref->lastCommit());
    }

    /**
     * @covers ::ensureRefEntry
     * @covers ::getRefIdByRepoAndRef
     */
    public function testEnsureRefEntry_WhenNew_CreatesRef(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->ensureRefEntry($repoId, 'develop');
        
        $this->assertInstanceOf(ContentRefId::class, $refId);
        $this->assertNotNull($registry->getRefIdByRepoAndRef($repoId, 'develop'));
    }

    /**
     * @covers ::ensureRefEntry
     * @covers ::getRefIdByRepoAndRef
     */
    public function testEnsureRefEntry_WhenExists_ReturnsExistingId(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId1 = $registry->ensureRefEntry($repoId, 'main');
        $refId2 = $registry->ensureRefEntry($repoId, 'main');
        
        $this->assertSame($refId1->toInt(), $refId2->toInt());
    }

    /**
     * @covers ::ensureRefEntry
     * @covers ::updateRefEntry
     * @covers ::getRefById
     */
    public function testEnsureRefEntry_WhenExists_UpdatesFields(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId1 = $registry->ensureRefEntry($repoId, 'main', ['last_commit' => 'old123']);
        $refId2 = $registry->ensureRefEntry($repoId, 'main', ['last_commit' => 'new456']);
        
        $this->assertSame($refId1->toInt(), $refId2->toInt());
        
        $ref = $registry->getRefById($refId2);
        $this->assertNotNull($ref);
        $this->assertSame('new456', $ref->lastCommit());
    }

    /**
     * @covers ::updateRefEntry
     * @covers ::getRefById
     */
    public function testUpdateRefEntry_UpdatesFields(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main');
        $before = $registry->getRefById($refId);
        $this->assertNotNull($before);
        $beforeUpdated = $before->updatedAt();

        $registry->updateRefEntry($refId, ['manifest_hash' => 'hash123']);
        
        $after = $registry->getRefById($refId);
        $this->assertNotNull($after);
        $this->assertSame('hash123', $after->manifestHash());
        $this->assertNotNull($after->updatedAt());
        
        if ($beforeUpdated !== null) {
            $this->assertGreaterThanOrEqual($beforeUpdated, $after->updatedAt());
        }
    }

    /**
     * @covers ::updateRefEntry
     */
    public function testUpdateRefEntry_WithEmptyFields_DoesNothing(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main');
        
        // Should not throw an exception
        $registry->updateRefEntry($refId, []);
        
        $this->assertTrue(true);
    }

    /**
     * @covers ::getRefIdByRepoAndRef
     */
    public function testGetRefIdByRepoAndRef_WithInt_FindsRef(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main');
        $foundId = $registry->getRefIdByRepoAndRef($repoId->toInt(), 'main');
        
        $this->assertNotNull($foundId);
        $this->assertSame($refId->toInt(), $foundId->toInt());
    }

    /**
     * @covers ::getRefIdByRepoAndRef
     */
    public function testGetRefIdByRepoAndRef_WithContentRepoId_FindsRef(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main');
        $foundId = $registry->getRefIdByRepoAndRef($repoId, 'main');
        
        $this->assertNotNull($foundId);
        $this->assertSame($refId->toInt(), $foundId->toInt());
    }

    /**
     * @covers ::getRefIdByRepoAndRef
     */
    public function testGetRefIdByRepoAndRef_WhenNotExists_ReturnsNull(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $result = $registry->getRefIdByRepoAndRef($repoId, 'nonexistent');
        
        $this->assertNull($result);
    }

    /**
     * @covers ::getRefById
     */
    public function testGetRefById_WhenNotExists_ReturnsNull(): void {
        $registry = $this->newRegistry();

        $result = $registry->getRefById(999999);
        
        $this->assertNull($result);
    }

    /**
     * @covers ::listRefsForRepo
     */
    public function testListRefsForRepo_ReturnsAllRefsForRepo(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $registry->addRefEntry($repoId->toInt(), 'main');
        $registry->addRefEntry($repoId->toInt(), 'develop');
        $registry->addRefEntry($repoId->toInt(), 'v1.0.0');

        $refs = $registry->listRefsForRepo($repoId);
        
        $this->assertGreaterThanOrEqual(3, count($refs));
        
        $refNames = array_map(fn($ref) => $ref->sourceRef(), $refs);
        $this->assertContains('main', $refNames);
        $this->assertContains('develop', $refNames);
        $this->assertContains('v1.0.0', $refNames);
    }

    /**
     * @covers ::listRefsForRepo
     */
    public function testListRefsForRepo_WithInt_ReturnsRefs(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $registry->addRefEntry($repoId->toInt(), 'main');

        $refs = $registry->listRefsForRepo($repoId->toInt());
        
        $this->assertGreaterThanOrEqual(1, count($refs));
    }

    /**
     * @covers ::deleteRef
     * @covers ::getRefById
     */
    public function testDeleteRef_RemovesRef(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main');
        $this->assertNotNull($registry->getRefById($refId));

        $registry->deleteRef($refId);
        
        $this->assertNull($registry->getRefById($refId));
    }

    /**
     * @covers ::deleteRef
     * @covers ::getRefIdByRepoAndRef
     */
    public function testDeleteRef_RemovesFromLookup(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $refId = $registry->addRefEntry($repoId->toInt(), 'main');
        $this->assertNotNull($registry->getRefIdByRepoAndRef($repoId, 'main'));

        $registry->deleteRef($refId);
        
        $this->assertNull($registry->getRefIdByRepoAndRef($repoId, 'main'));
    }

    /**
     * @covers ::getWorktreePath
     */
    public function testGetWorktreePath_ReturnsPath(): void {
        $repoId = $this->newRepo();
        $registry = $this->newRegistry();

        $registry->addRefEntry($repoId->toInt(), 'main', [
            'worktree_path' => '/path/to/worktree'
        ]);

        $path = $registry->getWorktreePath($repoId, 'main');
        
        $this->assertSame('/path/to/worktree', $path);
    }
}

