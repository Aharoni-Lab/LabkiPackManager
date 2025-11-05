<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Manifests;

use ApiMain;
use ApiTestCase;
use MediaWiki\Request\FauxRequest;
use LabkiPackManager\API\Manifests\ApiLabkiManifestGet;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\ManifestStore;
use Status;

/**
 * Integration tests for ApiLabkiManifestGet.
 *
 * @covers \LabkiPackManager\API\Manifests\ApiLabkiManifestGet
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
final class ApiLabkiManifestGetTest extends ApiTestCase {

    private LabkiRepoRegistry $repoRegistry;
    private \PHPUnit\Framework\MockObject\MockObject|ManifestStore $manifestStoreMock;

    /** @var string[] */
    protected $tablesUsed = [ 'labki_content_repo', 'labki_content_ref' ];

    protected function setUp(): void {
        parent::setUp();
        $this->repoRegistry = new LabkiRepoRegistry();
        $this->manifestStoreMock = $this->createMock(ManifestStore::class);
    }

    private function makeApi(array $params): ApiLabkiManifestGet {
        $main = new ApiMain(new FauxRequest($params));
        return new ApiLabkiManifestGet($main, 'labkiManifestGet', $this->repoRegistry, $this->manifestStoreMock);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Error handling
    // ─────────────────────────────────────────────────────────────────────────────

    public function testMissingRepoUrl_ReturnsError(): void {
        $this->expectException(\ApiUsageException::class);
        $this->doApiRequest(['action' => 'labkiManifestGet']);
    }

    public function testRepoNotFound_ReturnsError(): void {
        $this->expectException(\ApiUsageException::class);
        $api = $this->makeApi([
            'action' => 'labkiManifestGet',
            'repo_url' => 'https://github.com/fake/repo',
            'ref' => 'main',
        ]);
        $api->execute();
    }

    public function testManifestFetchError_ReturnsError(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $status = Status::newFatal('labkipackmanager-error-fetch');
        $this->manifestStoreMock->expects($this->once())
            ->method('getManifest')
            ->with(false)
            ->willReturn($status);

        $api = $this->makeApi([
            'action' => 'labkiManifestGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
        ]);

        $this->expectException(\ApiUsageException::class);
        $api->execute();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Success cases
    // ─────────────────────────────────────────────────────────────────────────────

    public function testValidManifest_ReturnsStructuredResponse(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo', ['default_ref' => 'main']);
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $manifestData = [
            'meta' => [
                'schema_version' => 1,
                'repo_url' => 'https://github.com/test/repo',
                'ref' => 'main',
                'hash' => 'abc123'
            ],
            'manifest' => [
                'schema_version' => '1.0.0',
                'name' => 'Labki Base Packs',
                'pages' => ['internal'] // internal key to be kept (no longer stripped)
            ],
            'from_cache' => true
        ];

        $this->manifestStoreMock->method('getManifest')
            ->willReturn(Status::newGood($manifestData));

        $api = $this->makeApi([
            'action' => 'labkiManifestGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
        ]);

        $api->execute();
        $data = $api->getResult()->getResultData();

        $this->assertSame('https://github.com/test/repo', $data['repo_url']);
        $this->assertSame('main', $data['ref']);
        $this->assertSame('abc123', $data['hash']);
        $this->assertArrayHasKey('manifest', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertSame(1, $data['meta']['schemaVersion']);
        $this->assertTrue($data['meta']['from_cache']);
    }

    public function testRefreshFlag_TriggersForcedFetch(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $this->manifestStoreMock->expects($this->once())
            ->method('getManifest')
            ->with(true)
            ->willReturn(Status::newGood([
                'meta' => [
                    'schema_version' => 1,
                    'repo_url' => 'https://github.com/test/repo',
                    'ref' => 'main',
                    'hash' => 'xyz789'
                ],
                'manifest' => [],
                'from_cache' => false
            ]));

        $api = $this->makeApi([
            'action' => 'labkiManifestGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
            'refresh' => true,
        ]);

        $api->execute();
        $data = $api->getResult()->getResultData();
        $this->assertArrayHasKey('manifest', $data);
        $this->assertFalse($data['meta']['from_cache']);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Meta
    // ─────────────────────────────────────────────────────────────────────────────

    public function testApiProperties_ReadOnlyAndPublic(): void {
        $api = new ApiLabkiManifestGet(
            new ApiMain(new FauxRequest([])),
            'labkiManifestGet',
            $this->repoRegistry,
            $this->manifestStoreMock
        );
        $this->assertFalse($api->isWriteMode());
        $this->assertFalse($api->isInternal());
    }
}
