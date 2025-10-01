<?php

namespace LabkiPackManager\Special;

use SpecialPage;
use ExtensionRegistry;

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
		$modules = [ 'ext.LabkiPackManager.styles', 'ext.LabkiPackManager.app' ];
		if ( class_exists( '\ExtensionRegistry' ) && ExtensionRegistry::getInstance()->isLoaded( 'Mermaid' ) ) {
			$modules[] = 'ext.mermaid';
		}
		$output->addModules( $modules );
        $output->addHTML( '<div id="labki-pack-manager-root"></div>' );
	}
}


