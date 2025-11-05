<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Services\ManifestFetcher;
use LabkiPackManager\Services\LabkiRefRegistry;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for ManifestFetcher
 *
 * ManifestFetcher reads manifest.yml files from local worktrees that have been
 * prepared by GitContentManager. These tests verify file reading, error handling,
 * and integration with LabkiRefRegistry.
 *
     * @coversDefaultClass \LabkiPackManager\Services\ManifestFetcher
     */
final class ManifestFetcherTest extends TestCase {

    private string $tempDir;

    protected function setUp(): void {
        parent::setUp();
        // Create a temporary directory for test worktrees
        $this->tempDir = sys_get_temp_dir() . '/labki_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * Recursively delete a directory and its contents.
     */
    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Create a mock worktree directory with a manifest.yml file.
     *
     * @param string $repoUrl Repository URL (used as identifier)
     * @param string $ref Git ref (branch/tag)
     * @param string $manifestContent Contents of manifest.yml
     * @return string Path to the worktree directory
     */
    private function createMockWorktree(string $repoUrl, string $ref, string $manifestContent): string {
        $worktreePath = $this->tempDir . '/' . md5($repoUrl . $ref);
        mkdir($worktreePath, 0777, true);
        file_put_contents($worktreePath . '/manifest.yml', $manifestContent);
        return $worktreePath;
    }

    /**
     * Create a mock LabkiRefRegistry that returns predefined worktree paths.
     * Uses PHPUnit's mock builder to stub the getWorktreePath() method.
     */
    private function createRefRegistryMock(array $worktreeMap): MockObject {
        $mock = $this->createMock(LabkiRefRegistry::class);
        
        $mock->method('getWorktreePath')
            ->willReturnCallback(function (string $repoUrl, string $ref) use ($worktreeMap) {
                $key = $repoUrl . '::' . $ref;
                return $worktreeMap[$key] ?? '/nonexistent/path';
            });
        
        return $mock;
    }

    /**
     * @covers ::__construct
     */
    public function testConstructor_WithoutRegistry_CreatesDefault(): void {
        $fetcher = new ManifestFetcher();
        $this->assertInstanceOf(ManifestFetcher::class, $fetcher);
    }

    /**
     * @covers ::__construct
     */
    public function testConstructor_WithRegistry_UsesProvided(): void {
        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([]);
        $fetcher = new ManifestFetcher($registry);
        $this->assertInstanceOf(ManifestFetcher::class, $fetcher);
    }

    /**
     * @covers ::fetch
     */
    public function testFetch_WhenValidManifest_ReturnsSuccess(): void {
        $repoUrl = 'https://github.com/example/repo';
        $ref = 'main';
        $manifestYaml = "schema_version: '1.0.0'\nname: Test Pack\npacks: []\n";

        $worktreePath = $this->createMockWorktree($repoUrl, $ref, $manifestYaml);
        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([
            $repoUrl . '::' . $ref => $worktreePath
        ]);

        $fetcher = new ManifestFetcher($registry);
        $status = $fetcher->fetch($repoUrl, $ref);

        $this->assertTrue($status->isOK(), 'Fetch should succeed for valid manifest');
        $this->assertSame($manifestYaml, $status->getValue(), 'Should return raw YAML content');
        }

    /**
     * @covers ::fetch
     */
    public function testFetch_WhenManifestMissing_ReturnsFatal(): void {
        $repoUrl = 'https://github.com/example/repo';
        $ref = 'main';

        // Create worktree directory but no manifest.yml
        $worktreePath = $this->tempDir . '/no_manifest';
        mkdir($worktreePath, 0777, true);

        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([
            $repoUrl . '::' . $ref => $worktreePath
        ]);

        $fetcher = new ManifestFetcher($registry);
        $status = $fetcher->fetch($repoUrl, $ref);

        $this->assertFalse($status->isOK(), 'Fetch should fail when manifest.yml is missing');
        $this->assertTrue($status->hasMessage('labkipackmanager-error-manifest-missing'));
        }

    /**
     * @covers ::fetch
     */
    public function testFetch_WhenManifestEmpty_ReturnsFatal(): void {
        $repoUrl = 'https://github.com/example/repo';
        $ref = 'main';

        $worktreePath = $this->createMockWorktree($repoUrl, $ref, '');

        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([
            $repoUrl . '::' . $ref => $worktreePath
        ]);

        $fetcher = new ManifestFetcher($registry);
        $status = $fetcher->fetch($repoUrl, $ref);

        $this->assertFalse($status->isOK(), 'Fetch should fail when manifest.yml is empty');
        $this->assertTrue($status->hasMessage('labkipackmanager-error-manifest-empty'));
        }

    /**
     * @covers ::fetch
     */
    public function testFetch_WhenManifestUnreadable_ReturnsFatal(): void {
        $repoUrl = 'https://github.com/example/repo';
        $ref = 'main';

        $worktreePath = $this->createMockWorktree($repoUrl, $ref, 'valid content');
        $manifestPath = $worktreePath . '/manifest.yml';
        
        // Remove read permissions
        chmod($manifestPath, 0000);

        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([
            $repoUrl . '::' . $ref => $worktreePath
        ]);

        $fetcher = new ManifestFetcher($registry);
        $status = $fetcher->fetch($repoUrl, $ref);

        // Restore permissions for cleanup
        chmod($manifestPath, 0644);

        $this->assertFalse($status->isOK(), 'Fetch should fail when manifest.yml is unreadable');
        $this->assertTrue($status->hasMessage('labkipackmanager-error-manifest-unreadable'));
    }

    /**
     * @covers ::fetch
     */
    public function testFetch_WhenWorktreeDoesNotExist_ReturnsFatal(): void {
        $repoUrl = 'https://github.com/example/repo';
        $ref = 'nonexistent';

        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([
            $repoUrl . '::' . $ref => '/path/that/does/not/exist'
        ]);

        $fetcher = new ManifestFetcher($registry);
        $status = $fetcher->fetch($repoUrl, $ref);

        $this->assertFalse($status->isOK(), 'Fetch should fail when worktree does not exist');
        $this->assertTrue($status->hasMessage('labkipackmanager-error-manifest-missing'));
        }

    /**
     * @covers ::fetch
     */
    public function testFetch_WhenMultipleRefs_ReturnsCorrectContent(): void {
        $repoUrl = 'https://github.com/example/repo';
        $refMain = 'main';
        $refDev = 'dev';

        $mainYaml = "schema_version: '1.0.0'\nname: Main Branch\n";
        $devYaml = "schema_version: '1.0.0'\nname: Dev Branch\n";

        $mainWorktree = $this->createMockWorktree($repoUrl, $refMain, $mainYaml);
        $devWorktree = $this->createMockWorktree($repoUrl, $refDev, $devYaml);

        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([
            $repoUrl . '::' . $refMain => $mainWorktree,
            $repoUrl . '::' . $refDev => $devWorktree
        ]);

        $fetcher = new ManifestFetcher($registry);

        // Fetch main
        $statusMain = $fetcher->fetch($repoUrl, $refMain);
        $this->assertTrue($statusMain->isOK());
        $this->assertSame($mainYaml, $statusMain->getValue());

        // Fetch dev
        $statusDev = $fetcher->fetch($repoUrl, $refDev);
        $this->assertTrue($statusDev->isOK());
        $this->assertSame($devYaml, $statusDev->getValue());
    }

    /**
     * @covers ::fetch
     */
    public function testFetch_WhenComplexYaml_PreservesFormatting(): void {
        $repoUrl = 'https://github.com/example/repo';
        $ref = 'main';

        $complexYaml = <<<YAML
schema_version: '1.0.0'
name: Complex Pack
description: |
  Multi-line
  description
packs:
  - name: pack-one
    version: 1.0.0
    pages:
      - name: Page_One
      - name: Page_Two
YAML;

        $worktreePath = $this->createMockWorktree($repoUrl, $ref, $complexYaml);
        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([
            $repoUrl . '::' . $ref => $worktreePath
        ]);

        $fetcher = new ManifestFetcher($registry);
        $status = $fetcher->fetch($repoUrl, $ref);

        $this->assertTrue($status->isOK());
        $this->assertSame($complexYaml, $status->getValue(), 'Should preserve exact YAML formatting');
    }

    /**
     * @covers ::fetch
     */
    public function testFetch_WhenWhitespaceOnlyManifest_ReturnsFatal(): void {
        $repoUrl = 'https://github.com/example/repo';
        $ref = 'main';

        $worktreePath = $this->createMockWorktree($repoUrl, $ref, "   \n\t\n  ");

        /** @var LabkiRefRegistry&MockObject $registry */
        $registry = $this->createRefRegistryMock([
            $repoUrl . '::' . $ref => $worktreePath
        ]);

        $fetcher = new ManifestFetcher($registry);
        $status = $fetcher->fetch($repoUrl, $ref);

        // Whitespace-only content is now treated as empty (consistent behavior)
        $this->assertFalse($status->isOK(), 'Fetch should fail when manifest.yml contains only whitespace');
        $this->assertTrue($status->hasMessage('labkipackmanager-error-manifest-empty'));
    }
}


