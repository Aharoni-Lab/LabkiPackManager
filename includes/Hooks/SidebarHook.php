<?php
namespace LabkiPackManager\Hooks;

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
            'href' => '/wiki/Special:Labki',
            'id' => 'n-labki-pack-manager',
            'active' => false,
        ];

        // Add more links if needed
        $bar['Labki'][] = [
            'text' => 'Docs',
            'href' => '/wiki/Labki:Documentation',
            'id' => 'n-labki-docs',
            'active' => false,
        ];

        return true;
    }
}
