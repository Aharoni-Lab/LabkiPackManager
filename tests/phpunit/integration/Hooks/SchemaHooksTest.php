<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Hooks;

use DatabaseUpdater;
use LabkiPackManager\Hooks\SchemaHooks;
use LabkiPackManager\Maintenance\InitializeContentRepos;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IDatabase;

/**
 * Tests for SchemaHooks
 *
 * SchemaHooks registers database tables and schedules post-update maintenance tasks.
 * These tests verify that tables are correctly registered and maintenance tasks are scheduled.
 *
 * @coversDefaultClass \LabkiPackManager\Hooks\SchemaHooks
 */
final class SchemaHooksTest extends MediaWikiIntegrationTestCase {

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_RegistersContentRepoTable(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Track all table registrations
        $tables = [];
        $updater->method('addExtensionTable')
            ->willReturnCallback(function($table, $path) use (&$tables) {
                $tables[] = $table;
            });

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);

        // Verify labki_content_repo was registered
        $this->assertContains('labki_content_repo', $tables);
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_RegistersContentRefTable(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Track all table registrations
        $tables = [];
        $updater->method('addExtensionTable')
            ->willReturnCallback(function($table, $path) use (&$tables) {
                $tables[] = $table;
            });

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);

        // Verify labki_content_ref was registered
        $this->assertContains('labki_content_ref', $tables);
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_RegistersPackTable(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Track all table registrations
        $tables = [];
        $updater->method('addExtensionTable')
            ->willReturnCallback(function($table, $path) use (&$tables) {
                $tables[] = $table;
            });

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);

        // Verify labki_pack was registered
        $this->assertContains('labki_pack', $tables);
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_RegistersPageTable(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Track all table registrations
        $tables = [];
        $updater->method('addExtensionTable')
            ->willReturnCallback(function($table, $path) use (&$tables) {
                $tables[] = $table;
            });

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);

        // Verify labki_page was registered
        $this->assertContains('labki_page', $tables);
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_RegistersAllFourTables(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Expect addExtensionTable to be called exactly 4 times
        $updater->expects($this->exactly(4))
            ->method('addExtensionTable')
            ->with(
                $this->logicalOr(
                    $this->equalTo('labki_content_repo'),
                    $this->equalTo('labki_content_ref'),
                    $this->equalTo('labki_pack'),
                    $this->equalTo('labki_page')
                ),
                $this->stringContains('sql/sqlite/tables.sql')
            );

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_SchedulesInitializeContentReposMaintenance(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Expect addPostDatabaseUpdateMaintenance to be called with InitializeContentRepos class
        $updater->expects($this->once())
            ->method('addPostDatabaseUpdateMaintenance')
            ->with($this->equalTo(InitializeContentRepos::class));

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_UsesSqliteSchemaForAllDatabaseTypes(): void {
        $databaseTypes = ['sqlite', 'mysql', 'postgres'];

        foreach ($databaseTypes as $dbType) {
            $db = $this->createMock(IDatabase::class);
            $db->method('getType')->willReturn($dbType);

            $updater = $this->createMock(DatabaseUpdater::class);
            $updater->method('getDB')->willReturn($db);

            // All database types should use sqlite schema (for now)
            $updater->expects($this->atLeastOnce())
                ->method('addExtensionTable')
                ->with(
                    $this->anything(),
                    $this->stringContains('sql/sqlite/tables.sql')
                );

            SchemaHooks::onLoadExtensionSchemaUpdates($updater);
        }
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_UsesCorrectTableFilePath(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Capture the actual path used
        $capturedPath = null;
        $updater->expects($this->atLeastOnce())
            ->method('addExtensionTable')
            ->willReturnCallback(function ($table, $path) use (&$capturedPath) {
                $capturedPath = $path;
            });

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);

        // Verify path structure
        $this->assertNotNull($capturedPath, 'Path should be captured');
        $this->assertStringContainsString('sql', $capturedPath, 'Path should contain sql directory');
        $this->assertStringContainsString('sqlite', $capturedPath, 'Path should contain sqlite directory');
        $this->assertStringContainsString('tables.sql', $capturedPath, 'Path should contain tables.sql file');
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_TablesRegisteredInCorrectOrder(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Track the order of table registrations
        $tableOrder = [];
        $updater->expects($this->exactly(4))
            ->method('addExtensionTable')
            ->willReturnCallback(function ($table, $path) use (&$tableOrder) {
                $tableOrder[] = $table;
            });

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);

        // Verify expected order (content_repo, content_ref, pack, page)
        $this->assertSame('labki_content_repo', $tableOrder[0], 'First table should be labki_content_repo');
        $this->assertSame('labki_content_ref', $tableOrder[1], 'Second table should be labki_content_ref');
        $this->assertSame('labki_pack', $tableOrder[2], 'Third table should be labki_pack');
        $this->assertSame('labki_page', $tableOrder[3], 'Fourth table should be labki_page');
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_MaintenanceScheduledAfterTables(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Track call order
        $callOrder = [];
        
        $updater->method('addExtensionTable')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'table';
            });

        $updater->method('addPostDatabaseUpdateMaintenance')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'maintenance';
            });

        SchemaHooks::onLoadExtensionSchemaUpdates($updater);

        // Verify maintenance is scheduled after tables
        $this->assertGreaterThan(
            array_search('table', $callOrder),
            array_search('maintenance', $callOrder),
            'Maintenance should be scheduled after at least one table'
        );
    }

    /**
     * @covers ::onLoadExtensionSchemaUpdates
     */
    public function testOnLoadExtensionSchemaUpdates_HandlesMultipleCalls(): void {
        $db = $this->createMock(IDatabase::class);
        $db->method('getType')->willReturn('sqlite');

        $updater = $this->createMock(DatabaseUpdater::class);
        $updater->method('getDB')->willReturn($db);

        // Call the hook twice
        SchemaHooks::onLoadExtensionSchemaUpdates($updater);
        SchemaHooks::onLoadExtensionSchemaUpdates($updater);

        // Should not throw any exceptions
        $this->assertTrue(true, 'Multiple calls should not cause errors');
    }
}

