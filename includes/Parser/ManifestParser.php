<?php

declare(strict_types=1);

namespace LabkiPackManager\Parser;

use Symfony\Component\Yaml\Yaml;
use InvalidArgumentException;

/**
 * ManifestParser
 *
 * Parses a Labki manifest YAML file into a normalized associative array.
 *
 * Input example:
 *  schema_version: "1.0.0"
 *  last_updated: "2025-09-22T00:00:00Z"
 *  name: "Test Manifest"
 *  description: "A demonstration manifest for Labki content packs."
 *  author: "Aharoni Lab"
 *  packs:
 *    onboarding:
 *      version: "0.3.1"
 *      description: "Example onboarding pack"
 *      pages: [ "MainPage", "SubPage" ]
 *      depends_on: [ "base-pack" ]
 *      tags: [ "core", "example" ]
 *
 * Output structure:
 * [
 *   'schema_version' => '1.0.0',
 *   'last_updated'   => '2025-09-22T00:00:00Z',
 *   'name'           => 'Test Manifest',
 *   'description'    => 'A demonstration manifest...',
 *   'author'         => 'Aharoni Lab',
 *   'packs' => [
 *     [
 *       'id'          => 'onboarding',
 *       'version'     => '0.3.1',
 *       'description' => 'Example onboarding pack',
 *       'pages'       => [ 'MainPage', 'SubPage' ],
 *       'page_count'  => 2,
 *       'depends_on'  => [ 'base-pack' ],
 *       'tags'        => [ 'core', 'example' ]
 *     ]
 *   ]
 * ]
 */
final class ManifestParser
{
    /**
     * Parse YAML into structured manifest array.
     *
     * @param string $yaml Raw YAML text.
     * @return array Normalized manifest structure.
     * @throws InvalidArgumentException if YAML or schema invalid.
     */
    public function parse(string $yaml): array
    {
        $data = $this->parseYaml($yaml);

        // --- Top-level metadata normalization ---
        $schemaVersion = (string)($data['schema_version'] ?? '');
        $lastUpdated   = (string)($data['last_updated'] ?? '');
        $name          = (string)($data['name'] ?? '');
        $description   = (string)($data['description'] ?? '');
        $author        = (string)($data['author'] ?? '');

        // --- Validate packs section ---
        if (!isset($data['packs']) || !is_array($data['packs']) || empty($data['packs'])) {
            throw new InvalidArgumentException('Invalid schema: missing or empty "packs" section.');
        }

        $packs = $this->parsePacks($data['packs']);

        return [
            'schema_version' => $schemaVersion,
            'last_updated'   => $lastUpdated,
            'name'           => $name,
            'description'    => $description,
            'author'         => $author,
            'packs'          => $packs,
        ];
    }

    /**
     * Parse the packs section into normalized array of packs.
     *
     * @param array $packsRaw
     * @return array
     */
    private function parsePacks(array $packsRaw): array
    {
        $packs = [];

        foreach ($packsRaw as $id => $meta) {
            if (!is_string($id) || !is_array($meta)) {
                continue; // skip invalid entries
            }

            $pages = $this->normalizeStringArray($meta['pages'] ?? []);
            $depends = $this->normalizeStringArray($meta['depends_on'] ?? []);
            $tags = $this->normalizeStringArray($meta['tags'] ?? []);

            $packs[] = [
                'id'          => $id,
                'version'     => (string)($meta['version'] ?? ''),
                'description' => (string)($meta['description'] ?? ''),
                'pages'       => $pages,
                'page_count'  => count($pages),
                'depends_on'  => $depends,
                'tags'        => $tags,
            ];
        }

        if (empty($packs)) {
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
    private function parseYaml(string $yaml): array
    {
        $trimmed = trim($yaml);

        // Strip UTF-8 BOM if present – avoids keys being prefixed (e.g., \uFEFFschema_version)
        // this was needed to fix the schema_version key being prefixed with \uFEFF
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
     * Normalize any array-like value to a clean list of strings.
     *
     * @param mixed $value
     * @return array<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
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
