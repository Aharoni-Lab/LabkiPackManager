<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\Title\Title;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\MediaWikiServices;

final class WikiPageLookup {
    private WikiPageFactory $wikiPageFactory;

    public function __construct( ?WikiPageFactory $wikiPageFactory = null ) {
        $this->wikiPageFactory = $wikiPageFactory
            ?? MediaWikiServices::getInstance()->getWikiPageFactory();
    }

    public function exists( string $titleText ): bool {
        $title = Title::newFromText( $titleText );
        return $title && $title->exists();
    }

    public function getInfo( string $titleText ): ?array {
        $title = Title::newFromText( $titleText );
        if ( !$title ) {
            return null;
        }

        $page = $this->wikiPageFactory->newFromTitle( $title );
        $exists = $title->exists();

        return [
            'exists' => $exists,
            'namespace' => $title->getNamespace(),
            'id' => $exists ? $page->getId() : null,
            'latestRevId' => $exists ? $page->getLatest() : null,
        ];
    }
}
