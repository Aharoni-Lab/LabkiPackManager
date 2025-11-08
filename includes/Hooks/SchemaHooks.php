<?php

declare(strict_types=1);

namespace LabkiPackManager\Hooks;

use DatabaseUpdater;
use LabkiPackManager\Maintenance\InitializeContentRepos;

/**
 * SchemaHooks
 *
 * Hook handler for database schema updates and maintenance tasks.
 *
 * This hook is responsible for:
 * - Registering database tables during MediaWiki installation/updates
 * - Scheduling post-update maintenance tasks (e.g., initializing content repositories)
 *
 * Tables managed:
 * - labki_content_repo: Content repository metadata
 * - labki_content_ref: Git references (branches/tags) for repositories
 * - labki_pack: Content pack metadata
 * - labki_page: Individual page metadata within packs
 * - labki_pack_dependency: Pack dependency relationships
 * - labki_operations: Background operation tracking
 */
final class SchemaHooks {

    /**
     * Handler for the LoadExtensionSchemaUpdates hook.
     *
     * Registers all LabkiPackManager database tables and schedules post-update
     * maintenance tasks. This hook is called during MediaWiki installation and
     * when running update.php.
     *
     * This extension uses MediaWiki's abstract schema format (sql/tables.json)
     * with auto-generated database-specific patches for MySQL/MariaDB and SQLite.
     *
     * Tables registered:
     * - labki_content_repo: Stores Git repository URLs and metadata
     * - labki_content_ref: Stores Git refs (branches/tags) for each repository
     * - labki_pack: Stores content pack information from manifests
     * - labki_page: Stores individual page information within packs
     * - labki_pack_dependency: Stores pack dependency relationships
     * - labki_operations: Stores background operation status and progress
     *
     * Post-update tasks:
     * - InitializeContentRepos: Clones repositories and sets up worktrees
     *
     * @param DatabaseUpdater $updater The database updater instance
     * @return void
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
     * @see https://www.mediawiki.org/wiki/Manual:Schema_changes#Automatically_generated
     */
    public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
        // Determine SQL directory path
        $dir = dirname( dirname( __DIR__ ) ) . '/sql';
        
        // Get database type (mysql, postgres, sqlite, etc.)
        $dbType = $updater->getDB()->getType();
        
        // Map database type to schema directory
        // MySQL and MariaDB share the same schema
        $dbDir = match ( $dbType ) {
            'mysql', 'mariadb' => 'mysql',
            'sqlite' => 'sqlite',
            'postgres' => 'postgres',
            default => 'mysql', // Fallback to MySQL for unknown types
        };
        
        // Construct path to generated tables SQL file
        $tablesFile = $dir . '/' . $dbDir . '/tables-generated.sql';
        
        // Register tables if schema file exists
        if ( file_exists( $tablesFile ) ) {
            // Register each table with the updater
            // All tables are defined in the same file, but each needs to be registered separately
            // The order matters for foreign key constraints
            $updater->addExtensionTable( 'labki_content_repo', $tablesFile );
            $updater->addExtensionTable( 'labki_content_ref', $tablesFile );
            $updater->addExtensionTable( 'labki_pack', $tablesFile );
            $updater->addExtensionTable( 'labki_page', $tablesFile );
            $updater->addExtensionTable( 'labki_pack_dependency', $tablesFile );
            $updater->addExtensionTable( 'labki_operations', $tablesFile );
        }
        
        // Schedule post-update maintenance task to initialize content repositories
        // This runs after all schema updates are complete
        $updater->addPostDatabaseUpdateMaintenance( InitializeContentRepos::class );
    }
}


