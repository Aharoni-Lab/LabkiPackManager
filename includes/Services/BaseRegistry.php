<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

class BaseRegistry
{
    /**
     * Get current timestamp in DB-specific format.
     * Can be called by external code to get properly formatted timestamps.
     * @return string Formatted timestamp for database insertion
     */
    public function now(?IDatabase $dbw = null): string {
        if ($dbw === null){
            $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        }
        return $dbw->timestamp( \wfTimestampNow() );
    }
}