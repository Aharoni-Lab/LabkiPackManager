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
 */
final class SchemaHooks {

    /**
     * Handler for the LoadExtensionSchemaUpdates hook.
     *
     * Registers all LabkiPackManager database tables and schedules post-update
     * maintenance tasks. This hook is called during MediaWiki installation and
     * when running update.php.
     *
     * Current implementation uses SQLite syntax for all database types.
     * TODO: Add support for MySQL/PostgreSQL-specific schemas.
     *
     * Tables registered:
     * - labki_content_repo: Stores Git repository URLs and metadata
     * - labki_content_ref: Stores Git refs (branches/tags) for each repository
     * - labki_pack: Stores content pack information from manifests
     * - labki_page: Stores individual page information within packs
     *
     * Post-update tasks:
     * - InitializeContentRepos: Clones repositories and sets up worktrees
     *
     * @param DatabaseUpdater $updater The database updater instance
     * @return void
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
     */
    public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ): void {
        // Determine SQL directory path
        $dir = dirname( dirname( __DIR__ ) ) . '/sql';
        
        // Get database type (mysql, postgres, sqlite, etc.)
        $dbType = $updater->getDB()->getType();
        
        // Currently using SQLite syntax for all database types
        // TODO: Add support for MySQL/PostgreSQL with proper schema files
        $suffix = 'sqlite';
        
        // Construct path to tables.sql file
        $tablesFile = $dir . '/' . $suffix . '/tables.sql';
        
        // Register tables if schema file exists
        if ( file_exists( $tablesFile ) ) {
            // Register each table with the updater
            // All tables are defined in the same file, but each needs to be registered separately
            $updater->addExtensionTable( 'labki_content_repo', $tablesFile );
            $updater->addExtensionTable( 'labki_content_ref', $tablesFile );
            $updater->addExtensionTable( 'labki_pack', $tablesFile );
            $updater->addExtensionTable( 'labki_page', $tablesFile );
        }
        
        // Schedule post-update maintenance task to initialize content repositories
        // This runs after all schema updates are complete
        $updater->addPostDatabaseUpdateMaintenance( InitializeContentRepos::class );
    }
}


