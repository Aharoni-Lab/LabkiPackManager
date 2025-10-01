<?php

declare(strict_types=1);

namespace LabkiPackManager\Hooks;

use DatabaseUpdater;

final class SchemaHooks {
    /**
     * @param DatabaseUpdater $updater
     */
    public static function onLoadExtensionSchemaUpdates( $updater ): void {
        $dir = dirname( dirname( __DIR__ ) ) . '/sql';
        $dbType = $updater->getDB()->getType();
        $suffix = $dbType === 'sqlite' ? 'sqlite' : ( $dbType === 'mysql' ? 'mysql' : $dbType );
        $tablesFile = $dir . '/' . $suffix . '/tables.sql';
        // Fallback to SQLite syntax when db-specific file missing (dev convenience)
        if ( !file_exists( $tablesFile ) ) {
            $tablesFile = $dir . '/sqlite/tables.sql';
        }
        if ( file_exists( $tablesFile ) ) {
            $updater->addExtensionTable( 'labki_pack_registry', $tablesFile );
            $updater->addExtensionTable( 'labki_pack_pages', $tablesFile );
        }
    }
}


