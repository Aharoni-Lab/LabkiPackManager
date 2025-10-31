<?php

declare(strict_types=1);

namespace LabkiPackManager\Special;

use SpecialPage;
use ExtensionRegistry;

/**
 * Special page for the Labki Pack Manager UI.
 * 
 * Provides an interactive interface for managing content packs, including:
 * - Repository and reference selection
 * - Dependency visualization via Mermaid
 * - Hierarchical pack browsing
 * - Pack selection and customization
 * - State management and application
 */
final class SpecialLabkiPacksManager extends SpecialPage {
    
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct('LabkiPacksManager');
    }

    /**
     * Get the special page group name.
     *
     * @return string
     */
    public function getGroupName(): string {
        return 'labki';
    }

    /**
     * Get the required user right to access this page.
     *
     * @return string
     */
    public function getRestriction(): string {
        return 'labkipackmanager-manage';
    }

    /**
     * Execute the special page.
     *
     * @param string|null $subPage Sub-page parameter (unused)
     */
    public function execute($subPage): void {
        // Check permissions
        $this->checkPermissions();

        $output = $this->getOutput();
        
        // Set page title
        $output->setPageTitle($this->msg('labkipackmanager-special-title')->text());

        // Add required ResourceLoader modules
        $modules = ['ext.LabkiPackManager.bundle'];
        
        // Add Mermaid extension module if available
        if (ExtensionRegistry::getInstance()->isLoaded('Mermaid')) {
            $modules[] = 'ext.mermaid';
        }
        
        $output->addModules($modules);

        // Output the root container for the Vue app
        $output->addHTML('<div id="labki-pack-manager-root"></div>');
    }
}

