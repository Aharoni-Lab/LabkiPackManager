<?php

declare(strict_types=1);

// Base test class is provided by MediaWiki's test harness
use LabkiPackManager\Services\InstalledRegistry;

/**
 * @group Database
 * @covers \LabkiPackManager\Services\InstalledRegistry::getInstalledMap
 */
final class InstalledRegistryTest extends \MediaWikiIntegrationTestCase {
    public function testInstalledMapReadsRegistry(): void {
        $dbw = $this->getDb();
        $dbw->upsert(
            'labki_pack_registry',
            [ 'pack_id' => 'publication', 'version' => '1.0.0', 'installed_at' => 1, 'installed_by' => 0 ],
            [ 'pack_id' ],
            [ 'version' => '1.0.0' ]
        );

        $svc = new InstalledRegistry();
        $map = $svc->getInstalledMap();
        $this->assertArrayHasKey('publication', $map);
        $this->assertSame('1.0.0', $map['publication']['version']);
    }
}


