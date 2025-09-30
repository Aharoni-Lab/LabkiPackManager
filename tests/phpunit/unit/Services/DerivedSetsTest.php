<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;
use LabkiPackManager\Services\GraphBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\GraphBuilder
 */
class DerivedSetsTest extends TestCase {
    public function testDerivedSets(): void {
        $a = new Pack( new PackId( 'A' ), null, null, [], [], [ new PageId( 'A1' ) ] );
        $b = new Pack( new PackId( 'B' ), null, null, [], [ new PackId( 'A' ) ], [ new PageId( 'B1' ) ] );
        $c = new Pack( new PackId( 'C' ), null, null, [], [ new PackId( 'B' ) ], [ new PageId( 'C1' ) ] );
        $g = new GraphBuilder();
        $out = $g->build( [ $a, $b, $c ] );
        $this->assertSame( [ 'A' ], $out['rootPacks'] );
        $this->assertSame( [ 'B' => [ 'A' ], 'C' => [ 'B', 'A' ] ], $out['transitiveDepends'] );
        $this->assertArrayHasKey( 'A', $out['reverseDepends'] );
        $this->assertContains( 'B', $out['reverseDepends']['A'] );
    }
}


