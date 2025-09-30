<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;
use LabkiPackManager\Services\GraphBuilder;
use LabkiPackManager\Services\HierarchyBuilder;
use LabkiPackManager\Services\MermaidBuilder;
use LabkiPackManager\Services\SelectionResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\GraphBuilder
 * @covers \LabkiPackManager\Services\HierarchyBuilder
 * @covers \LabkiPackManager\Services\MermaidBuilder
 * @covers \LabkiPackManager\Services\SelectionResolver
 */
class GraphHierarchyMermaidSelectionTest extends TestCase {
    private function packs(): array {
        $core = new Pack( new PackId( 'core' ), null, null, [], [], [ new PageId( 'Home' ) ] );
        $feature = new Pack( new PackId( 'feature' ), null, null, [], [ new PackId( 'core' ) ], [ new PageId( 'Feature' ) ] );
		return [ $core, $feature ];
	}

	public function testGraphEdgesAndMermaid(): void {
		$g = new GraphBuilder();
        $edgesInfo = $g->build( $this->packs() );
        $this->assertSame( [ [ 'from' => 'feature', 'to' => 'core' ] ], $edgesInfo['dependsEdges'] );
		$m = new MermaidBuilder();
        $txt = $m->generate( $edgesInfo['dependsEdges'] );
		$this->assertStringContainsString( 'graph LR', $txt );
        $this->assertStringContainsString( 'feature --> core', $txt );
	}

	public function testHierarchyTree(): void {
		$h = new HierarchyBuilder();
		$tree = $h->buildTree( $this->packs() );
		$this->assertCount( 2, $tree );
		$this->assertSame( 'pack', $tree[0]['type'] );
		$this->assertSame( 'core', $tree[0]['id'] );
		$this->assertSame( 'page', $tree[0]['children'][0]['type'] );
	}

	public function testSelectionResolver(): void {
		$s = new SelectionResolver();
		$res = $s->resolve( $this->packs(), [ 'core' ] );
		$this->assertSame( [ 'core' ], $res['packs'] );
		$this->assertSame( [ 'Home' ], $res['pages'] );
	}
}


