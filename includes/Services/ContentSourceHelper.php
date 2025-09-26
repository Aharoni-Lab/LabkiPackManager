<?php

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;

class ContentSourceHelper {
    /**
     * @return array<string,string> Mapping of label => manifest URL
     */
    public static function getSources(): array {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $sources = $config->get( 'LabkiContentSources' );
        return is_array( $sources ) ? $sources : [];
    }

    public static function resolveSelectedRepoLabel( array $sources, ?string $requested ): string {
        if ( $requested !== null && isset( $sources[$requested] ) ) {
            return $requested;
        }
        $first = array_key_first( $sources );
        return $first ?? '';
    }

    public static function getManifestUrlForLabel( array $sources, string $label ): string {
        return (string)( $sources[$label] ?? '' );
    }
}


