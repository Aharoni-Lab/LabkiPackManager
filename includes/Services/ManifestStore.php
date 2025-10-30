<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use WANObjectCache;
use LabkiPackManager\Parser\ManifestParser;
use LabkiPackManager\Services\HierarchyBuilder;
use LabkiPackManager\Services\GraphBuilder;
use LabkiPackManager\Services\ManifestFetcher;

/**
 * ManifestStore
 *
 * Central cache and access layer for parsed manifest data and its derived views.
 *
 * Each ManifestStore instance corresponds to a specific repository URL + ref.
 * It fetches manifest.yml from the local worktree, parses it, constructs
 * hierarchy and graph representations, and caches the result indefinitely.
 *
 * Cached structure schema (v1):
 * [
 *   'meta' => [
 *     'schema_version' => 1,
 *     'hash' => string,
 *     'repo_url' => string,
 *     'ref' => string,
 *     'ref_name' => ?string,
 *     'parsed_at' => int
 *   ],
 *   'manifest' => array,
 *   'derived' => [
 *     'hierarchy' => array,
 *     'graph' => array,
 *     'stats' => [
 *        'pack_count' => int,
 *        'page_count' => int
 *     ]
 *   ]
 * ]
 */
class ManifestStore {

	private const STORE_SCHEMA_VERSION = 1;

	private string $repoUrl;
	private string $ref;
	private string $cacheKey;
	private $cache;
	private ManifestFetcher $fetcher;

	public function __construct(
		string $repoUrl,
		string $ref,
		?WANObjectCache $wanObjectCache = null,
		?ManifestFetcher $fetcher = null
	) {
		$this->repoUrl = $repoUrl;
		$this->ref = $ref;
		$this->cacheKey = 'labki:manifest:' . sha1($repoUrl . ':' . $ref);
		$this->cache = $wanObjectCache ?? $this->resolveCache();
		$this->fetcher = $fetcher ?? new ManifestFetcher();
	}

	/**
	 * Retrieve or rebuild the structured manifest record.
	 *
	 * @param bool $refresh If true, bypass cache and rebuild manifest
	 * @return Status Structured manifest data or fatal error
	 */
	public function get(bool $refresh = false): Status {
        
		if (!$refresh) {
			$cached = $this->cache->get($this->cacheKey);
			if (is_array($cached) && isset($cached['meta']['schema_version'])) {
				return Status::newGood($cached + ['from_cache' => true]);
			}
		}

		// --- Fetch manifest.yml from local worktree ---
		$fetched = $this->fetcher->fetch($this->repoUrl, $this->ref);
		if (!$fetched->isOK()) {
			return $fetched;
		}

		$manifestYaml = $fetched->getValue();
		$hash = sha1($manifestYaml);

		// --- Parse and build ---
		try {
			$parser = new ManifestParser();
			$manifest = $parser->parse($manifestYaml);
		} catch (\Throwable $e) {
			wfDebugLog('labkipack', "ManifestStore: parse failed for {$this->repoUrl}@{$this->ref}: " . $e->getMessage());
			return Status::newFatal('labkipackmanager-error-parse');
		}

		$packs = $manifest['packs'] ?? [];
		$pages = $manifest['pages'] ?? [];

		$hierarchy = (new HierarchyBuilder())->build($manifest);
		$graph = (new GraphBuilder())->build($packs);

		$data = [
			'meta' => [
				'schema_version' => self::STORE_SCHEMA_VERSION,
				'hash' => $hash,
				'repo_url' => $this->repoUrl,
				'ref' => $this->ref,
				'ref_name' => $manifest['name'] ?? null,
				'parsed_at' => wfTimestampNow()
			],
			'manifest' => $manifest,
			'derived' => [
				'hierarchy' => $hierarchy,
				'graph' => $graph,
				'stats' => [
					'pack_count' => count($packs),
					'page_count' => count($pages)
				]
			]
		];

		$this->cache->set($this->cacheKey, $data, WANObjectCache::TTL_INDEFINITE);
		wfDebugLog('labkipack', "ManifestStore: cached new manifest for {$this->repoUrl}@{$this->ref} (hash={$hash})");

		return Status::newGood($data + ['from_cache' => false]);
	}

	/**
	 * Get manifest data + meta.
	 *
	 * @param bool $refresh If true, rebuild before returning
	 * @return Status Manifest data or fatal error
	 */
	public function getManifest(bool $refresh = false): Status {
		$status = $this->get($refresh);
		if (!$status->isOK()) {
			return $status;
		}
		$data = $status->getValue();
		return Status::newGood([
			'meta' => $data['meta'],
			'manifest' => $data['manifest'],
			'from_cache' => $data['from_cache']
		]);
	}

	/**
	 * Get hierarchy data + meta.
	 *
	 * @param bool $refresh If true, rebuild before returning
	 * @return Status Hierarchy data or fatal error
	 */
	public function getHierarchy(bool $refresh = false): Status {
		$status = $this->get($refresh);
		if (!$status->isOK()) {
			return $status;
		}
		$data = $status->getValue();
		return Status::newGood([
			'meta' => $data['meta'],
			'hierarchy' => $data['derived']['hierarchy'],
			'from_cache' => $data['from_cache']
		]);
	}

	/**
	 * Get graph data + meta.
	 *
	 * @param bool $refresh If true, rebuild before returning
	 * @return Status Graph data or fatal error
	 */
	public function getGraph(bool $refresh = false): Status {
		$status = $this->get($refresh);
		if (!$status->isOK()) {
			return $status;
		}
		$data = $status->getValue();
		return Status::newGood([
			'meta' => $data['meta'],
			'graph' => $data['derived']['graph'],
			'from_cache' => $data['from_cache']
		]);
	}

	/**
	 * Clear cached manifest.
	 */
	public function clear(): void {
		$this->cache->delete($this->cacheKey);
	}

	/**
	 * Resolve a WANObjectCache or use lightweight fallback.
	 */
	private function resolveCache() {
		try {
			return MediaWikiServices::getInstance()->getMainWANObjectCache();
		} catch (\Throwable $e) {
			return new class() {
				private array $store = [];
				public function get(string $key) { return $this->store[$key] ?? null; }
				public function set(string $key, $value, int $ttl): void { $this->store[$key] = $value; }
				public function delete(string $key): void { unset($this->store[$key]); }
			};
		}
	}
}
