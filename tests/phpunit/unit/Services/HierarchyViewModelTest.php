<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;
use LabkiPackManager\Services\HierarchyBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\HierarchyBuilder
 */
class HierarchyViewModelTest extends TestCase {
    public function testViewModelCountsAndRoots(): void {
        $core = new Pack( new PackId( 'core' ), null, null, [], [], [ new PageId( 'Home' ), new PageId( 'About' ) ] );
        $extra = new Pack( new PackId( 'extra' ), null, null, [], [], [ new PageId( 'X' ) ] );
        $bundle = new Pack( new PackId( 'bundle' ), null, null, [ new PackId( 'core' ), new PackId( 'extra' ) ], [], [] );
        $h = new HierarchyBuilder();
        $vm = $h->buildViewModel( [ $core, $extra, $bundle ] );
        $this->assertSame( 3, $vm['packCount'] );
        $this->assertSame( 3, $vm['pageCount'] );
        $this->assertSame( [ 'pack:bundle' ], $vm['roots'] );
        $this->assertArrayHasKey( 'pack:core', $vm['nodes'] );
        $this->assertArrayHasKey( 'page:Home', $vm['nodes'] );
        $this->assertGreaterThanOrEqual( 1, $vm['nodes']['pack:bundle']['packsBeneath'] );
        $this->assertGreaterThanOrEqual( 2, $vm['nodes']['pack:bundle']['pagesBeneath'] );
    }
}


