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
        return 'labki-import';
    }

    public function execute( $subPage ) {
        $this->checkPermissions();

        $output = $this->getOutput();
        $output->setPageTitle( $this->msg( 'labkipackmanager-special-title' ) );
        $output->addWikiTextAsInterface( $this->msg( 'labkipackmanager-special-placeholder' )->text() );
    }
}


