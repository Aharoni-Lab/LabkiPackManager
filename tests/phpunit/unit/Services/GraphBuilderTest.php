<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Services\GraphBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\GraphBuilder
 */
class GraphBuilderTest extends TestCase {

	public function testBuildWithSinglePack(): void {
		$packs = [
			[
				'id' => 'simple-pack',
				'pages' => [ 'Page1', 'Page2' ],
				'depends_on' => [],
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		$this->assertArrayHasKey( 'containsEdges', $graph );
		$this->assertArrayHasKey( 'dependsEdges', $graph );
		$this->assertArrayHasKey( 'roots', $graph );
		$this->assertArrayHasKey( 'hasCycle', $graph );

		// Contains edges: pack -> pages
		$this->assertCount( 2, $graph['containsEdges'] );
		$this->assertEquals( 'pack:simple-pack', $graph['containsEdges'][0]['from'] );
		$this->assertEquals( 'page:Page1', $graph['containsEdges'][0]['to'] );
		$this->assertEquals( 'pack:simple-pack', $graph['containsEdges'][1]['from'] );
		$this->assertEquals( 'page:Page2', $graph['containsEdges'][1]['to'] );

		// No dependency edges
		$this->assertCount( 0, $graph['dependsEdges'] );

		// Should be a root (no dependencies)
		$this->assertContains( 'pack:simple-pack', $graph['roots'] );

		// No cycles
		$this->assertFalse( $graph['hasCycle'] );
	}

	public function testBuildWithDependencies(): void {
		$packs = [
			[
				'id' => 'base-pack',
				'pages' => [ 'BasePage' ],
				'depends_on' => [],
			],
			[
				'id' => 'dependent-pack',
				'pages' => [ 'DependentPage' ],
				'depends_on' => [ 'base-pack' ],
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		// Depends edges: dependent-pack -> base-pack
		$this->assertCount( 1, $graph['dependsEdges'] );
		$this->assertEquals( 'pack:dependent-pack', $graph['dependsEdges'][0]['from'] );
		$this->assertEquals( 'pack:base-pack', $graph['dependsEdges'][0]['to'] );

		// Contains edges: both packs -> their pages
		$this->assertCount( 2, $graph['containsEdges'] );

		// Only dependent-pack should be root (base-pack is depended on)
		$this->assertContains( 'pack:dependent-pack', $graph['roots'] );
		$this->assertNotContains( 'pack:base-pack', $graph['roots'] );

		// No cycle
		$this->assertFalse( $graph['hasCycle'] );
	}

	public function testBuildDetectsCycle(): void {
		$packs = [
			[
				'id' => 'pack-a',
				'pages' => [],
				'depends_on' => [ 'pack-b' ],
			],
			[
				'id' => 'pack-b',
				'pages' => [],
				'depends_on' => [ 'pack-a' ], // Cycle: A -> B -> A
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		$this->assertTrue( $graph['hasCycle'] );

		// In a cycle, no pack is a root (all have incoming dependencies)
		$this->assertCount( 0, $graph['roots'] );
	}

	public function testBuildDetectsSelfCycle(): void {
		$packs = [
			[
				'id' => 'pack-a',
				'pages' => [],
				'depends_on' => [ 'pack-b' ],
			],
			[
				'id' => 'pack-b',
				'pages' => [],
				'depends_on' => [ 'pack-c' ],
			],
			[
				'id' => 'pack-c',
				'pages' => [],
				'depends_on' => [ 'pack-a' ], // Cycle: A -> B -> C -> A
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		$this->assertTrue( $graph['hasCycle'] );
	}

	public function testBuildIgnoresSelfDependency(): void {
		$packs = [
			[
				'id' => 'self-dep-pack',
				'pages' => [ 'Page1' ],
				'depends_on' => [ 'self-dep-pack' ], // Self-dependency - should be ignored
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		// Should not create a self-dependency edge
		$this->assertCount( 0, $graph['dependsEdges'] );
		$this->assertFalse( $graph['hasCycle'] );
		$this->assertContains( 'pack:self-dep-pack', $graph['roots'] );
	}

	public function testBuildWithComplexGraph(): void {
		$packs = [
			[
				'id' => 'core',
				'pages' => [ 'CorePage1', 'CorePage2' ],
				'depends_on' => [],
			],
			[
				'id' => 'utils',
				'pages' => [ 'UtilPage' ],
				'depends_on' => [ 'core' ],
			],
			[
				'id' => 'app1',
				'pages' => [ 'App1Page' ],
				'depends_on' => [ 'core', 'utils' ],
			],
			[
				'id' => 'app2',
				'pages' => [ 'App2Page' ],
				'depends_on' => [ 'core' ],
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		// Contains edges: 4 packs × their pages
		$this->assertCount( 5, $graph['containsEdges'] ); // 2 + 1 + 1 + 1

		// Depends edges: 4 total
		$this->assertCount( 4, $graph['dependsEdges'] );

		// Roots: app1 and app2 (nothing depends on them)
		$this->assertContains( 'pack:app1', $graph['roots'] );
		$this->assertContains( 'pack:app2', $graph['roots'] );
		$this->assertCount( 2, $graph['roots'] );

		// No cycle
		$this->assertFalse( $graph['hasCycle'] );
	}

	public function testBuildWithEmptyPacks(): void {
		$packs = [];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		$this->assertCount( 0, $graph['containsEdges'] );
		$this->assertCount( 0, $graph['dependsEdges'] );
		$this->assertCount( 0, $graph['roots'] );
		$this->assertFalse( $graph['hasCycle'] );
	}

	public function testBuildHandlesPacksWithNoId(): void {
		$packs = [
			[
				'pages' => [ 'Page1' ],
			],
			[
				'id' => 'valid-pack',
				'pages' => [ 'Page2' ],
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		// First pack should be ignored (no ID)
		$this->assertCount( 1, $graph['containsEdges'] );
		$this->assertEquals( 'pack:valid-pack', $graph['containsEdges'][0]['from'] );
	}

	public function testBuildTrimsWhitespaceInPageNames(): void {
		$packs = [
			[
				'id' => 'test-pack',
				'pages' => [ '  Page1  ', '', '  ', 'Page2' ],
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		// Should only create edges for non-empty trimmed pages
		$this->assertCount( 2, $graph['containsEdges'] );
		$this->assertEquals( 'page:Page1', $graph['containsEdges'][0]['to'] );
		$this->assertEquals( 'page:Page2', $graph['containsEdges'][1]['to'] );
	}

	public function testBuildHandlesNumericPackArrays(): void {
		// Test with numeric array instead of associative
		$packs = [
			[
				'id' => 'pack1',
				'pages' => [ 'Page1' ],
				'depends_on' => [],
			],
			[
				'id' => 'pack2',
				'pages' => [ 'Page2' ],
				'depends_on' => [ 'pack1' ],
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		$this->assertCount( 2, $graph['containsEdges'] );
		$this->assertCount( 1, $graph['dependsEdges'] );
		$this->assertFalse( $graph['hasCycle'] );
	}

	public function testBuildRootsAreSorted(): void {
		$packs = [
			[
				'id' => 'z-pack',
				'pages' => [],
			],
			[
				'id' => 'a-pack',
				'pages' => [],
			],
			[
				'id' => 'm-pack',
				'pages' => [],
			],
		];

		$builder = new GraphBuilder();
		$graph = $builder->build( $packs );

		// Roots should be sorted alphabetically for deterministic output
		$this->assertEquals( 'pack:a-pack', $graph['roots'][0] );
		$this->assertEquals( 'pack:m-pack', $graph['roots'][1] );
		$this->assertEquals( 'pack:z-pack', $graph['roots'][2] );
	}
}

