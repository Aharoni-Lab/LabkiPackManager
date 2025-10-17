<?php
namespace LabkiPackManager\Hooks;

use MediaWiki\Skin\SkinComponentUtils;

class SidebarHook {
    /**
     * @param \Skin $skin
     * @param array &$bar
     * @return bool
     */
    public static function onSkinBuildSidebar( $skin, &$bar ): bool {

        // Add a top-level section
        $bar['Labki'][] = [
            'text' => 'Pack Manager',
            'href' => SkinComponentUtils::makeSpecialUrl( 'LabkiPackManager' ),
            'id' => 'n-labki-pack-manager',
            'active' => false,
        ];
        
        return true;
    }
}
