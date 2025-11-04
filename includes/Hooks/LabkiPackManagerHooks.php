<?php

declare(strict_types=1);

namespace LabkiPackManager\Hooks;

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\GitContentManager;
use LabkiPackManager\Services\ManifestStore;

use Exception;

class LabkiPackManagerHooks {

    /**
     * Inject LabkiContentSources into mw.config for JS.
     *
     * (Legacy: planned for removal once frontend uses API.)
     */
    public static function onBeforePageDisplay( $out, $skin ): void {
        global $wgLabkiContentSources;

        if ( isset( $wgLabkiContentSources ) && is_array( $wgLabkiContentSources ) ) {
            $out->addJsConfigVars( 'LabkiContentSources', $wgLabkiContentSources );
        }
    }
}
