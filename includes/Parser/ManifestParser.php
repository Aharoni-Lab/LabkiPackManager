<?php

declare(strict_types=1);

namespace LabkiPackManager\Parser;

use Symfony\Component\Yaml\Yaml;
use InvalidArgumentException;

/**
 * ManifestParser
 *
 * Converts a Labki manifest.yml into a consistent associative array used by
 * the ManifestStore, HierarchyBuilder, and GraphBuilder.
 *
 * The parser enforces a predictable structure:
 *
 * Output schema:
 * [
 *   'schema_version' => string,
 *   'last_updated'   => string,
 *   'name'           => string,
 *   'description'    => string,
 *   'author'         => string,
 *   'pages' => [
 *      'PageName' => [
 *          'name'         => 'PageName',
 *          'file'         => 'pages/PageName.wiki',
 *          'last_updated' => '2025-09-22T00:00:00Z'
 *      ],
 *      ...
 *   ],
 *   'packs' => [
 *      'pack_id' => [
 *          'id'          => 'pack_id',
 *          'version'     => '1.0.0',
 *          'description' => 'Description...',
 *          'pages'       => ['Page1', 'Page2'],
 *          'depends_on'  => ['other_pack'],
 *          'tags'        => ['example', 'core'],
 *          'page_count'  => 2
 *      ],
 *      ...
 *   ]
 * ]
 */
final class ManifestParser {

    /**
     * Parse YAML text into a structured manifest array.
     *
     * @param string $yaml Raw YAML text from manifest.yml
     * @return array Normalized manifest data
     * @throws InvalidArgumentException if YAML or schema is invalid
     */
    public function parse(string $yaml): array {
        $data = $this->parseYaml($yaml);

        // --- Normalize top-level metadata ---
        $schemaVersion = (string)($data['schema_version'] ?? '');
        $lastUpdated   = (string)($data['last_updated'] ?? '');
        $name          = (string)($data['name'] ?? '');
        $description   = (string)($data['description'] ?? '');
        $author        = (string)($data['author'] ?? '');

        // --- Normalize page entries ---
        $pages = $this->parsePages($data['pages'] ?? []);

        // --- Validate and normalize packs ---
        if (!isset($data['packs']) || !is_array($data['packs']) || $data['packs'] === []) {
            throw new InvalidArgumentException('Invalid manifest: missing or empty "packs" section.');
        }

        $packs = $this->parsePacks($data['packs']);

        return [
            'schema_version' => $schemaVersion,
            'last_updated'   => $lastUpdated,
            'name'           => $name,
            'description'    => $description,
            'author'         => $author,
            'pages'          => $pages,
            'packs'          => $packs,
        ];
    }

    /**
     * Parse and normalize the "pages" section.
     *
     * @param array $pagesRaw Raw page definitions
     * @return array<string,array<string,string>>
     */
    private function parsePages(array $pagesRaw): array {
        $pages = [];

        foreach ($pagesRaw as $pageId => $meta) {
            if (!is_string($pageId) || !is_array($meta)) {
                continue; // skip invalid entries
            }

            $file = (string)($meta['file'] ?? '');
            $lastUpdated = (string)($meta['last_updated'] ?? '');

            if ($file === '') {
                continue;
            }

            $pages[$pageId] = [
                'name' => $pageId,
                'file' => $file,
                'last_updated' => $lastUpdated,
            ];
        }

        return $pages;
    }

    /**
     * Parse and normalize the "packs" section.
     *
     * Always returns an associative array keyed by pack ID.
     *
     * @param array $packsRaw
     * @return array<string,array<string,mixed>>
     */
    private function parsePacks(array $packsRaw): array {
        $packs = [];

        foreach ($packsRaw as $id => $meta) {
            // Handle both associative and numeric-style pack entries
            if (!is_string($id)) {
                $id = (string)($meta['id'] ?? '');
            }

            if ($id === '' || !is_array($meta)) {
                continue;
            }

            $pages = $this->normalizeStringArray($meta['pages'] ?? []);
            $depends = $this->normalizeStringArray($meta['depends_on'] ?? []);
            $tags = $this->normalizeStringArray($meta['tags'] ?? []);

            $packs[$id] = [
                'id'          => $id,
                'version'     => (string)($meta['version'] ?? ''),
                'description' => (string)($meta['description'] ?? ''),
                'pages'       => $pages,
                'page_count'  => count($pages),
                'depends_on'  => $depends,
                'tags'        => $tags
            ];
        }

        if ($packs === []) {
            throw new InvalidArgumentException('Invalid manifest: "packs" section contained no valid entries.');
        }

        return $packs;
    }

    /**
     * Parse YAML safely and validate top-level structure.
     *
     * @param string $yaml
     * @return array
     * @throws InvalidArgumentException
     */
    private function parseYaml(string $yaml): array {
        $trimmed = trim($yaml);

        // Strip UTF-8 BOM if present (common in Windows-saved YAML files)
        if (strncmp($trimmed, "\xEF\xBB\xBF", 3) === 0) {
            $trimmed = substr($trimmed, 3);
        }

        if ($trimmed === '') {
            throw new InvalidArgumentException('Empty YAML manifest.');
        }

        try {
            $parsed = Yaml::parse($trimmed);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Invalid YAML: ' . $e->getMessage());
        }

        if (!is_array($parsed)) {
            throw new InvalidArgumentException('Invalid manifest root: expected mapping.');
        }

        return $parsed;
    }

    /**
     * Normalize any array-like value to a list of non-empty trimmed strings.
     *
     * @param mixed $value
     * @return array<string>
     */
    private function normalizeStringArray(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn($v) => is_string($v) ? trim($v) : '',
                $value
            ),
            static fn($v) => $v !== ''
        ));
    }
}
