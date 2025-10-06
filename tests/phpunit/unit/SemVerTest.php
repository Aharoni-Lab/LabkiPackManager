<?php

declare(strict_types=1);

// Base test class is provided by MediaWiki's test harness
use LabkiPackManager\Util\SemVer;

/**
 * @covers \LabkiPackManager\Util\SemVer::compare
 * @covers \LabkiPackManager\Util\SemVer::sameMajor
 * @covers \LabkiPackManager\Util\SemVer::parse
 */
final class SemVerTest extends \MediaWikiUnitTestCase {
    public function testParseVariants(): void {
        $cases = [
            [ null, [0,0,0] ],
            [ '', [0,0,0] ],
            [ '1', [1,0,0] ],
            [ '1.2', [1,2,0] ],
            [ '1.2.3', [1,2,3] ],
            [ 'v1.2.3', [1,2,3] ],
            [ '1.2.3-alpha', [1,2,3] ],
            [ '1.2.3+build.5', [1,2,3] ],
            [ 'v1.2.3-rc.1+build.5', [1,2,3] ],
            [ '01.002.0003', [1,2,3] ],
            [ '1.2.3beta', [1,2,3] ],
            [ '  v2.0.1  ', [2,0,1] ],
            [ '1.2.x', [1,2,0] ],
        ];
        foreach ( $cases as [ $input, $expected ] ) {
            $this->assertSame( $expected, \LabkiPackManager\Util\SemVer::parse( $input ) );
        }
    }

    public function testCompareBasic(): void {
        $this->assertSame(0, SemVer::compare('1.2.3','1.2.3'));
        $this->assertLessThan(0, SemVer::compare('1.2.3','1.2.4'));
        $this->assertGreaterThan(0, SemVer::compare('1.3.0','1.2.9'));
        $this->assertLessThan(0, SemVer::compare('0.9.9','1.0.0'));
    }

    public function testCompareWithNullsAndSuffixes(): void {
        $this->assertLessThan(0, SemVer::compare(null, '1.0.0'));
        $this->assertGreaterThan(0, SemVer::compare('1.0.0', null));
        $this->assertSame(0, SemVer::compare('1.2.3-alpha', '1.2.3+build'));
        $this->assertLessThan(0, SemVer::compare('1.2.3', '1.2.4-alpha'));
        $this->assertGreaterThan(0, SemVer::compare('2.0.0-rc.1', '1.9.9'));
    }

    public function testSameMajor(): void {
        $this->assertTrue(SemVer::sameMajor('1.0.0','1.9.9'));
        $this->assertFalse(SemVer::sameMajor('1.0.0','2.0.0'));
        $this->assertTrue(SemVer::sameMajor('v1.2.3','1.5.0'));
        $this->assertTrue(SemVer::sameMajor('1.0.0-alpha','1.0.0+build'));
        $this->assertTrue(SemVer::sameMajor(null,'0.1.0'));
        $this->assertFalse(SemVer::sameMajor(null,'1.0.0'));
    }
}
