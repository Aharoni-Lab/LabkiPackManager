<?php

declare(strict_types=1);

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
    require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
    require_once __DIR__ . '/../../../../maintenance/Maintenance.php';
}

/**
 * Placeholder for backfilling Labki registries (repo, pack, page)
 * from existing page_props or historical metadata.
 *
 * This script will eventually allow migration of older installations
 * to the new schema defined in LabkiRepoRegistry, LabkiPackRegistry, and LabkiPageRegistry.
 */
class BackfillLabkiRegistry extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addDescription(
            'Backfill Labki repository, pack, and page registries based on existing page_props.'
        );
    }

    public function execute(): void {
        $this->output("BackfillLabkiRegistry is currently a placeholder.\n");
        $this->output("No actions performed. Future implementation will:\n");
        $this->output("  • Scan page_props for labki.* metadata\n");
        $this->output("  • Reconstruct repo/pack/page associations\n");
        $this->output("  • Populate labki_repo, labki_pack, labki_page tables\n");
        $this->output("  • Skip pages already registered.\n");
        $this->output("\n");
        $this->output("Exiting.\n");
    }
}

$maintClass = BackfillLabkiRegistry::class;
require_once RUN_MAINTENANCE_IF_MAIN;
