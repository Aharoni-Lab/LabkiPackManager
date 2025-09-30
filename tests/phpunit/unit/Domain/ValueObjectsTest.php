<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Domain;

use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Domain\PackId
 * @covers \LabkiPackManager\Domain\PageId
 */
class ValueObjectsTest extends TestCase {
	public function testEqualityAndToString(): void {
		$a = new PackId( 'core' );
		$b = PackId::fromString( 'core' );
		$c = new PackId( 'feature' );
		$this->assertTrue( $a->equals( $b ) );
		$this->assertFalse( $a->equals( $c ) );
		$this->assertSame( 'core', (string)$a );

		$p1 = new PageId( 'Home' );
		$p2 = PageId::fromString( 'Home' );
		$this->assertTrue( $p1->equals( $p2 ) );
		$this->assertSame( 'Home', (string)$p1 );
	}
}


