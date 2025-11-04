<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\Title\Title;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\MediaWikiServices;

/**
 * WikiPageLookup
 *
 * Service for querying MediaWiki page existence and metadata.
 * Provides a clean abstraction over MediaWiki's Title and WikiPage APIs.
 *
 * Usage:
 *   $lookup = new WikiPageLookup();
 *   if ($lookup->exists('Main Page')) {
 *       $info = $lookup->getInfo('Main Page');
 *       // ['exists' => true, 'namespace' => 0, 'id' => 1, 'latestRevId' => 123, ...]
 *   }
 */
final class WikiPageLookup {
    private WikiPageFactory $wikiPageFactory;

    /**
     * @param WikiPageFactory|null $wikiPageFactory Optional factory for testing
     */
    public function __construct(?WikiPageFactory $wikiPageFactory = null) {
        $this->wikiPageFactory = $wikiPageFactory
            ?? MediaWikiServices::getInstance()->getWikiPageFactory();
    }

    /**
     * Check if a wiki page exists.
     *
     * @param string $titleText Page title (e.g., "Main Page" or "Help:Contents")
     * @return bool True if the page exists, false otherwise
     */
    public function exists(string $titleText): bool {
        $title = Title::newFromText($titleText);
        return $title !== null && $title->exists();
    }

    /**
     * Get detailed information about a wiki page.
     *
     * Returns metadata including existence, namespace, page ID, and latest revision ID.
     * Returns null if the title is invalid (e.g., contains illegal characters).
     *
     * @param string $titleText Page title (e.g., "Main Page" or "Help:Contents")
     * @return array|null Page metadata or null if title is invalid
     *
     * Return structure:
     * [
     *   'exists' => bool,           // Whether the page exists
     *   'namespace' => int,          // Namespace ID (0 = main, 1 = talk, etc.)
     *   'namespaceText' => string,   // Human-readable namespace name
     *   'dbKey' => string,           // Database key (normalized title)
     *   'prefixedText' => string,    // Full title with namespace prefix
     *   'id' => int|null,            // Page ID (null if page doesn't exist)
     *   'latestRevId' => int|null,   // Latest revision ID (null if page doesn't exist)
     * ]
     */
    public function getInfo(string $titleText): ?array {
        $title = Title::newFromText($titleText);
        if (!$title) {
            return null;
        }

        $exists = $title->exists();
        $page = null;

        // Only create WikiPage if the page exists (optimization)
        if ($exists) {
            $page = $this->wikiPageFactory->newFromTitle($title);
        }

        return [
            'exists' => $exists,
            'namespace' => $title->getNamespace(),
            'namespaceText' => $title->getNsText(),
            'dbKey' => $title->getDBkey(),
            'prefixedText' => $title->getPrefixedText(),
            'id' => $exists && $page ? $page->getId() : null,
            'latestRevId' => $exists && $page ? $page->getLatest() : null,
        ];
    }

    /**
     * Check if multiple pages exist in a single batch operation.
     *
     * More efficient than calling exists() multiple times when checking many pages.
     *
     * @param array<string> $titleTexts Array of page titles to check
     * @return array<string,bool> Map of title => exists (invalid titles are omitted)
     */
    public function batchExists(array $titleTexts): array {
        $results = [];

        foreach ($titleTexts as $titleText) {
            if (!is_string($titleText) || trim($titleText) === '') {
                continue;
            }

            $title = Title::newFromText($titleText);
            if ($title) {
                $results[$titleText] = $title->exists();
            }
        }

        return $results;
    }

    /**
     * Get the Title object for a given title text.
     *
     * Useful when you need direct access to MediaWiki's Title API.
     *
     * @param string $titleText Page title
     * @return Title|null Title object or null if invalid
     */
    public function getTitle(string $titleText): ?Title {
        return Title::newFromText($titleText);
    }
}
