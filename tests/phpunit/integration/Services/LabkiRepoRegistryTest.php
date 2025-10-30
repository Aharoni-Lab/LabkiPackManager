<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Domain\ContentRepoId;
use MediaWikiIntegrationTestCase;

/**
 * Tests for LabkiRepoRegistry
 *
 * @coversDefaultClass \LabkiPackManager\Services\LabkiRepoRegistry
 * @group Database
 */
final class LabkiRepoRegistryTest extends MediaWikiIntegrationTestCase {

    private function newRegistry(): LabkiRepoRegistry {
        return new LabkiRepoRegistry();
    }

    /**
     * @covers ::addRepoEntry
     * @covers ::getRepoId
     * @covers ::getRepo
     */
    public function testAddRepoEntry_CreatesNewRepo(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/repo';

        $id = $registry->addRepoEntry($url, ['default_ref' => 'main']);
        
        $this->assertInstanceOf(ContentRepoId::class, $id);
        $this->assertGreaterThan(0, $id->toInt());
    }

    /**
     * @covers ::addRepoEntry
     * @covers ::getRepoId
     * @covers ::getRepo
     */
    public function testAddRepoEntry_CanBeRetrievedByUrl(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/repo';

        $id = $registry->addRepoEntry($url);
        $fetchedId = $registry->getRepoId($url);
        
        $this->assertNotNull($fetchedId);
        $this->assertSame($id->toInt(), $fetchedId->toInt());
    }

    /**
     * @covers ::addRepoEntry
     * @covers ::getRepo
     */
    public function testGetRepo_WithIntId_ReturnsCompleteRepoObject(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/repo-int';

        $id = $registry->addRepoEntry($url);
        $repo = $registry->getRepo($id->toInt());
        
        $this->assertNotNull($repo);
        $this->assertSame($url, $repo->url());
        $this->assertSame('main', $repo->defaultRef());
    }

    /**
     * @covers ::addRepoEntry
     * @covers ::getRepo
     */
    public function testGetRepo_WithContentRepoId_ReturnsCompleteRepoObject(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/repo-object';

        $id = $registry->addRepoEntry($url);
        $repo = $registry->getRepo($id);
        
        $this->assertNotNull($repo);
        $this->assertSame($url, $repo->url());
        $this->assertSame('main', $repo->defaultRef());
    }

    /**
     * @covers ::addRepoEntry
     * @covers ::getRepo
     * @covers ::getRepoId
     */
    public function testGetRepo_WithUrlString_ReturnsCompleteRepoObject(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/repo-url';

        $id = $registry->addRepoEntry($url);
        $repo = $registry->getRepo($url);
        
        $this->assertNotNull($repo);
        $this->assertSame($url, $repo->url());
        $this->assertSame('main', $repo->defaultRef());
        $this->assertSame($id->toInt(), $repo->id()->toInt());
    }

    /**
     * @covers ::ensureRepoEntry
     * @covers ::getRepoId
     */
    public function testEnsureRepoEntry_WhenNew_CreatesRepo(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/ensure-new';
        
        $id = $registry->ensureRepoEntry($url);
        
        $this->assertInstanceOf(ContentRepoId::class, $id);
        $this->assertNotNull($registry->getRepoId($url));
    }

    /**
     * @covers ::ensureRepoEntry
     * @covers ::getRepoId
     */
    public function testEnsureRepoEntry_WhenExists_ReturnsExistingId(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/ensure-existing';
        
        $id1 = $registry->ensureRepoEntry($url);
        $id2 = $registry->ensureRepoEntry($url);
        
        $this->assertSame($id1->toInt(), $id2->toInt());
    }

    /**
     * @covers ::ensureRepoEntry
     * @covers ::updateRepoEntry
     * @covers ::getRepo
     */
    public function testEnsureRepoEntry_WhenExists_UpdatesFields(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/ensure-update';
        
        $id1 = $registry->ensureRepoEntry($url, ['bare_path' => '/path/old']);
        $id2 = $registry->ensureRepoEntry($url, ['bare_path' => '/path/new']);
        
        $this->assertSame($id1->toInt(), $id2->toInt());
        
        $repo = $registry->getRepo($id2);
        $this->assertNotNull($repo);
        $this->assertSame('/path/new', $repo->barePath());
    }

