<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Services\HierarchyBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\HierarchyBuilder
 */
class HierarchyBuilderTest extends TestCase {

	public function testBuildWithSinglePack(): void {
		$manifest = [
			'packs' => [
				'simple-pack' => [
					'id' => 'simple-pack',
					'version' => '1.0.0',
					'description' => 'A simple pack',
					'pages' => [ 'Page1', 'Page2' ],
					'depends_on' => [],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$this->assertArrayHasKey( 'root_nodes', $hierarchy );
		$this->assertArrayHasKey( 'meta', $hierarchy );
		
		$this->assertCount( 1, $hierarchy['root_nodes'] );
		
		$root = $hierarchy['root_nodes'][0];
		$this->assertEquals( 'pack:simple-pack', $root['id'] );
		$this->assertEquals( 'simple-pack', $root['label'] );
		$this->assertEquals( 'pack', $root['type'] );
		$this->assertEquals( 'A simple pack', $root['description'] );
		$this->assertEquals( '1.0.0', $root['version'] );
		$this->assertEquals( [], $root['depends_on'] );
		
		// Check children (pages)
		$this->assertCount( 2, $root['children'] );
		$this->assertEquals( 'page:Page1', $root['children'][0]['id'] );
		$this->assertEquals( 'Page1', $root['children'][0]['label'] );
		$this->assertEquals( 'page', $root['children'][0]['type'] );
		
		// Check stats
		$this->assertEquals( 0, $root['stats']['packs_beneath'] );
		$this->assertEquals( 2, $root['stats']['pages_beneath'] );
		
		// Check meta
		$this->assertEquals( 1, $hierarchy['meta']['pack_count'] );
		$this->assertEquals( 2, $hierarchy['meta']['page_count'] );
		$this->assertNotEmpty( $hierarchy['meta']['timestamp'] );
	}

	public function testBuildWithDependencies(): void {
		$manifest = [
			'packs' => [
				'base-pack' => [
					'id' => 'base-pack',
					'version' => '1.0.0',
					'pages' => [ 'BasePage' ],
					'depends_on' => [],
				],
				'dependent-pack' => [
					'id' => 'dependent-pack',
					'version' => '1.0.0',
					'pages' => [ 'DependentPage' ],
					'depends_on' => [ 'base-pack' ],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		// Only dependent-pack should be a root (base-pack is depended on)
		$this->assertCount( 1, $hierarchy['root_nodes'] );
		
		$root = $hierarchy['root_nodes'][0];
		$this->assertEquals( 'pack:dependent-pack', $root['id'] );
		
		// base-pack should be a child (dependency)
		$this->assertCount( 2, $root['children'] ); // 1 dependency + 1 page
		
		// Find the base-pack child
		$basePack = null;
		foreach ( $root['children'] as $child ) {
			if ( $child['type'] === 'pack' ) {
				$basePack = $child;
				break;
			}
		}
		
		$this->assertNotNull( $basePack );
		$this->assertEquals( 'pack:base-pack', $basePack['id'] );
		$this->assertCount( 1, $basePack['children'] ); // BasePage
	}

	public function testBuildWithMultipleRoots(): void {
		$manifest = [
			'packs' => [
				'root1' => [
					'id' => 'root1',
					'pages' => [ 'Page1' ],
					'depends_on' => [],
				],
				'root2' => [
					'id' => 'root2',
					'pages' => [ 'Page2' ],
					'depends_on' => [],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$this->assertCount( 2, $hierarchy['root_nodes'] );
		$this->assertEquals( 2, $hierarchy['meta']['pack_count'] );
		$this->assertEquals( 2, $hierarchy['meta']['page_count'] );
	}

	public function testBuildWithDeeplyNestedDependencies(): void {
		$manifest = [
			'packs' => [
				'level-0' => [
					'id' => 'level-0',
					'pages' => [],
					'depends_on' => [],
				],
				'level-1' => [
					'id' => 'level-1',
					'pages' => [],
					'depends_on' => [ 'level-0' ],
				],
				'level-2' => [
					'id' => 'level-2',
					'pages' => [ 'FinalPage' ],
					'depends_on' => [ 'level-1' ],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		// Only level-2 should be root
		$this->assertCount( 1, $hierarchy['root_nodes'] );
		$root = $hierarchy['root_nodes'][0];
		$this->assertEquals( 'pack:level-2', $root['id'] );
		
		// Check stats - should count all descendants
		$this->assertEquals( 2, $root['stats']['packs_beneath'] );
		$this->assertEquals( 1, $root['stats']['pages_beneath'] );
	}

	public function testBuildWithEmptyManifest(): void {
		$manifest = [
			'packs' => [],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$this->assertArrayHasKey( 'root_nodes', $hierarchy );
		$this->assertArrayHasKey( 'meta', $hierarchy );
		$this->assertCount( 0, $hierarchy['root_nodes'] );
		$this->assertEquals( 0, $hierarchy['meta']['pack_count'] );
		$this->assertEquals( 0, $hierarchy['meta']['page_count'] );
	}

	public function testBuildWithNoPagesSection(): void {
		$manifest = [
			'packs' => [
				'pack1' => [
					'id' => 'pack1',
					'pages' => [ 'Page1' ],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$this->assertCount( 1, $hierarchy['root_nodes'] );
		$this->assertEquals( 1, $hierarchy['meta']['page_count'] );
	}

	public function testBuildHandlesPackWithNoPages(): void {
		$manifest = [
			'packs' => [
				'empty-pack' => [
					'id' => 'empty-pack',
					'version' => '1.0.0',
					'pages' => [],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$root = $hierarchy['root_nodes'][0];
		$this->assertEquals( 'pack:empty-pack', $root['id'] );
		$this->assertCount( 0, $root['children'] );
		$this->assertEquals( 0, $root['stats']['pages_beneath'] );
		$this->assertEquals( 0, $hierarchy['meta']['page_count'] );
	}

	public function testBuildHandlesInvalidPackDefinitions(): void {
		$manifest = [
			'packs' => [
				'' => [ 'pages' => [ 'Page1' ] ], // Empty ID - should be ignored
				'valid-pack' => [
					'id' => 'valid-pack',
					'pages' => [ 'Page2' ],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$this->assertCount( 1, $hierarchy['root_nodes'] );
		$this->assertEquals( 'pack:valid-pack', $hierarchy['root_nodes'][0]['id'] );
	}

	public function testBuildHandlesMissingDependencies(): void {
		$manifest = [
			'packs' => [
				'pack-with-bad-dep' => [
					'id' => 'pack-with-bad-dep',
					'pages' => [ 'Page1' ],
					'depends_on' => [ 'non-existent-pack' ], // Missing dependency
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$root = $hierarchy['root_nodes'][0];
		$this->assertEquals( 'pack:pack-with-bad-dep', $root['id'] );
		
		// Should only have pages, not the missing dependency
		$this->assertCount( 1, $root['children'] );
		$this->assertEquals( 'page', $root['children'][0]['type'] );
	}

	public function testBuildHandlesCyclicDependencies(): void {
		$manifest = [
			'packs' => [
				'pack-a' => [
					'id' => 'pack-a',
					'pages' => [],
					'depends_on' => [ 'pack-b' ],
				],
				'pack-b' => [
					'id' => 'pack-b',
					'pages' => [],
					'depends_on' => [ 'pack-a' ], // Cycle: A -> B -> A
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		// Should handle gracefully without infinite recursion
		$this->assertArrayHasKey( 'root_nodes', $hierarchy );
		
		// In a cycle, no pack is a root (all have incoming dependencies)
		$this->assertCount( 0, $hierarchy['root_nodes'] );
	}

	public function testBuildCountsPagesTotalAcrossAllPacks(): void {
		$manifest = [
			'packs' => [
				'pack1' => [
					'id' => 'pack1',
					'pages' => [ 'A', 'B', 'C' ],
				],
				'pack2' => [
					'id' => 'pack2',
					'pages' => [ 'D', 'E' ],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$this->assertEquals( 2, $hierarchy['meta']['pack_count'] );
		$this->assertEquals( 5, $hierarchy['meta']['page_count'] );
	}

	public function testBuildPreservesPackMetadata(): void {
		$manifest = [
			'packs' => [
				'metadata-pack' => [
					'id' => 'metadata-pack',
					'version' => '2.3.1',
					'description' => 'Test description',
					'pages' => [ 'TestPage' ],
					'depends_on' => [],
				],
			],
		];

		$builder = new HierarchyBuilder();
		$hierarchy = $builder->build( $manifest );

		$root = $hierarchy['root_nodes'][0];
		$this->assertEquals( '2.3.1', $root['version'] );
		$this->assertEquals( 'Test description', $root['description'] );
		$this->assertEquals( [], $root['depends_on'] );
	}
}

