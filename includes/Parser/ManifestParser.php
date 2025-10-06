<?php

declare(strict_types=1);

namespace LabkiPackManager\Parser;

use Symfony\Component\Yaml\Yaml;
use InvalidArgumentException;

/**
 * ManifestParser
 *
 * Parses a Labki manifest YAML into a normalized structure.
 *
 * Input YAML format:
 *  schema_version: "1.0.0"
 *  packs:
 *    my-pack:
 *      version: "0.3.1"
 *      description: "Example pack"
 *      pages: [ "MainPage", "SubPage" ]
 *      depends_on: [ "base-pack" ]
 *      tags: [ "core", "example" ]
 *
 * Output structure:
 * [
 *   'schema_version' => '1.0.0',
 *   'packs' => [
 *      [
 *         'id'          => 'my-pack',
 *         'version'     => '0.3.1',
 *         'description' => 'Example pack',
 *         'pages'       => [ 'MainPage', 'SubPage' ],
 *         'page_count'  => 2,
 *         'depends_on'  => [ 'base-pack' ],
 *         'tags'        => [ 'core', 'example' ],
 *      ]
 *   ]
 * ]
 */
final class ManifestParser {

    public function parse(string $yaml): array {
        $data = $this->parseYaml($yaml);
        $schemaVersion = isset($data['schema_version']) ? (string)$data['schema_version'] : null;

        if (!isset($data['packs']) || !is_array($data['packs'])) {
            throw new InvalidArgumentException('Invalid schema: missing "packs"');
        }

        $packs = [];
        foreach ($data['packs'] as $id => $meta) {
            if (!is_string($id) || !is_array($meta)) {
                continue;
            }

            $packs[] = [
                'id'          => $id,
                'version'     => (string)($meta['version'] ?? ''),
                'description' => (string)($meta['description'] ?? ''),
                'pages'       => $this->normalizeStringArray($meta['pages'] ?? []),
                'page_count'  => isset($meta['pages']) && is_array($meta['pages']) ? count($meta['pages']) : 0,
                'depends_on'  => $this->normalizeStringArray($meta['depends_on'] ?? []),
                'tags'        => $this->normalizeStringArray($meta['tags'] ?? []),
            ];
        }

        return [
            'schema_version' => $schemaVersion,
            'packs' => $packs,
        ];
    }

    /**
     * Parse YAML safely and validate top-level structure.
     */
    private function parseYaml(string $yaml): array {
        $trimmed = trim($yaml);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Empty YAML');
        }

        try {
            $parsed = Yaml::parse($trimmed);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Invalid YAML');
        }

        if (!is_array($parsed)) {
            throw new InvalidArgumentException('Invalid schema: root must be a mapping');
        }

        return $parsed;
    }

    /**
     * Filter and cast a list to an array of strings.
     */
    private function normalizeStringArray(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn($v) => is_string($v) ? trim($v) : '', $value),
            static fn($v) => $v !== ''
        ));
    }
}
