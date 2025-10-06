<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use Title;

final class WikiPageLookup {

    /**
     * Check if a page exists in the current wiki (even if external to Labki).
     *
     * @param string $titleText Full page title (e.g. "Template:Infobox", "Main Page")
     * @return bool True if the page exists in MediaWiki core.
     */
    public function exists( string $titleText ): bool {
        $title = Title::newFromText( $titleText );
        return $title !== null && $title->exists();
    }

    /**
     * Get basic info about a wiki page, or null if it does not exist.
     *
     * @param string $titleText
     * @return array|null { 'exists' => bool, 'namespace' => int, 'id' => int|null, 'latestRevId' => int|null }
     */
    public function getInfo( string $titleText ): ?array {
        $title = Title::newFromText( $titleText );
        if ( !$title ) {
            return null;
        }

        $page = \WikiPage::factory( $title );
        $exists = $title->exists();

        return [
            'exists' => $exists,
            'namespace' => $title->getNamespace(),
            'id' => $exists ? $page->getId() : null,
            'latestRevId' => $exists ? $page->getLatest() : null,
        ];
    }
}
