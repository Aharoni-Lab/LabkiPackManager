<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Domain;

use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Domain\ContentRepoId
 * @covers \LabkiPackManager\Domain\PackId
 * @covers \LabkiPackManager\Domain\PageId
 */
class ValueObjectsTest extends TestCase {
    public function testEqualityAndToStringAndToInt(): void {
        $repoA = new ContentRepoId( 1 );
        $repoB = new ContentRepoId( 1 );
        $repoC = new ContentRepoId( 2 );
        $this->assertTrue( $repoA->equals( $repoB ) );
        $this->assertFalse( $repoA->equals( $repoC ) );
        $this->assertSame( 1, $repoA->toInt() );
        $this->assertSame( '1', (string)$repoA );

        $packA = new PackId( 10 );
        $packB = new PackId( 10 );
        $packC = new PackId( 11 );
        $this->assertTrue( $packA->equals( $packB ) );
        $this->assertFalse( $packA->equals( $packC ) );
        $this->assertSame( 10, $packA->toInt() );
        $this->assertSame( '10', (string)$packA );

        $pageA = new PageId( 100 );
        $pageB = new PageId( 100 );
        $pageC = new PageId( 101 );
        $this->assertTrue( $pageA->equals( $pageB ) );
        $this->assertFalse( $pageA->equals( $pageC ) );
        $this->assertSame( 100, $pageA->toInt() );
        $this->assertSame( '100', (string)$pageA );
    }
}


