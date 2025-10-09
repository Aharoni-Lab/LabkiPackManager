<?php
namespace LabkiPackManager\Hooks;

class LabkiPackManagerHooks {
    /**
     * Inject LabkiContentSources into mw.config for JS.
     */
    public static function onBeforePageDisplay( $out, $skin ): void {
        global $wgLabkiContentSources;
        if ( isset( $wgLabkiContentSources ) && is_array( $wgLabkiContentSources ) ) {
            $out->addJsConfigVars( 'LabkiContentSources', $wgLabkiContentSources );
        }
    }
}
