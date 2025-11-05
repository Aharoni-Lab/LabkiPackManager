<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Manifests;

use ApiMain;
use ApiTestCase;
use MediaWiki\Request\FauxRequest;
use LabkiPackManager\API\Manifests\ApiLabkiGraphGet;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\ManifestStore;

/**
 * Integration tests for ApiLabkiGraphGet.
 *
 * @covers \LabkiPackManager\API\Manifests\ApiLabkiGraphGet
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
final class ApiLabkiGraphGetTest extends ApiTestCase {

    private LabkiRepoRegistry $repoRegistry;
    private \PHPUnit\Framework\MockObject\MockObject|ManifestStore $manifestStoreMock;

    /** @var string[] */
    protected $tablesUsed = [ 'labki_content_repo', 'labki_content_ref' ];

    protected function setUp(): void {
        parent::setUp();
        $this->repoRegistry = new LabkiRepoRegistry();
        $this->manifestStoreMock = $this->createMock(ManifestStore::class);
    }

    private function makeApi(array $params): ApiLabkiGraphGet {
        $main = new ApiMain(new FauxRequest($params));
        return new ApiLabkiGraphGet($main, 'labkiGraphGet', $this->repoRegistry, $this->manifestStoreMock);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Error handling
    // ─────────────────────────────────────────────────────────────────────────────

    public function testMissingRepoUrl_ReturnsError(): void {
        $this->expectException(\ApiUsageException::class);
        $this->doApiRequest(['action' => 'labkiGraphGet']);
    }

    public function testRepoNotFound_ReturnsError(): void {
        $this->expectException(\ApiUsageException::class);
        $api = $this->makeApi([
            'action' => 'labkiGraphGet',
            'repo_url' => 'https://github.com/fake/repo',
            'ref' => 'main',
        ]);
        $api->execute();
    }

    public function testGraphFetchError_ReturnsError(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $status = \MediaWiki\Status\Status::newFatal('labkipackmanager-error-fetch');
        $this->manifestStoreMock->expects($this->once())
            ->method('getGraph')
            ->with(false)
            ->willReturn($status);

        $api = $this->makeApi([
            'action' => 'labkiGraphGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
        ]);

        $this->expectException(\ApiUsageException::class);
        $api->execute();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Success cases
    // ─────────────────────────────────────────────────────────────────────────────

    public function testValidGraph_ReturnsStructuredResponse(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo', ['default_ref' => 'main']);
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $graphData = [
            'meta' => [
                'schema_version' => 1,
                'repo_url' => 'https://github.com/test/repo',
                'ref' => 'main',
                'hash' => 'xyz789'
            ],
            'graph' => [
                'containsEdges' => [
                    ['from' => 'packA', 'to' => 'page1']
                ],
                'dependsEdges' => [
                    ['from' => 'packB', 'to' => 'packA']
                ],
                'roots' => ['packA', 'packB'],
                'hasCycle' => false
            ],
            'from_cache' => true
        ];

        $this->manifestStoreMock->expects($this->once())
            ->method('getGraph')
            ->with(false)
            ->willReturn(\MediaWiki\Status\Status::newGood($graphData));

        $api = $this->makeApi([
            'action' => 'labkiGraphGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
        ]);

        $api->execute();
        $data = $api->getResult()->getResultData();

        $this->assertSame('https://github.com/test/repo', $data['repo_url']);
        $this->assertSame('main', $data['ref']);
        $this->assertSame('xyz789', $data['hash']);
        $this->assertArrayHasKey('graph', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertSame(1, $data['meta']['schemaVersion']);
        $this->assertTrue($data['meta']['from_cache']);
        $this->assertArrayHasKey('containsEdges', $data['graph']);
    }

    public function testRefreshFlag_TriggersForcedFetch(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $this->manifestStoreMock->expects($this->once())
            ->method('getGraph')
            ->with(true)
            ->willReturn(\MediaWiki\Status\Status::newGood([
                'meta' => [
                    'schema_version' => 1,
                    'repo_url' => 'https://github.com/test/repo',
                    'ref' => 'main',
                    'hash' => 'abc123'
                ],
                'graph' => [],
                'from_cache' => false
            ]));

        $api = $this->makeApi([
            'action' => 'labkiGraphGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
            'refresh' => true,
        ]);

        $api->execute();
        $data = $api->getResult()->getResultData();

        $this->assertArrayHasKey('graph', $data);
        $this->assertFalse($data['meta']['from_cache']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Meta
    // ─────────────────────────────────────────────────────────────────────────────

    public function testApiProperties_ReadOnlyAndPublic(): void {
        $api = new ApiLabkiGraphGet(
            new ApiMain(new FauxRequest([])),
            'labkiGraphGet',
            $this->repoRegistry,
            $this->manifestStoreMock
        );
        $this->assertFalse($api->isWriteMode());
        $this->assertFalse($api->isInternal());
    }
}
