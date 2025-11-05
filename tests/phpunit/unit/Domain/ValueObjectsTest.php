<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Domain;

use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\OperationId;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Domain\ContentRepoId
 * @covers \LabkiPackManager\Domain\ContentRefId
 * @covers \LabkiPackManager\Domain\OperationId
 * @covers \LabkiPackManager\Domain\PackId
 * @covers \LabkiPackManager\Domain\PageId
 */
class ValueObjectsTest extends TestCase {
    public function testEqualityAndToStringAndToInt(): void {
        // ContentRepoId
        $repoA = new ContentRepoId( 1 );
        $repoB = new ContentRepoId( 1 );
        $repoC = new ContentRepoId( 2 );
        $this->assertTrue( $repoA->equals( $repoB ) );
        $this->assertFalse( $repoA->equals( $repoC ) );
        $this->assertSame( 1, $repoA->toInt() );
        $this->assertSame( '1', (string)$repoA );

        // ContentRefId
        $refA = new ContentRefId( 5 );
        $refB = new ContentRefId( 5 );
        $refC = new ContentRefId( 6 );
        $this->assertTrue( $refA->equals( $refB ) );
        $this->assertFalse( $refA->equals( $refC ) );
        $this->assertSame( 5, $refA->toInt() );
        $this->assertSame( '5', (string)$refA );

        // PackId
        $packA = new PackId( 10 );
        $packB = new PackId( 10 );
        $packC = new PackId( 11 );
        $this->assertTrue( $packA->equals( $packB ) );
        $this->assertFalse( $packA->equals( $packC ) );
        $this->assertSame( 10, $packA->toInt() );
        $this->assertSame( '10', (string)$packA );

        // PageId
        $pageA = new PageId( 100 );
        $pageB = new PageId( 100 );
        $pageC = new PageId( 101 );
        $this->assertTrue( $pageA->equals( $pageB ) );
        $this->assertFalse( $pageA->equals( $pageC ) );
        $this->assertSame( 100, $pageA->toInt() );
        $this->assertSame( '100', (string)$pageA );
    }

    public function testOperationId(): void {
        // OperationId (string-based, unlike the int-based IDs above)
        $opA = new OperationId( 'repo_add_abc123' );
        $opB = new OperationId( 'repo_add_abc123' );
        $opC = new OperationId( 'pack_apply_xyz789' );
        
        $this->assertTrue( $opA->equals( $opB ) );
        $this->assertFalse( $opA->equals( $opC ) );
        $this->assertSame( 'repo_add_abc123', $opA->toString() );
        $this->assertSame( 'repo_add_abc123', (string)$opA );
    }
}


