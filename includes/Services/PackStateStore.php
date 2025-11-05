<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use MediaWiki\MediaWikiServices;
use WANObjectCache;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;

/**
 * PackStateStore
 *
 * Manages persistence of pack selection session state using MediaWiki's cache.
 * Each session is scoped to a specific user + ref combination.
 *
 * ## Cache Strategy
 * - Uses WANObjectCache for distributed session storage
 * - Key format: labki:packstate:{user_id}:{ref_id}
 * - TTL: 1800 seconds (30 minutes)
 * - No versioning needed (state includes hash for optimistic locking)
 *
 * ## Data Structure
 * The cached state contains:
 * - Metadata: ref_id, user_id, hash, timestamp
 * - Packs: Flat array of pack states, each containing:
 *   - Action: install|update|remove|unchanged (primary state)
 *   - Auto reason: auto_selected_reason (null if manual, otherwise explains why)
 *   - Versions: current_version, target_version
 *   - Customization: prefix (for page titles)
 *   - Installation status: installed (boolean)
 *   - Pages: Array of page states with name, default_title, final_title, conflicts, installed
 *
 * Example structure:
 * ```php
 * [
 *   'ref_id' => 5,
 *   'user_id' => 123,
 *   'hash' => 'abc123',
 *   'timestamp' => 1730345678,
 *   'packs' => [
 *     'test pack' => [
 *       'action' => 'install',
 *       'auto_selected_reason' => null,
 *       'current_version' => null,
 *       'target_version' => '1.0.0',
 *       'prefix' => 'TestPack',
 *       'installed' => false,
 *       'pages' => [
 *         'test page' => [
 *           'name' => 'test page',
 *           'default_title' => 'TestPack/test page',
 *           'final_title' => 'TestPack/test page',
 *           'has_conflict' => false,
 *           'conflict_type' => null,
 *           'installed' => false
 *         ]
 *       ]
 *     ]
 *   ]
 * ]
 * ```
 *
 * @package LabkiPackManager\Services
 */
final class PackStateStore {

	private const CACHE_TTL = 1800; // 30 minutes
	private const CACHE_PREFIX = 'labki:packstate';

	private WANObjectCache $cache;

	/**
	 * Constructor.
	 *
	 * @param WANObjectCache|null $cache Optional cache instance (for testing)
	 */
	public function __construct( ?WANObjectCache $cache = null ) {
		$this->cache = $cache ?? MediaWikiServices::getInstance()->getMainWANObjectCache();
	}

	/**
	 * Get session state for a user and ref.
	 *
	 * @param int $userId User ID
	 * @param ContentRefId $refId Content ref ID
	 * @return PackSessionState|null State object or null if not found
	 */
	public function get( int $userId, ContentRefId $refId ): ?PackSessionState {
		$key = $this->makeCacheKey( $userId, $refId );
		$cached = $this->cache->get( $key );

		if ( !is_array( $cached ) ) {
			return null;
		}

		try {
			return PackSessionState::fromArray( $cached );
		} catch ( \Throwable $e ) {
			wfDebugLog( 'labkipack', "PackStateStore: Failed to deserialize state: {$e->getMessage()}" );
			return null;
		}
	}

	/**
	 * Save session state.
	 *
	 * @param PackSessionState $state State to save
	 * @return bool Success
	 */
	public function save( PackSessionState $state ): bool {
		$key = $this->makeCacheKey( $state->userId(), $state->refId() );
		$data = $state->toArray();

		wfDebugLog(
			'labkipack',
			"PackStateStore: Saving state for user={$state->userId()}, ref={$state->refId()->toInt()}, hash={$state->hash()}, packs=" . count( $state->packs() )
		);

		return $this->cache->set( $key, $data, self::CACHE_TTL );
	}

	/**
	 * Clear session state for a user and ref.
	 *
	 * @param int $userId User ID
	 * @param ContentRefId $refId Content ref ID
	 * @return bool Success
	 */
	public function clear( int $userId, ContentRefId $refId ): bool {
		$key = $this->makeCacheKey( $userId, $refId );

		wfDebugLog(
			'labkipack',
			"PackStateStore: Clearing state for user={$userId}, ref={$refId->toInt()}"
		);

		return $this->cache->delete( $key );
	}

	/**
	 * Check if state exists for a user and ref.
	 *
	 * @param int $userId User ID
	 * @param ContentRefId $refId Content ref ID
	 * @return bool
	 */
	public function exists( int $userId, ContentRefId $refId ): bool {
		$key = $this->makeCacheKey( $userId, $refId );
		return $this->cache->get( $key ) !== false;
	}

	/**
	 * Generate cache key for user and ref.
	 *
	 * @param int $userId User ID
	 * @param ContentRefId $refId Content ref ID
	 * @return string Cache key
	 */
	private function makeCacheKey( int $userId, ContentRefId $refId ): string {
		return $this->cache->makeKey(
			self::CACHE_PREFIX,
			(string)$userId,
			(string)$refId->toInt()
		);
	}
}
