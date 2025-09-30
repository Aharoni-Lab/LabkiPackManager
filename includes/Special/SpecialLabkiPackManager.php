<?php

namespace LabkiPackManager\Special;

use SpecialPage;

class SpecialLabkiPackManager extends SpecialPage {
    public function __construct() {
        parent::__construct( 'LabkiPackManager' );
    }

    public function getGroupName() {
        return 'labki';
    }

    public function getRestriction() {
        return 'labkipackmanager-manage';
    }

    public function execute( $subPage ) {
        $this->checkPermissions();

        $output = $this->getOutput();
        $output->setPageTitle( $this->msg( 'labkipackmanager-special-title' )->text() );
        $output->addModules( [ 'ext.LabkiPackManager.styles', 'ext.LabkiPackManager.app' ] );
        $output->addHTML( '<div id="labki-pack-manager-root"></div>' );
	}
}


