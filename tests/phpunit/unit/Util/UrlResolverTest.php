<?php

declare(strict_types=1);

// Base test class is provided by MediaWiki's test harness
use LabkiPackManager\Util\UrlResolver;

/**
 * @covers \LabkiPackManager\Util\UrlResolver::resolveContentRepoUrl
 */
final class UrlResolverTest extends \MediaWikiUnitTestCase {

    public function testEmptyAndInvalidUrls(): void {
        $cases = [
            [ '', '' ],
            [ '   ', '' ], // trim() removes whitespace
            [ 'invalid-url', 'invalid-url' ],
            [ 'not-a-url', 'not-a-url' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testGitHubCanonicalUrls(): void {
        $cases = [
            // Basic GitHub URLs
            [ 'https://github.com/user/repo', 'https://github.com/user/repo.git' ],
            [ 'http://github.com/user/repo', 'https://github.com/user/repo.git' ], // Always uses HTTPS
            [ 'https://www.github.com/user/repo', 'https://github.com/user/repo.git' ], // Normalizes to github.com
            [ 'https://github.com/user/repo/', 'https://github.com/user/repo.git' ],
            
            // GitHub URLs with trailing slashes
            [ 'https://github.com/user/repo/', 'https://github.com/user/repo.git' ],
            [ 'https://github.com/user/repo//', 'https://github.com/user/repo.git' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testGitHubUrlsWithTreeRefs(): void {
        $cases = [
            // Tree URLs
            [ 'https://github.com/user/repo/tree/main', 'https://github.com/user/repo.git' ],
            [ 'https://github.com/user/repo/tree/develop', 'https://github.com/user/repo.git' ],
            [ 'https://github.com/user/repo/tree/v1.0.0', 'https://github.com/user/repo.git' ],
            [ 'https://github.com/user/repo/tree/main/path/to/file', 'https://github.com/user/repo.git' ],
            [ 'https://github.com/user/repo/tree/main/subdir/', 'https://github.com/user/repo.git' ],
            
            // Tree URLs with www
            [ 'https://www.github.com/user/repo/tree/main', 'https://github.com/user/repo.git' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testGitHubUrlsWithBlobRefs(): void {
        $cases = [
            // Blob URLs
            [ 'https://github.com/user/repo/blob/main/file.txt', 'https://github.com/user/repo.git' ],
            [ 'https://github.com/user/repo/blob/develop/src/main.php', 'https://github.com/user/repo.git' ],
            [ 'https://github.com/user/repo/blob/v1.0.0/manifest.yml', 'https://github.com/user/repo.git' ],
            [ 'https://github.com/user/repo/blob/main/manifest.yaml', 'https://github.com/user/repo.git' ],
            
            // Blob URLs with www
            [ 'https://www.github.com/user/repo/blob/main/file.txt', 'https://github.com/user/repo.git' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testGitHubUrlsWithSpecialCharacters(): void {
        $cases = [
            // URLs with special characters in owner/repo names
            [ 'https://github.com/user-name/repo_name', 'https://github.com/user-name/repo_name.git' ],
            [ 'https://github.com/user.name/repo.name', 'https://github.com/user.name/repo.name.git' ],
            [ 'https://github.com/user123/repo123', 'https://github.com/user123/repo123.git' ],
            [ 'https://github.com/user_name/repo-name', 'https://github.com/user_name/repo-name.git' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testRawGitHubUrls(): void {
        $cases = [
            // Raw GitHub URLs
            [ 'https://raw.githubusercontent.com/user/repo/main/file.txt', 'https://github.com/user/repo.git' ],
            [ 'https://raw.githubusercontent.com/user/repo/develop/manifest.yml', 'https://github.com/user/repo.git' ],
            [ 'https://raw.githubusercontent.com/user/repo/v1.0.0/path/to/file', 'https://github.com/user/repo.git' ],
            [ 'https://raw.githubusercontent.com/user/repo/main/', 'https://github.com/user/repo.git' ],
            
            // Raw GitHub URLs with www
            [ 'https://raw.fastgit.org/user/repo/main/file.txt', 'https://github.com/user/repo.git' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testAlreadyGitUrls(): void {
        $cases = [
            // Already valid git URLs
            [ 'https://github.com/user/repo.git', 'https://github.com/user/repo.git' ],
            [ 'http://github.com/user/repo.git', 'http://github.com/user/repo.git' ],
            [ 'https://gitlab.com/user/repo.git', 'https://gitlab.com/user/repo.git' ],
            [ 'https://bitbucket.org/user/repo.git', 'https://bitbucket.org/user/repo.git' ],
            [ 'git@github.com:user/repo.git', 'git@github.com:user/repo.git' ],
            [ 'ssh://git@github.com/user/repo.git', 'ssh://git@github.com/user/repo.git' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testOtherGitUrls(): void {
        $cases = [
            // Other git hosting services
            [ 'https://gitlab.com/user/repo', 'https://gitlab.com/user/repo' ],
            [ 'https://bitbucket.org/user/repo', 'https://bitbucket.org/user/repo' ],
            [ 'https://sourceforge.net/projects/projectname', 'https://sourceforge.net/projects/projectname' ],
            
            // Generic HTTP(S) URLs
            [ 'https://example.com/repo', 'https://example.com/repo' ],
            [ 'http://example.com/repo', 'http://example.com/repo' ],
            [ 'https://custom-git-server.com/user/repo', 'https://custom-git-server.com/user/repo' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testUrlsWithQueryAndFragment(): void {
        $cases = [
            // URLs with query parameters and fragments
            [ 'https://github.com/user/repo?ref=main', 'https://github.com/user/repo.git?ref=main' ],
            [ 'https://github.com/user/repo#readme', 'https://github.com/user/repo.git#readme' ],
            [ 'https://github.com/user/repo?tab=readme#section', 'https://github.com/user/repo.git?tab=readme#section' ],
            
            // Tree URLs with query/fragment
            [ 'https://github.com/user/repo/tree/main?ref=main', 'https://github.com/user/repo.git?ref=main' ],
            [ 'https://github.com/user/repo/blob/main/file.txt#L10', 'https://github.com/user/repo.git#L10' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testEdgeCases(): void {
        $cases = [
            // Edge cases
            [ 'https://github.com/user', 'https://github.com/user' ], // Not enough segments
            [ 'https://github.com/', 'https://github.com/' ], // Empty path
            [ 'https://github.com/user/', 'https://github.com/user/' ], // Only owner
            
            // Invalid GitHub URLs
            [ 'https://github.com/user/repo/tree', 'https://github.com/user/repo.git' ], // Tree without ref
            [ 'https://github.com/user/repo/blob', 'https://github.com/user/repo.git' ], // Blob without ref
            [ 'https://github.com/user/repo/tree/', 'https://github.com/user/repo.git' ], // Tree with empty ref
            [ 'https://github.com/user/repo/blob/', 'https://github.com/user/repo.git' ], // Blob with empty ref
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testCaseInsensitiveHosts(): void {
        $cases = [
            // Case insensitive host matching
            [ 'https://GITHUB.COM/user/repo', 'https://github.com/user/repo.git' ],
            [ 'https://GitHub.Com/user/repo', 'https://github.com/user/repo.git' ],
            [ 'https://WWW.GITHUB.COM/user/repo', 'https://github.com/user/repo.git' ],
            [ 'https://RAW.GITHUBUSERCONTENT.COM/user/repo/main/file.txt', 'https://github.com/user/repo.git' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }

    public function testWhitespaceHandling(): void {
        $cases = [
            // Whitespace handling
            [ '  https://github.com/user/repo  ', 'https://github.com/user/repo.git' ],
            [ "\thttps://github.com/user/repo\t", 'https://github.com/user/repo.git' ],
            [ "\nhttps://github.com/user/repo\n", 'https://github.com/user/repo.git' ],
        ];
        
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, UrlResolver::resolveContentRepoUrl($input));
        }
    }
}
