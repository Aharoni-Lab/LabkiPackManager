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
    // Error handling tests
    // ─────────────────────────────────────────────────────────────────────────────

    /** Missing repo_url should immediately trigger ApiUsageException */
    public function testMissingRepoUrl_ReturnsError(): void {
        $this->expectException(\ApiUsageException::class);
        $this->doApiRequest(['action' => 'labkiManifestGet']);
    }

    /** Nonexistent repo should trigger repo_not_found error */
    public function testRepoNotFound_ReturnsError(): void {
        $this->expectException(\ApiUsageException::class);
        $api = $this->makeApi([
            'action' => 'labkiManifestGet',
            'repo_url' => 'https://github.com/fake/repo',
            'ref' => 'main',
        ]);
        $api->execute();
    }

    /** ManifestStore returning fatal Status should raise ApiUsageException */
    public function testManifestFetchError_ReturnsError(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $status = Status::newFatal('labkipackmanager-error-fetch');
        $this->manifestStoreMock->expects($this->once())
            ->method('get')
            ->with(false) // default refresh = false
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
    // Successful fetch tests
    // ─────────────────────────────────────────────────────────────────────────────

    /** Valid manifest should produce structured response with filtered fields */
    public function testValidManifest_ReturnsStructuredResponse(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo', ['default_ref' => 'main']);
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $manifestData = [
            'hash' => 'abc123',
            'manifest' => [
                'schema_version' => '1.0.0',
                'name' => 'Labki Base Packs',
                'pages' => ['internal'], // should be stripped
            ],
            'hierarchy' => ['tree' => [], 'packCount' => 5, 'pageCount' => 45],
            'graph' => ['nodes' => [], 'edges' => []],
            'from_cache' => true,
        ];
        $this->manifestStoreMock->method('get')->willReturn(Status::newGood($manifestData));

        $api = $this->makeApi([
            'action' => 'labkiManifestGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
        ]);

        $api->execute();
        $data = $api->getResult()->getResultData();

        // Repo and ref correctness
        $this->assertSame('https://github.com/test/repo', $data['repo_url']);
        $this->assertSame('main', $data['ref']);
        $this->assertSame('abc123', $data['hash']);

        // Manifest field filtering
        $this->assertArrayHasKey('manifest', $data);
        $this->assertArrayNotHasKey('pages', $data['manifest']);

        // Hierarchy + graph
        $this->assertArrayHasKey('hierarchy', $data);
        $this->assertArrayHasKey('graph', $data);

        // Meta information
        $this->assertSame(1, $data['meta']['schemaVersion']);
        $this->assertArrayHasKey('timestamp', $data['meta']);
        $this->assertTrue($data['meta']['from_cache']);
    }

    /** Ensures refresh parameter triggers ManifestStore::get(true) */
    public function testRefreshFlag_TriggersForcedFetch(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $this->manifestStoreMock->expects($this->once())
            ->method('get')
            ->with(true)
            ->willReturn(Status::newGood(['manifest' => []]));

        $api = $this->makeApi([
            'action' => 'labkiManifestGet',
            'repo_url' => 'https://github.com/test/repo',
            'ref' => 'main',
            'refresh' => true,
        ]);
        $api->execute();
    }

    /** Invalid manifest (missing 'manifest' key) should raise ApiUsageException */
    public function testInvalidManifestStructure_ReturnsError(): void {
        $repoId = $this->repoRegistry->ensureRepoEntry('https://github.com/test/repo');
        $this->assertNotNull($this->repoRegistry->getRepo($repoId));

        $this->manifestStoreMock->method('get')
            ->willReturn(Status::newGood(['no_manifest_here' => true]));

        $api = $this->makeApi([
            'action' => 'labkiManifestGet',
            'repo_url' => 'https://github.com/test/repo',
        ]);

        $this->expectException(\ApiUsageException::class);
        $api->execute();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Meta tests
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
