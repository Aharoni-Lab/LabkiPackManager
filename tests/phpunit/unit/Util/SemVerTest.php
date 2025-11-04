<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Util;

use LabkiPackManager\Util\SemVer;
use MediaWikiUnitTestCase;

/**
 * Tests for SemVer
 *
 * SemVer provides semantic version parsing and comparison utilities.
 * These tests verify parsing of various version formats, comparison logic,
 * and helper methods for version relationships.
 *
 * @coversDefaultClass \LabkiPackManager\Util\SemVer
 */
final class SemVerTest extends MediaWikiUnitTestCase {

    /**
     * @covers ::parse
     */
    public function testParse_WhenNullOrEmpty_ReturnsZeros(): void {
        $this->assertSame([0, 0, 0], SemVer::parse(null));
        $this->assertSame([0, 0, 0], SemVer::parse(''));
        $this->assertSame([0, 0, 0], SemVer::parse('   '));
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenPartialVersion_FillsWithZeros(): void {
        $cases = [
            ['1', [1, 0, 0]],
            ['1.2', [1, 2, 0]],
            ['5', [5, 0, 0]],
            ['10.20', [10, 20, 0]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenStandardVersion_ReturnsComponents(): void {
        $cases = [
            ['1.2.3', [1, 2, 3]],
            ['0.0.1', [0, 0, 1]],
            ['10.20.30', [10, 20, 30]],
            ['100.200.300', [100, 200, 300]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenPrefixedWithV_StripsPrefix(): void {
        $cases = [
            ['v1.2.3', [1, 2, 3]],
            ['V1.2.3', [1, 2, 3]],
            ['v0.0.1', [0, 0, 1]],
            ['v10.20.30', [10, 20, 30]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenPreReleaseVersion_StripsPreRelease(): void {
        $cases = [
            ['1.2.3-alpha', [1, 2, 3]],
            ['1.2.3-beta', [1, 2, 3]],
            ['1.2.3-rc.1', [1, 2, 3]],
            ['1.2.3-alpha.1', [1, 2, 3]],
            ['v1.2.3-rc.1', [1, 2, 3]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenBuildMetadata_StripsBuildMetadata(): void {
        $cases = [
            ['1.2.3+build.5', [1, 2, 3]],
            ['1.2.3+20230101', [1, 2, 3]],
            ['1.2.3+build', [1, 2, 3]],
            ['v1.2.3+build.5', [1, 2, 3]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenCombinedPreReleaseAndBuild_StripsBoth(): void {
        $cases = [
            ['1.2.3-alpha+build.5', [1, 2, 3]],
            ['v1.2.3-rc.1+build.5', [1, 2, 3]],
            ['1.2.3-beta.1+20230101', [1, 2, 3]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenLeadingZeros_ParsesCorrectly(): void {
        $cases = [
            ['01.02.03', [1, 2, 3]],
            ['001.002.003', [1, 2, 3]],
            ['0.0.1', [0, 0, 1]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenNonNumericSuffix_StripsNonDigits(): void {
        $cases = [
            ['1.2.3beta', [1, 2, 3]],
            ['1.2.3alpha', [1, 2, 3]],
            ['1.2.x', [1, 2, 0]],
            ['1.x.x', [1, 0, 0]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::parse
     */
    public function testParse_WhenWhitespace_TrimsCorrectly(): void {
        $cases = [
            ['  1.2.3  ', [1, 2, 3]],
            ["\t1.2.3\t", [1, 2, 3]],
            ["\nv2.0.1\n", [2, 0, 1]],
            ['  v1.2.3-alpha  ', [1, 2, 3]],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::parse($input), "Failed for: {$input}");
        }
    }

    /**
     * @covers ::compare
     */
    public function testCompare_WhenEqual_ReturnsZero(): void {
        $cases = [
            ['1.2.3', '1.2.3'],
            ['0.0.0', '0.0.0'],
            ['v1.2.3', '1.2.3'],
            ['1.2.3-alpha', '1.2.3'],
            ['1.2.3+build', '1.2.3'],
            ['1.2.3-alpha', '1.2.3+build'],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertSame(0, SemVer::compare($a, $b), "Failed for: {$a} vs {$b}");
        }
    }

    /**
     * @covers ::compare
     */
    public function testCompare_WhenFirstLess_ReturnsNegative(): void {
        $cases = [
            ['1.2.3', '1.2.4'],
            ['1.2.3', '1.3.0'],
            ['1.2.3', '2.0.0'],
            ['0.9.9', '1.0.0'],
            ['1.2.3', '1.2.4-alpha'],
            [null, '1.0.0'],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertLessThan(0, SemVer::compare($a, $b), "Failed for: {$a} vs {$b}");
        }
    }

    /**
     * @covers ::compare
     */
    public function testCompare_WhenFirstGreater_ReturnsPositive(): void {
        $cases = [
            ['1.2.4', '1.2.3'],
            ['1.3.0', '1.2.9'],
            ['2.0.0', '1.9.9'],
            ['1.0.0', '0.9.9'],
            ['2.0.0-rc.1', '1.9.9'],
            ['1.0.0', null],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertGreaterThan(0, SemVer::compare($a, $b), "Failed for: {$a} vs {$b}");
        }
    }

    /**
     * @covers ::compare
     */
    public function testCompare_WhenBothNull_ReturnsZero(): void {
        $this->assertSame(0, SemVer::compare(null, null));
    }

    /**
     * @covers ::compare
     */
    public function testCompare_WhenMajorDiffers_ReturnsMajorComparison(): void {
        $this->assertLessThan(0, SemVer::compare('1.9.9', '2.0.0'));
        $this->assertGreaterThan(0, SemVer::compare('3.0.0', '2.9.9'));
    }

    /**
     * @covers ::compare
     */
    public function testCompare_WhenMinorDiffers_ReturnsMinorComparison(): void {
        $this->assertLessThan(0, SemVer::compare('1.2.9', '1.3.0'));
        $this->assertGreaterThan(0, SemVer::compare('1.5.0', '1.4.9'));
    }

    /**
     * @covers ::compare
     */
    public function testCompare_WhenPatchDiffers_ReturnsPatchComparison(): void {
        $this->assertLessThan(0, SemVer::compare('1.2.3', '1.2.4'));
        $this->assertGreaterThan(0, SemVer::compare('1.2.5', '1.2.4'));
    }

    /**
     * @covers ::sameMajor
     */
    public function testSameMajor_WhenSameMajor_ReturnsTrue(): void {
        $cases = [
            ['1.0.0', '1.9.9'],
            ['1.2.3', '1.5.0'],
            ['v1.2.3', '1.5.0'],
            ['1.0.0-alpha', '1.0.0+build'],
            ['2.0.0', '2.9.9'],
            ['0.1.0', '0.9.9'],
            [null, '0.1.0'], // null → 0.0.0
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertTrue(SemVer::sameMajor($a, $b), "Failed for: {$a} vs {$b}");
        }
    }

    /**
     * @covers ::sameMajor
     */
    public function testSameMajor_WhenDifferentMajor_ReturnsFalse(): void {
        $cases = [
            ['1.0.0', '2.0.0'],
            ['0.9.9', '1.0.0'],
            ['2.9.9', '3.0.0'],
            [null, '1.0.0'], // null → 0.0.0
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertFalse(SemVer::sameMajor($a, $b), "Failed for: {$a} vs {$b}");
        }
    }

    /**
     * @covers ::greaterThan
     */
    public function testGreaterThan_WhenGreater_ReturnsTrue(): void {
        $cases = [
            ['1.2.4', '1.2.3'],
            ['2.0.0', '1.9.9'],
            ['1.3.0', '1.2.9'],
            ['1.0.0', null],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertTrue(SemVer::greaterThan($a, $b), "Failed for: {$a} > {$b}");
        }
    }

    /**
     * @covers ::greaterThan
     */
    public function testGreaterThan_WhenNotGreater_ReturnsFalse(): void {
        $cases = [
            ['1.2.3', '1.2.3'],
            ['1.2.3', '1.2.4'],
            ['1.9.9', '2.0.0'],
            [null, '1.0.0'],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertFalse(SemVer::greaterThan($a, $b), "Failed for: {$a} > {$b}");
        }
    }

    /**
     * @covers ::lessThan
     */
    public function testLessThan_WhenLess_ReturnsTrue(): void {
        $cases = [
            ['1.2.3', '1.2.4'],
            ['1.9.9', '2.0.0'],
            ['1.2.9', '1.3.0'],
            [null, '1.0.0'],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertTrue(SemVer::lessThan($a, $b), "Failed for: {$a} < {$b}");
        }
    }

    /**
     * @covers ::lessThan
     */
    public function testLessThan_WhenNotLess_ReturnsFalse(): void {
        $cases = [
            ['1.2.3', '1.2.3'],
            ['1.2.4', '1.2.3'],
            ['2.0.0', '1.9.9'],
            ['1.0.0', null],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertFalse(SemVer::lessThan($a, $b), "Failed for: {$a} < {$b}");
        }
    }

    /**
     * @covers ::equals
     */
    public function testEquals_WhenEqual_ReturnsTrue(): void {
        $cases = [
            ['1.2.3', '1.2.3'],
            ['v1.2.3', '1.2.3'],
            ['1.2.3-alpha', '1.2.3'],
            ['1.2.3+build', '1.2.3'],
            ['1.2.3-alpha', '1.2.3+build'],
            [null, null],
            [null, '0.0.0'],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertTrue(SemVer::equals($a, $b), "Failed for: {$a} == {$b}");
        }
    }

    /**
     * @covers ::equals
     */
    public function testEquals_WhenNotEqual_ReturnsFalse(): void {
        $cases = [
            ['1.2.3', '1.2.4'],
            ['1.2.3', '2.0.0'],
            ['1.0.0', null],
            ['0.0.1', null],
        ];

        foreach ($cases as [$a, $b]) {
            $this->assertFalse(SemVer::equals($a, $b), "Failed for: {$a} == {$b}");
        }
    }

    /**
     * @covers ::format
     */
    public function testFormat_WhenValidArray_ReturnsFormattedString(): void {
        $cases = [
            [[1, 2, 3], '1.2.3'],
            [[0, 0, 0], '0.0.0'],
            [[10, 20, 30], '10.20.30'],
            [[1, 0, 0], '1.0.0'],
        ];

        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, SemVer::format($input), "Failed for: " . json_encode($input));
        }
    }

    /**
     * @covers ::format
     * @covers ::parse
     */
    public function testFormat_WhenRoundTrip_PreservesVersion(): void {
        $versions = ['1.2.3', '0.0.1', '10.20.30', '5.0.0'];

        foreach ($versions as $version) {
            $parsed = SemVer::parse($version);
            $formatted = SemVer::format($parsed);
            $this->assertSame($version, $formatted, "Failed round-trip for: {$version}");
        }
    }
}
