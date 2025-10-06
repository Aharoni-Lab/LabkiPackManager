<?php

declare(strict_types=1);

namespace LabkiPackManager\Special;

use SpecialPage;
use ExtensionRegistry;

/**
 * Special page entry point for the LabkiPackManager UI.
 * Renders a placeholder div and loads the front-end application module.
 */
final class SpecialLabkiPackManager extends SpecialPage {
    public function __construct() {
        parent::__construct('LabkiPackManager');
    }

    public function getGroupName(): string {
        return 'labki';
    }

    public function getRestriction(): string {
        return 'labkipackmanager-manage';
    }

    public function execute($subPage): void {
        $this->checkPermissions();

        $output = $this->getOutput();
        $output->setPageTitle($this->msg('labkipackmanager-special-title')->text());

        $modules = ['ext.LabkiPackManager.styles', 'ext.LabkiPackManager.app'];
        if (ExtensionRegistry::getInstance()->isLoaded('Mermaid')) {
            $modules[] = 'ext.mermaid';
        }

        $output->addModules($modules);
        $output->addHTML('<div id="labki-pack-manager-root"></div>');
    }
}
