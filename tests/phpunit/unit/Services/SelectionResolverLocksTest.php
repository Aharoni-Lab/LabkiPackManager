<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Services\SelectionResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\SelectionResolver
 */
class SelectionResolverLocksTest extends TestCase {
    public function testResolveWithLocksAddsLockReasons(): void {
        $a = new Pack( new PackId( 'A' ), 'A', null, [], [ new PackId( 'B' ) ], [] );
        $b = new Pack( new PackId( 'B' ), 'B', null, [], [], [] );
        $r = new SelectionResolver();
        $out = $r->resolveWithLocks( [ $a, $b ], [ 'A' ] );
        $this->assertContains( 'A', $out['packs'] );
        $this->assertArrayHasKey( 'B', $out['locks'] );
        $this->assertStringContainsString( 'Required by A', $out['locks']['B'] );
    }
}


