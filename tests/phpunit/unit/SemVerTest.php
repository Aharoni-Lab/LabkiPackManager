<?php

declare(strict_types=1);

// Base test class is provided by MediaWiki's test harness
use LabkiPackManager\Util\SemVer;

/**
 * @covers \LabkiPackManager\Util\SemVer::compare
 * @covers \LabkiPackManager\Util\SemVer::sameMajor
 */
final class SemVerTest extends \MediaWikiUnitTestCase {
    public function testCompareBasic(): void {
        $this->assertSame(0, SemVer::compare('1.2.3','1.2.3'));
        $this->assertLessThan(0, SemVer::compare('1.2.3','1.2.4'));
        $this->assertGreaterThan(0, SemVer::compare('1.3.0','1.2.9'));
        $this->assertLessThan(0, SemVer::compare('0.9.9','1.0.0'));
    }

    public function testSameMajor(): void {
        $this->assertTrue(SemVer::sameMajor('1.0.0','1.9.9'));
        $this->assertFalse(SemVer::sameMajor('1.0.0','2.0.0'));
        $this->assertTrue(SemVer::sameMajor('v1.2.3','1.5.0'));
    }
}
