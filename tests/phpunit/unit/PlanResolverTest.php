<?php

declare(strict_types=1);

use LabkiPackManager\Services\PlanResolver;

/**
 * @covers \LabkiPackManager\Services\PlanResolver
 */
final class PlanResolverTest extends \MediaWikiUnitTestCase {
    public function testRenameWithPrefixPreservesNamespace(): void {
        $resolver = new PlanResolver();
        $resolved = [ 'packs' => ['p1'], 'pages' => ['Template:Foo','Main:Bar','Baz'] ];
        $actions = [ 'globalPrefix' => 'ABC', 'pages' => [ 'Template:Foo' => [ 'action' => 'rename', 'renameTo' => 'Ren' ] ] ];
        $pf = [ 'lists' => [ 'external_collisions' => ['Template:Foo'], 'pack_pack_conflicts' => [] ] ];
        $plan = $resolver->resolve( $resolved, $actions, $pf );
        $pages = $plan['pages'];
        $this->assertSame('Template:ABC/Ren', $pages[0]['finalTitle']);
    }

    public function testSkipActionCounted(): void {
        $resolver = new PlanResolver();
        $resolved = [ 'packs' => ['p1'], 'pages' => ['Foo'] ];
        $actions = [ 'pages' => [ 'Foo' => [ 'action' => 'skip' ] ] ];
        $pf = [ 'lists' => [] ];
        $plan = $resolver->resolve( $resolved, $actions, $pf );
        $this->assertSame('skip', $plan['pages'][0]['action']);
        $this->assertArrayHasKey('summary', $plan);
        $this->assertIsArray($plan['summary']);
    }
}


