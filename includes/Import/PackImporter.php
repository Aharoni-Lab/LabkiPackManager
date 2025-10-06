<?php

declare(strict_types=1);

namespace LabkiPackManager\Import;

/**
 * Placeholder class for future implementation of pack importing.
 *
 * This service will eventually handle:
 *  - Importing all pages from a pack into MediaWiki
 *  - Creating or updating pages as needed
 *  - Writing Labki provenance data into page_props
 *  - Updating Labki pack, page, and mapping registry tables
 *  - Computing and storing content hashes for version tracking
 *
 * Implementation planned for a later development phase.
 */
final class PackImporter {

    public function __construct() {}

    /**
     * Placeholder for full pack import logic.
     *
     * @param string $packId
     *   The logical identifier of the pack (from the manifest).
     * @param string|null $packVersion
     *   Version string for the pack.
     * @param array<int,array<string,mixed>> $pages
     *   List of pages belonging to this pack.
     *   Expected shape (future):
     *   [
     *     [
     *       'title' => 'PageName',
     *       'namespace' => 0,
     *       'text' => 'Page content...',
     *       'page_key' => 'original/manifest/key'
     *     ],
     *     ...
     *   ]
     * @param array<string,mixed> $source
     *   Optional metadata about the source repository and commit.
     *   [
     *     'source_repo' => 'https://github.com/Aharoni-Lab/labki-packs',
     *     'source_ref' => 'main',
     *     'source_commit' => 'abc123...'
     *   ]
     *
     * @return array{created:int, updated:int}
     *   Summary statistics for imported pages.
     */
    public function importPack(
        string $packId,
        ?string $packVersion,
        array $pages,
        array $source = []
    ): array {
        // TODO: Implement full pack import workflow.
        // Steps will include:
        //  1. Normalize page text and compute content hashes
        //  2. Check for existing pages and revision differences
        //  3. Create or update pages as needed
        //  4. Write labki.* props into page_props
        //  5. Update labki_pack, labki_page, and labki_page_mapping tables
        //  6. Log actions and return summary statistics
        return [
            'created' => 0,
            'updated' => 0,
        ];
    }
}
