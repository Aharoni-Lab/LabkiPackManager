<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Util;

use LabkiPackManager\Util\ContentSourcesUtil;
use MediaWikiUnitTestCase;

/**
 * Tests for ContentSourcesUtil
 *
 * ContentSourcesUtil provides access to and normalization of content repository
 * sources from the $wgLabkiContentSources MediaWiki configuration.
 *
 * These tests verify parsing of various configuration formats, URL resolution,
 * refs handling, and edge cases.
 *
 * @coversDefaultClass \LabkiPackManager\Util\ContentSourcesUtil
 */
final class ContentSourcesUtilTest extends MediaWikiUnitTestCase {

    /**
     * Store original global state to restore after each test
     */
    private $originalContentSources;

    protected function setUp(): void {
        parent::setUp();
        global $wgLabkiContentSources;
        $this->originalContentSources = $wgLabkiContentSources ?? null;
    }

    protected function tearDown(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = $this->originalContentSources;
        parent::tearDown();
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenNotConfigured_ReturnsEmpty(): void {
        global $wgLabkiContentSources;
        unset($wgLabkiContentSources);

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenNotArray_ReturnsEmpty(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = 'not-an-array';

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenEmptyArray_ReturnsEmpty(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenStringUrl_ResolvesWithDefaultRef(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo'
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
        $this->assertSame(['main'], $result[0]['refs']);
        $this->assertSame('https://github.com/user/repo', $result[0]['original']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenStringUrlWithGitSuffix_RemovesSuffix(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo.git'
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenArrayWithUrl_ResolvesWithDefaultRef(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            ['url' => 'https://github.com/user/repo']
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
        $this->assertSame(['main'], $result[0]['refs']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenArrayWithUrlAndRefs_ResolvesWithRefs(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            ['url' => 'https://github.com/user/repo', 'refs' => ['main', 'dev']]
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
        $this->assertSame(['main', 'dev'], $result[0]['refs']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenArrayWithSingleRef_ResolvesAsArray(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            ['url' => 'https://github.com/user/repo', 'refs' => 'main']
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame(['main'], $result[0]['refs']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenMultipleSources_ResolvesAll(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo1',
            ['url' => 'https://github.com/user/repo2'],
            ['url' => 'https://github.com/user/repo3', 'refs' => ['main', 'dev']],
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(3, $result);
        $this->assertSame('https://github.com/user/repo1', $result[0]['url']);
        $this->assertSame('https://github.com/user/repo2', $result[1]['url']);
        $this->assertSame('https://github.com/user/repo3', $result[2]['url']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenWhitespaceInUrl_TrimsUrl(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            '  https://github.com/user/repo  ',
            ['url' => '  https://github.com/user/repo2  ']
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(2, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
        $this->assertSame('https://github.com/user/repo2', $result[1]['url']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenWhitespaceInRefs_TrimsRefs(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            ['url' => 'https://github.com/user/repo', 'refs' => ['  main  ', '  dev  ']]
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame(['main', 'dev'], $result[0]['refs']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenEmptyStringUrl_SkipsEntry(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            '',
            'https://github.com/user/repo',
            '   ',
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenEmptyUrlInArray_SkipsEntry(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            ['url' => ''],
            ['url' => 'https://github.com/user/repo'],
            ['url' => '   '],
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenEmptyRefs_DefaultsToMain(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            ['url' => 'https://github.com/user/repo', 'refs' => []],
            ['url' => 'https://github.com/user/repo2', 'refs' => ['', '  ']],
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(2, $result);
        $this->assertSame(['main'], $result[0]['refs']);
        $this->assertSame(['main'], $result[1]['refs']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenInvalidEntries_SkipsThem(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo1',
            123, // Invalid: number
            ['no-url-key' => 'value'], // Invalid: array without 'url'
            null, // Invalid: null
            ['url' => 'https://github.com/user/repo2'],
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(2, $result);
        $this->assertSame('https://github.com/user/repo1', $result[0]['url']);
        $this->assertSame('https://github.com/user/repo2', $result[1]['url']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenGitHubTreeUrl_NormalizesToBase(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo/tree/main/path'
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_WhenRawGitHubUrl_ConvertsToStandard(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://raw.githubusercontent.com/user/repo/main/manifest.yml'
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(1, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['url']);
    }

    /**
     * @covers ::getResolvedContentSources
     * @covers ::parseSources
     */
    public function testGetResolvedContentSources_PreservesOriginalEntry(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo',
            ['url' => 'https://github.com/user/repo2', 'refs' => ['main'], 'custom' => 'data'],
        ];

        $result = ContentSourcesUtil::getResolvedContentSources();

        $this->assertCount(2, $result);
        $this->assertSame('https://github.com/user/repo', $result[0]['original']);
        $this->assertIsArray($result[1]['original']);
        $this->assertSame('data', $result[1]['original']['custom']);
    }

    /**
     * @covers ::getResolvedUrls
     */
    public function testGetResolvedUrls_WhenConfigured_ReturnsOnlyUrls(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo1',
            ['url' => 'https://github.com/user/repo2', 'refs' => ['main', 'dev']],
        ];

        $result = ContentSourcesUtil::getResolvedUrls();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('https://github.com/user/repo1', $result[0]);
        $this->assertSame('https://github.com/user/repo2', $result[1]);
    }

    /**
     * @covers ::getResolvedUrls
     */
    public function testGetResolvedUrls_WhenNotConfigured_ReturnsEmpty(): void {
        global $wgLabkiContentSources;
        unset($wgLabkiContentSources);

        $result = ContentSourcesUtil::getResolvedUrls();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @covers ::getResolvedUrlsWithRefs
     */
    public function testGetResolvedUrlsWithRefs_WhenConfigured_ReturnsUrlsAndRefs(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo1',
            ['url' => 'https://github.com/user/repo2', 'refs' => ['main', 'dev']],
        ];

        $result = ContentSourcesUtil::getResolvedUrlsWithRefs();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('https://github.com/user/repo1', $result[0]['url']);
        $this->assertSame(['main'], $result[0]['refs']);
        $this->assertSame('https://github.com/user/repo2', $result[1]['url']);
        $this->assertSame(['main', 'dev'], $result[1]['refs']);
    }

    /**
     * @covers ::getResolvedUrlsWithRefs
     */
    public function testGetResolvedUrlsWithRefs_DoesNotIncludeOriginal(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            ['url' => 'https://github.com/user/repo', 'refs' => ['main'], 'custom' => 'data'],
        ];

        $result = ContentSourcesUtil::getResolvedUrlsWithRefs();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('url', $result[0]);
        $this->assertArrayHasKey('refs', $result[0]);
        $this->assertArrayNotHasKey('original', $result[0]);
        $this->assertArrayNotHasKey('custom', $result[0]);
    }

    /**
     * @covers ::hasContentSources
     */
    public function testHasContentSources_WhenConfigured_ReturnsTrue(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo'
        ];

        $result = ContentSourcesUtil::hasContentSources();

        $this->assertTrue($result);
    }

    /**
     * @covers ::hasContentSources
     */
    public function testHasContentSources_WhenNotConfigured_ReturnsFalse(): void {
        global $wgLabkiContentSources;
        unset($wgLabkiContentSources);

        $result = ContentSourcesUtil::hasContentSources();

        $this->assertFalse($result);
    }

    /**
     * @covers ::hasContentSources
     */
    public function testHasContentSources_WhenEmptyArray_ReturnsFalse(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [];

        $result = ContentSourcesUtil::hasContentSources();

        $this->assertFalse($result);
    }

    /**
     * @covers ::hasContentSources
     */
    public function testHasContentSources_WhenOnlyInvalidEntries_ReturnsFalse(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            123,
            null,
            ['no-url' => 'value'],
        ];

        $result = ContentSourcesUtil::hasContentSources();

        $this->assertFalse($result);
    }

    /**
     * @covers ::getOriginalContentSources
     */
    public function testGetOriginalContentSources_WhenConfigured_ReturnsRawConfig(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo.git',
            ['url' => 'https://github.com/user/repo2', 'custom' => 'data'],
        ];

        $result = ContentSourcesUtil::getOriginalContentSources();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('https://github.com/user/repo.git', $result[0]); // Not normalized
        $this->assertSame('data', $result[1]['custom']); // Custom data preserved
    }

    /**
     * @covers ::getOriginalContentSources
     */
    public function testGetOriginalContentSources_WhenNotConfigured_ReturnsNull(): void {
        global $wgLabkiContentSources;
        unset($wgLabkiContentSources);

        $result = ContentSourcesUtil::getOriginalContentSources();

        $this->assertNull($result);
    }

    /**
     * @covers ::getOriginalContentSources
     */
    public function testGetOriginalContentSources_DoesNotNormalizeUrls(): void {
        global $wgLabkiContentSources;
        $wgLabkiContentSources = [
            'https://github.com/user/repo/tree/main/path'
        ];

        $result = ContentSourcesUtil::getOriginalContentSources();

        $this->assertSame('https://github.com/user/repo/tree/main/path', $result[0]);
    }
}

