<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Manifests;

use ApiMain;
use ApiTestCase;
use MediaWiki\Request\FauxRequest;
use LabkiPackManager\API\Manifests\ApiLabkiHierarchyGet;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\ManifestStore;

/**
 * Integration tests for ApiLabkiHierarchyGet.
 *
 * @covers \LabkiPackManager\API\Manifests\ApiLabkiHierarchyGet
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
final class ApiLabkiHierarchyGetTest extends ApiTestCase {

    private LabkiRepoRegistry $repoRegistry;
    private \PHPUnit\Framework\MockObject\MockObject|ManifestStore $manifestStoreMock;

    /** @var string[] */
    protected $tablesUsed = [ 'labki_content_repo', 'labki_content_ref' ];

    protected function setUp(): void {
        parent::setUp();
        $this->repoRegistry = new LabkiRepoRegistry();
        $this->manifestStoreMock = $this->createMock(ManifestStore::class);
    }

    private function makeApi(array $params): ApiLabkiHierarchyGet {
        $main = new ApiMain(new FauxRequest($params));
        return new ApiLabkiHierarchyGet($main, 'labkiHierarchyGet', $this->repoRegistry, $this->manifestStoreMock);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Error handling
    // ─────────────────────────────────────────────────────────────────────────────

    public function testMissingRepoUrl_ReturnsError(): void {
        $this->expectException(\ApiUsageException::class);
        $this->doApiRequest(['action' => 'labkiHierarchyGet']);
    }

    public function testRepoNotFound_ReturnsError(): void {
        $this->expectException(\ApiUsageException::class);
        $api = $this->makeApi([
            'action' => 'labkiHierarchyGet',
            'repo_url' => 'https://github.com/fake/repo',
            'ref' => 'main',
        ]);
        $api->execute();
    }

    public function testHierarchyFetchError_ReturnsError(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        // getHierarchy() returning null should trigger ApiUsageException
        $this->manifestStoreMock->expects($this->once())
            ->method('getHierarchy')
            ->with(false)
            ->willReturn(null);

        $api = $this->makeApi([
            'action' => 'labkiHierarchyGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
        ]);

        $this->expectException(\ApiUsageException::class);
        $api->execute();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Success cases
    // ─────────────────────────────────────────────────────────────────────────────

    public function testValidHierarchy_ReturnsStructuredResponse(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo', ['default_ref' => 'main']);
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $hierarchyData = [
            'meta' => [
                'schema_version' => 1,
                'repo_url' => 'https://github.com/test/repo',
                'ref' => 'main',
                'hash' => 'def456'
            ],
            'hierarchy' => [
                'root' => [
                    'name' => 'PackA',
                    'children' => [
                        ['name' => 'PackB'],
                    ]
                ]
            ],
            'from_cache' => true
        ];

        $this->manifestStoreMock->expects($this->once())
            ->method('getHierarchy')
            ->with(false)
            ->willReturn($hierarchyData);

        $api = $this->makeApi([
            'action' => 'labkiHierarchyGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
        ]);

        $api->execute();
        $data = $api->getResult()->getResultData();

        $this->assertSame('https://github.com/test/repo', $data['repo_url']);
        $this->assertSame('main', $data['ref']);
        $this->assertSame('def456', $data['hash']);
        $this->assertArrayHasKey('hierarchy', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertSame(1, $data['meta']['schemaVersion']);
        $this->assertTrue($data['meta']['from_cache']);
    }

    public function testRefreshFlag_TriggersForcedFetch(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $this->manifestStoreMock->expects($this->once())
            ->method('getHierarchy')
            ->with(true)
            ->willReturn([
                'meta' => ['schema_version' => 1],
                'hierarchy' => [],
                'from_cache' => false
            ]);

        $api = $this->makeApi([
            'action' => 'labkiHierarchyGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
            'refresh' => true,
        ]);

        $api->execute();
        $data = $api->getResult()->getResultData();

        $this->assertArrayHasKey('hierarchy', $data);
        $this->assertFalse($data['meta']['from_cache']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Meta
    // ─────────────────────────────────────────────────────────────────────────────

    public function testApiProperties_ReadOnlyAndPublic(): void {
        $api = new ApiLabkiHierarchyGet(
            new ApiMain(new FauxRequest([])),
            'labkiHierarchyGet',
            $this->repoRegistry,
            $this->manifestStoreMock
        );
        $this->assertFalse($api->isWriteMode());
        $this->assertFalse($api->isInternal());
    }
}
