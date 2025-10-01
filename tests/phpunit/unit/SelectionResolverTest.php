<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use LabkiPackManager\Services\SelectionResolver;
use LabkiPackManager\Domain\Pack;
use LabkiPackManager\Domain\PackId;
use LabkiPackManager\Domain\PageId;

/**
 * @covers \LabkiPackManager\Services\SelectionResolver::resolve
 */
final class SelectionResolverTest extends TestCase {
    private function pack(string $id, array $pages = [], array $depends = []): Pack {
        $packId = new PackId($id);
        $pageObjs = array_map(static fn($p) => new PageId($p), $pages);
        $depObjs = array_map(static fn($d) => new PackId($d), $depends);
        return new Pack($packId, null, '1.0.0', [], $depObjs, $pageObjs);
    }

    public function testPageOwnersAndClosure(): void {
        $resolver = new SelectionResolver();
        $packs = [
            $this->pack('a', ['A','B'], ['b']),
            $this->pack('b', ['B','C'], []),
            $this->pack('c', ['C'], []),
        ];
        $out = $resolver->resolve($packs, ['a']);
        sort($out['packs']);
        sort($out['pages']);
        $this->assertSame(['a','b'], $out['packs']);
        $this->assertSame(['A','B','C'], $out['pages']);
        $owners = $out['pageOwners'];
        $this->assertSame(['a'], $owners['A']);
        sort($owners['B']); $this->assertSame(['a','b'], $owners['B']);
        $this->assertSame(['b'], $owners['C']);
    }
}
