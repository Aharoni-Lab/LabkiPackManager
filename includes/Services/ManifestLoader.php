<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\Status\Status;

/**
 * ManifestLoader
 *
 * High-level orchestrator for retrieving and caching manifests
 * from Labki content repositories.
 *
 * Responsibilities:
 *  - Check cache via ManifestStore
 *  - Fetch fresh data via ManifestFetcher when missing or refresh=true
 *  - Cache successful responses
 *  - Return structured StatusValue with packs + metadata
 *
 * This class will later handle schema migrations when multiple
 * manifest schema versions are supported.
 */
final class ManifestLoader {

    private ManifestFetcher $fetcher;
    private ?ManifestStore $store;

    public function __construct(?ManifestFetcher $fetcher = null, ?ManifestStore $store = null) {
        $this->fetcher = $fetcher ?? new ManifestFetcher();
        $this->store   = $store;
    }

    /**
     * Load a manifest, using cache if available.
     *
     * @param string $manifestUrl
     * @param bool $refresh Whether to bypass cache and fetch fresh data.
     * @return StatusValue
     *     ->getValue(): [
     *        'packs' => array,
     *        'schema_version' => string|null,
     *        'manifest_url' => string,
     *        'fetched_at' => int
     *     ]
     */
    public function load(string $manifestUrl, bool $refresh = false): Status {
        $store = $this->store ?? new ManifestStore($manifestUrl);
        $cached = !$refresh ? $store->getManifestOrNull() : null;

        // Case 1: Use cached manifest if available
        if ($cached !== null) {
            return Status::newGood([
                'packs' => $cached['packs'] ?? [],
                'schema_version' => $cached['schemaVersion'] ?? null,
                'manifest_url' => $cached['manifestUrl'] ?? $manifestUrl,
                'fetched_at' => $cached['fetchedAt'] ?? null,
                'from_cache' => true,
            ]);
        }

        // Case 2: Fetch from remote or local source
        $status = $this->fetcher->fetch($manifestUrl);
        if (!$status->isOK()) {
            return $status;
        }

        $val = $status->getValue();
        $packs = $val['packs'] ?? [];
        $schema = $val['schema_version'] ?? null;

        // Store new result in cache
        $store->saveManifest($packs, [
            'schemaVersion' => $schema,
            'manifestUrl' => $manifestUrl,
            'fetchedAt' => time(),
        ]);

        return Status::newGood([
            'packs' => $packs,
            'schema_version' => $schema,
            'manifest_url' => $manifestUrl,
            'fetched_at' => time(),
            'from_cache' => false,
        ]);
    }
}
