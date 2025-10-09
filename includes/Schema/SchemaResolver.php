<?php

declare(strict_types=1);

namespace LabkiPackManager\Schema;

/**
 * SchemaResolver
 *
 * Placeholder service for resolving and fetching manifest schema definitions.
 *
 * This class will eventually handle:
 *   - Mapping a schema version (e.g. "1.0.0") to its JSON schema URL.
 *   - Fetching and caching schema definitions from labki-packs-tools or other registries.
 *   - Providing schema content for runtime or CI validation.
 *
 * For now, this is a no-op stub retained for forward compatibility.
 */
final class SchemaResolver {

    public function __construct() {
        // Reserved for future configuration (e.g., schema index URL or cache)
    }

    /**
     * Resolve the URL or identifier of a schema version.
     *
     * @param string $schemaVersion Manifest schema version identifier (e.g. "1.0.0").
     * @return string|null URL or file path for the schema, or null if unsupported.
     */
    public function resolveManifestSchemaUrl(string $schemaVersion): ?string {
        // TODO: Implement when multiple manifest schema versions are supported.
        // This will likely map schema versions to URLs in labki-packs-tools/schema/index.json.
        return null;
    }

    /**
     * Fetch and return parsed schema JSON for a given URL or schema version.
     *
     * @param string $schemaUrlOrVersion Either a schema URL or version tag.
     * @return array|null Parsed JSON schema as an associative array, or null if not found.
     */
    public function fetchSchemaJson(string $schemaUrlOrVersion): ?array {
        // TODO: In the future, fetch and cache schema JSON using HttpRequestFactory.
        return null;
    }
}
