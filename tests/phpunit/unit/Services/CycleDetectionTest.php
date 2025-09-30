<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Services\GraphBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\GraphBuilder
 */
class CycleDetectionTest extends TestCase {
	public function testDetectsCycleOnDepends(): void {
		$a = new Pack( new PackId( 'A' ), null, null, [], [ new PackId( 'B' ) ] );
		$b = new Pack( new PackId( 'B' ), null, null, [], [ new PackId( 'A' ) ] );
		$builder = new GraphBuilder();
		$out = $builder->build( [ $a, $b ] );
		$this->assertTrue( $out['hasCycle'] );
	}
}


