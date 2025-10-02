<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use LabkiPackManager\Services\PlanResolver;

/**
 * @covers \LabkiPackManager\Services\PlanResolver::resolve
 */
final class PlanResolverTest extends TestCase {
    public function testNamespacePreservingRename(): void {
        $resolver = new PlanResolver();
        $resolved = [ 'packs' => ['p'], 'pages' => [ 'Template:Card' ] ];
        $actions = [ 'globalPrefix' => 'PackX' ];
        $pf = [ 'lists' => [ 'external_collisions' => [ 'Template:Card' ] ] ];
        $plan = $resolver->resolve( $resolved, $actions, $pf );
        $this->assertIsArray( $plan['pages'] );
        $this->assertNotEmpty( $plan['pages'] );
        $this->assertSame( 'Template:PackX/Card', $plan['pages'][0]['finalTitle'] );
        $this->assertSame( 'rename', $plan['pages'][0]['action'] );
    }
}


