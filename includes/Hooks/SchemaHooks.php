<?php

declare(strict_types=1);

namespace LabkiPackManager\Hooks;

use DatabaseUpdater;
use LabkiPackManager\Maintenance\InitializeContentRepos;

final class SchemaHooks {
    /**
     * @param DatabaseUpdater $updater
     */
    public static function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater ): void {
        $dir = dirname( dirname( __DIR__ ) ) . '/sql';
        $dbType = $updater->getDB()->getType();
        // Currently don't support other databases, so we'll use SQLite syntax for now.
        // TODO: Add support for other databases.
        $suffix = 'sqlite';
        $tablesFile = $dir . '/' . $suffix . '/tables.sql';
        if ( file_exists( $tablesFile ) ) {
            $updater->addExtensionTable( 'labki_content_repo', $tablesFile );
            $updater->addExtensionTable( 'labki_content_ref', $tablesFile );    
            $updater->addExtensionTable( 'labki_pack', $tablesFile );
            $updater->addExtensionTable( 'labki_page', $tablesFile );
        }
        
        $updater->addPostDatabaseUpdateMaintenance( InitializeContentRepos::class );
    }
}


