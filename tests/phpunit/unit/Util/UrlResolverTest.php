<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Util;

use LabkiPackManager\Util\UrlResolver;
use MediaWikiUnitTestCase;

/**
 * Tests for UrlResolver
 *
 * UrlResolver normalizes repository URLs into canonical base forms.
 * These tests verify GitHub URL normalization, raw URL conversion,
 * and fallback behavior for non-GitHub hosts.
 *
 * @coversDefaultClass \LabkiPackManager\Util\UrlResolver
 */
final class UrlResolverTest extends MediaWikiUnitTestCase {

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenEmpty_ReturnsEmpty(): void {
        $this->assertSame('', UrlResolver::resolveContentRepoUrl(''));
        $this->assertSame('', UrlResolver::resolveContentRepoUrl('   '));
        $this->assertSame('', UrlResolver::resolveContentRepoUrl("\t"));
        $this->assertSame('', UrlResolver::resolveContentRepoUrl("\n"));
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenBasicGitHubUrl_ReturnsNormalized(): void {
        $cases = [
            ['https://github.com/user/repo', 'https://github.com/user/repo'],
            ['http://github.com/user/repo', 'https://github.com/user/repo'],
            ['https://www.github.com/user/repo', 'https://github.com/user/repo'],
            ['https://github.com/user/repo/', 'https://github.com/user/repo'],
            ['https://github.com/user/repo//', 'https://github.com/user/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenGitHubUrlWithGitSuffix_RemovesSuffix(): void {
        $cases = [
            ['https://github.com/user/repo.git', 'https://github.com/user/repo'],
            ['https://github.com/user/repo.GIT', 'https://github.com/user/repo'],
            ['https://github.com/user/repo.Git', 'https://github.com/user/repo'],
            ['https://www.github.com/user/repo.git', 'https://github.com/user/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenGitHubTreeUrl_StripsTreePath(): void {
        $cases = [
            ['https://github.com/user/repo/tree/main', 'https://github.com/user/repo'],
            ['https://github.com/user/repo/tree/develop', 'https://github.com/user/repo'],
            ['https://github.com/user/repo/tree/v1.0.0', 'https://github.com/user/repo'],
            ['https://github.com/user/repo/tree/main/path/to/file', 'https://github.com/user/repo'],
            ['https://www.github.com/user/repo/tree/main', 'https://github.com/user/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenGitHubBlobUrl_StripsBlobPath(): void {
        $cases = [
            ['https://github.com/user/repo/blob/main/file.txt', 'https://github.com/user/repo'],
            ['https://github.com/user/repo/blob/develop/src/main.php', 'https://github.com/user/repo'],
            ['https://github.com/user/repo/blob/v1.0.0/manifest.yml', 'https://github.com/user/repo'],
            ['https://www.github.com/user/repo/blob/main/file.txt', 'https://github.com/user/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenGitHubUrlWithSpecialChars_Preserves(): void {
        $cases = [
            ['https://github.com/user-name/repo_name', 'https://github.com/user-name/repo_name'],
            ['https://github.com/user.name/repo.name', 'https://github.com/user.name/repo.name'],
            ['https://github.com/user123/repo123', 'https://github.com/user123/repo123'],
            ['https://github.com/user_name/repo-name', 'https://github.com/user_name/repo-name'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenRawGitHubUrl_ConvertsToStandard(): void {
        $cases = [
            ['https://raw.githubusercontent.com/user/repo/main/file.txt', 'https://github.com/user/repo'],
            ['https://raw.githubusercontent.com/user/repo/develop/manifest.yml', 'https://github.com/user/repo'],
            ['https://raw.githubusercontent.com/user/repo/v1.0.0/path/to/file', 'https://github.com/user/repo'],
            ['https://raw.fastgit.org/user/repo/main/file.txt', 'https://github.com/user/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenNonGitHubUrl_PreservesAsIs(): void {
        $cases = [
            ['https://gitlab.com/user/repo', 'https://gitlab.com/user/repo'],
            ['https://bitbucket.org/user/repo', 'https://bitbucket.org/user/repo'],
            ['https://example.com/repo', 'https://example.com/repo'],
            ['http://example.com/repo', 'http://example.com/repo'],
            ['https://custom-git-server.com/user/repo', 'https://custom-git-server.com/user/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenNonGitHubUrlWithGitSuffix_RemovesSuffix(): void {
        $cases = [
            ['https://gitlab.com/user/repo.git', 'https://gitlab.com/user/repo'],
            ['https://bitbucket.org/user/repo.git', 'https://bitbucket.org/user/repo'],
            ['http://example.com/repo.git', 'http://example.com/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenGitHubUrlWithInsufficientSegments_ReturnsCleanedUrl(): void {
        $cases = [
            ['https://github.com/user', 'https://github.com/user'],
            ['https://github.com/', 'https://github.com'],
            ['https://github.com/user/', 'https://github.com/user'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenCaseInsensitiveHost_NormalizesCorrectly(): void {
        $cases = [
            ['https://GITHUB.COM/user/repo', 'https://github.com/user/repo'],
            ['https://GitHub.Com/user/repo', 'https://github.com/user/repo'],
            ['https://WWW.GITHUB.COM/user/repo', 'https://github.com/user/repo'],
            ['https://RAW.GITHUBUSERCONTENT.COM/user/repo/main/file.txt', 'https://github.com/user/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenWhitespaceAround_TrimsCorrectly(): void {
        $cases = [
            ['  https://github.com/user/repo  ', 'https://github.com/user/repo'],
            ["\thttps://github.com/user/repo\t", 'https://github.com/user/repo'],
            ["\nhttps://github.com/user/repo\n", 'https://github.com/user/repo'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenMalformedUrl_HandlesGracefully(): void {
        $cases = [
            // Malformed URLs get treated as path-only, resulting in https:///path format
            ['not-a-url', 'https:///not-a-url'],
            ['invalid-url', 'https:///invalid-url'],
            ['just-text', 'https:///just-text'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::resolveContentRepoUrl
     */
    public function testResolveContentRepoUrl_WhenComplexGitHubPaths_ExtractsOwnerAndRepo(): void {
        $cases = [
            ['https://github.com/Aharoni-Lab/labki-packs/tree/main/docs', 'https://github.com/Aharoni-Lab/labki-packs'],
            ['https://github.com/Aharoni-Lab/labki-packs/blob/v1.0.0/manifest.yml', 'https://github.com/Aharoni-Lab/labki-packs'],
            ['https://raw.githubusercontent.com/Aharoni-Lab/labki-packs/main/manifest.yml', 'https://github.com/Aharoni-Lab/labki-packs'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input), "Failed for: {$input}");
        }
    }
}
