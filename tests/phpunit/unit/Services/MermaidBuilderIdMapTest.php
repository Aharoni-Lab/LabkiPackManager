<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Services\MermaidBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Services\MermaidBuilder
 */
class MermaidBuilderIdMapTest extends TestCase {
    public function testIdMapStableAssignment(): void {
        $edges = [
            [ 'from' => 'pack:core', 'to' => 'pack:extra' ],
            [ 'from' => 'pack:core', 'to' => 'pack:more' ],
        ];
        $b = new MermaidBuilder();
        $out = $b->generateWithIdMap( $edges );
        $this->assertArrayHasKey( 'pack:core', $out['idMap'] );
        $this->assertArrayHasKey( 'pack:extra', $out['idMap'] );
        $this->assertStringContainsString( 'graph LR', $out['code'] );
    }
}


