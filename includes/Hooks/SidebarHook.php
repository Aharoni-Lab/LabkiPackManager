<?php

declare(strict_types=1);

namespace LabkiPackManager\Hooks;

use MediaWiki\Skin\SkinComponentUtils;
use Skin;

/**
 * SidebarHook
 *
 * Hook handler for adding LabkiPackManager navigation items to the MediaWiki sidebar.
 *
 * This hook adds a "Pack Manager" link under the "Labki" section in the sidebar,
 * providing quick access to the LabkiPackManager special page from any wiki page.
 */
final class SidebarHook {

    /**
     * Handler for the SkinBuildSidebar hook.
     *
     * Adds a "Pack Manager" navigation item to the sidebar under the "Labki" section.
     * If the "Labki" section already exists (e.g., from other extensions), the item
     * is appended to it. Otherwise, a new "Labki" section is created.
     *
     * The sidebar item includes:
     * - text: Display text ("Pack Manager")
     * - href: URL to Special:LabkiPackManager
     * - id: HTML ID for styling/targeting (n-labki-pack-manager)
     * - active: Whether the link is currently active (always false)
     *
     * @param Skin $skin The skin being used to render the page
     * @param array &$bar The sidebar structure (passed by reference)
     * @return bool Always returns true to continue hook processing
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinBuildSidebar
     */
    public static function onSkinBuildSidebar( Skin $skin, array &$bar ): bool {
        // Add Pack Manager link to the Labki section
        // This will create the section if it doesn't exist, or append if it does
        $bar['Labki'][] = [
            'text' => 'Pack Manager',
            'href' => SkinComponentUtils::makeSpecialUrl( 'LabkiPacksManager' ),
            'id' => 'n-labki-pack-manager',
            'active' => false,
        ];

        return true;
    }
}
