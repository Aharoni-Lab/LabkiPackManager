<?php

declare(strict_types=1);

// Base test class is provided by MediaWiki's test harness
use LabkiPackManager\Services\InstalledRegistry;

/**
 * @group Database
 * @covers \LabkiPackManager\Services\InstalledRegistry::getInstalledMap
 */
final class InstalledRegistryTest extends \MediaWikiIntegrationTestCase {
    protected static $tablesUsed = [ 'page', 'labki_pack_registry' ];
    public function testInstalledMapReadsRegistry(): void {
        $dbw = $this->getDb();
        $dbw->upsert(
            'labki_pack_registry',
            [ 'pack_uid' => 'uid-publication-r1', 'pack_id' => 'publication', 'version' => '1.0.0', 'installed_at' => 1, 'installed_by' => 0 ],
            [ 'pack_uid' ],
            [ 'version' => '1.0.0' ]
        );

        $svc = new InstalledRegistry();
        $map = $svc->getInstalledMap();
        $this->assertArrayHasKey('uid-publication-r1', $map);
        $this->assertSame('1.0.0', $map['uid-publication-r1']['version']);
    }
}


