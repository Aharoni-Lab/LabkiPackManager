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
				[ 'id' => 'bundle', 'contains' => [ 'core', 'extra' ] ],
				[ 'id' => 'extra', 'pages' => [ 'X' ] ],
			],
		];
		$adapter = new ManifestSchemaAdapter();
		$out = $adapter->toDomain( $raw );
		$this->assertSame( '1.0.0', $out['schema_version'] );
		$this->assertCount( 3, $out['packs'] );
		$this->assertSame( 'core', $out['packs'][0]->getIdString() );
		$this->assertSame( 'bundle', $out['packs'][1]->getIdString() );
		$this->assertCount( 2, $out['packs'][0]->getIncludedPages() );
		$this->assertCount( 2, $out['packs'][1]->getContainedPacks() );
	}

	/**
	 * @covers ::toDomain
	 */
	public function testInvalidReferencesThrow(): void {
		$this->expectException( \InvalidArgumentException::class );
		$raw = [ 'packs' => [ [ 'id' => 'a', 'contains' => [ 'missing' ] ] ] ];
		(new ManifestSchemaAdapter())->toDomain( $raw );
	}

	/**
	 * @covers ::toDomain
	 */
	public function testSemanticRuleEnforced(): void {
		$this->expectException( \InvalidArgumentException::class );
		$raw = [ 'packs' => [ [ 'id' => 'thin' ] ] ];
		(new ManifestSchemaAdapter())->toDomain( $raw );
	}
}


