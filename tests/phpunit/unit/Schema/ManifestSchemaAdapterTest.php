<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Schema;

use LabkiPackManager\Schema\ManifestSchemaAdapter;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \LabkiPackManager\Schema\ManifestSchemaAdapter
 */
class ManifestSchemaAdapterTest extends TestCase {
	/**
	 * @covers ::toDomain
	 */
	public function testMapsBasicManifestToDomain(): void {
		$raw = [
			'schema_version' => '1.0.0',
			'packs' => [
				[ 'id' => 'core', 'pages' => [ 'Home', 'About' ] ],
				[ 'id' => 'feature', 'depends' => [ 'core' ], 'pages' => [ 'Feature' ] ],
			],
		];
		$adapter = new ManifestSchemaAdapter();
		$out = $adapter->toDomain( $raw );
		$this->assertSame( '1.0.0', $out['schema_version'] );
		$this->assertCount( 2, $out['packs'] );
		$this->assertSame( 'core', $out['packs'][0]->getIdString() );
		$this->assertSame( 'feature', $out['packs'][1]->getIdString() );
		$this->assertCount( 2, $out['packs'][0]->getIncludedPages() );
		$this->assertCount( 1, $out['packs'][1]->getDependsOnPacks() );
	}
}