    /**
     * @covers ::updateRepoEntry
     * @covers ::getRepo
     */
    public function testUpdateRepoEntry_UpdatesFields(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/update';
        
        $id = $registry->addRepoEntry($url);
        $before = $registry->getRepo($id);
        $this->assertNotNull($before);
        $beforeUpdated = $before->updatedAt();

        $registry->updateRepoEntry($id, ['default_ref' => 'dev']);
        
        $after = $registry->getRepo($id);
        $this->assertNotNull($after);
        $this->assertSame('dev', $after->defaultRef());
        $this->assertNotNull($after->updatedAt());
        
        if ($beforeUpdated !== null) {
            $this->assertGreaterThanOrEqual($beforeUpdated, $after->updatedAt());
        }
    }

    /**
     * @covers ::updateRepoEntry
     */
    public function testUpdateRepoEntry_WithEmptyFields_DoesNothing(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/update-empty';
        
        $id = $registry->addRepoEntry($url);
        
        // Should not throw an exception
        $registry->updateRepoEntry($id, []);
        
        $this->assertTrue(true);
    }

    /**
     * @covers ::listRepos
     */
    public function testListRepos_ReturnsAllRepos(): void {
        $registry = $this->newRegistry();
        
        $idA = $registry->ensureRepoEntry('https://example.com/list-a');
        $idB = $registry->ensureRepoEntry('https://example.com/list-b');

        $list = $registry->listRepos();
        
        $this->assertGreaterThanOrEqual(2, count($list));
        
        $urls = array_map(fn($repo) => $repo->url(), $list);
        $this->assertContains('https://example.com/list-a', $urls);
        $this->assertContains('https://example.com/list-b', $urls);
    }

    /**
     * @covers ::deleteRepo
     * @covers ::getRepo
     */
    public function testDeleteRepo_RemovesRepo(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/delete';
        
        $id = $registry->ensureRepoEntry($url);
        $this->assertNotNull($registry->getRepo($id));

        $registry->deleteRepo($id);
        
        $this->assertNull($registry->getRepo($id));
    }

    /**
     * @covers ::deleteRepo
     * @covers ::getRepoId
     */
    public function testDeleteRepo_RemovesFromUrlLookup(): void {
        $registry = $this->newRegistry();
        $url = 'https://example.com/delete-url';
        
        $id = $registry->ensureRepoEntry($url);
        $this->assertNotNull($registry->getRepoId($url));

        $registry->deleteRepo($id);
        
        $this->assertNull($registry->getRepoId($url));
    }

    /**
     * @covers ::getRepoId
     */
    public function testGetRepoId_WhenNotExists_ReturnsNull(): void {
        $registry = $this->newRegistry();
        
        $result = $registry->getRepoId('https://example.com/nonexistent');
        
        $this->assertNull($result);
    }

    /**
     * @covers ::getRepo
     */
    public function testGetRepo_WithIntId_WhenNotExists_ReturnsNull(): void {
        $registry = $this->newRegistry();
        
        $result = $registry->getRepo(999999);
        
        $this->assertNull($result);
    }

    /**
     * @covers ::getRepo
     */
    public function testGetRepo_WithContentRepoId_WhenNotExists_ReturnsNull(): void {
        $registry = $this->newRegistry();
        
        $result = $registry->getRepo(new ContentRepoId(999999));
        
        $this->assertNull($result);
    }

    /**
     * @covers ::getRepo
     * @covers ::getRepoId
     */
    public function testGetRepo_WithUrlString_WhenNotExists_ReturnsNull(): void {
        $registry = $this->newRegistry();
        
        $result = $registry->getRepo('https://example.com/nonexistent-url');
        
        $this->assertNull($result);
    }
}
