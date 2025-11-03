<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * PackSessionState
 *
 * Encapsulates the complete session state for pack selection and management.
 * Stores pack-level and page-level data including user customizations.
 *
 * ## State Structure
 * - Metadata: refId, userId, hash, timestamp
 * - Packs: Flat array of pack states with pages nested inside
 *
 * ## Pack State Fields
 * - action: install|update|remove|unchanged (primary state indicator)
 * - auto_selected_reason: Why action was auto-set (null if manually set by user)
 * - current_version: Version currently installed (null if not installed)
 * - target_version: Version from manifest
 * - prefix: Pack prefix for page titles (user-customizable)
 * - installed: Whether this pack is already installed
 * - pages: Array of page states
 *
 * ## Page State Fields
 * - name: Original page name from manifest
 * - default_title: Computed default title (prefix/name)
 * - final_title: User-customized title or default
 * - has_conflict: Whether this title conflicts with existing page
 * - conflict_type: Type of conflict (title_exists, namespace_invalid, etc.)
 * - installed: Whether this page is already installed
 *
 * @package LabkiPackManager\Domain
 */
final class PackSessionState {

	private ContentRefId $refId;
	private int $userId;
	private array $packs;
	private string $hash;
	private int $timestamp;

	/**
	 * Pack-level state fields.
	 * These represent the top-level properties of a pack within the session.
	 */
	public const PACK_FIELDS = [
		'action',                // install|update|remove|unchanged (primary state)
		'auto_selected_reason',  // Reason for auto-action (null if manually set)
		'current_version',       // Version currently installed (null if not installed)
		'target_version',        // Version from manifest
		'prefix',                // Pack prefix for page titles (user-customizable)
		'installed',             // Whether this pack is already installed
	];

	/**
	 * Page-level state fields.
	 * These represent the properties of individual pages within a pack.
	 */
	public const PAGE_FIELDS = [
		'name',            // Original page name from manifest
		'default_title',   // Computed default title (prefix/name)
		'final_title',     // User-customized title or default
		'has_conflict',    // Whether this title conflicts with existing page
		'conflict_type',   // Type of conflict (title_exists, namespace_invalid, etc.)
		'installed',       // Whether this page is already installed
	];

	/**
	 * Constructor.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param int $userId User ID
	 * @param array $packs Pack states (pack_name => pack_state)
	 * @param string|null $hash State hash (generated if null)
	 * @param int|null $timestamp Timestamp (current time if null)
	 */
	public function __construct(
		ContentRefId $refId,
		int $userId,
		array $packs = [],
		?string $hash = null,
		?int $timestamp = null
	) {
		$this->refId = $refId;
		$this->userId = $userId;
		$this->packs = $packs;
		$this->timestamp = $timestamp ?? time();
		$this->hash = $hash ?? $this->computeHash();
	}

	/**
	 * Get content ref ID.
	 *
	 * @return ContentRefId
	 */
	public function refId(): ContentRefId {
		return $this->refId;
	}

	/**
	 * Get user ID.
	 *
	 * @return int
	 */
	public function userId(): int {
		return $this->userId;
	}

	/**
	 * Get all packs.
	 *
	 * @return array Pack states (pack_name => pack_state)
	 */
	public function packs(): array {
		return $this->packs;
	}

	/**
	 * Get a specific pack state.
	 *
	 * @param string $packName Pack name
	 * @return array|null Pack state or null if not found
	 */
	public function getPack( string $packName ): ?array {
		return $this->packs[$packName] ?? null;
	}

	/**
	 * Set pack state.
	 *
	 * @param string $packName Pack name
	 * @param array $packState Pack state data
	 */
	public function setPack( string $packName, array $packState ): void {
		$this->packs[$packName] = $packState;
		$this->updateHash();
	}

	/**
	 * Remove pack from state.
	 *
	 * @param string $packName Pack name
	 */
	public function removePack( string $packName ): void {
		unset( $this->packs[$packName] );
		$this->updateHash();
	}

	/**
	 * Check if pack exists in state.
	 *
	 * @param string $packName Pack name
	 * @return bool
	 */
	public function hasPack( string $packName ): bool {
		return isset( $this->packs[$packName] );
	}

	/**
	 * Get all packs with actions set (install, update, or remove).
	 *
	 * @return array Array of pack names
	 */
	public function getPacksWithActions(): array {
		$packs = [];
		foreach ( $this->packs as $packName => $packState ) {
			$action = $packState['action'] ?? 'unchanged';
			if ( $action !== 'unchanged' ) {
				$packs[] = $packName;
			}
		}
		return $packs;
	}

	/**
	 * Get packs with install or update actions.
	 *
	 * @return array Array of pack names
	 */
	public function getPacksForInstallOrUpdate(): array {
		$packs = [];
		foreach ( $this->packs as $packName => $packState ) {
			$action = $packState['action'] ?? 'unchanged';
			if ( $action === 'install' || $action === 'update' ) {
				$packs[] = $packName;
			}
		}
		return $packs;
	}

	/**
	 * Get manually actioned pack names (excluding auto-actioned).
	 *
	 * @return array Array of pack names
	 */
	public function getManuallyActionedPackNames(): array {
		$packs = [];
		foreach ( $this->packs as $packName => $packState ) {
			$action = $packState['action'] ?? 'unchanged';
			$autoReason = $packState['auto_selected_reason'] ?? null;
			if ( $action !== 'unchanged' && $autoReason === null ) {
				$packs[] = $packName;
			}
		}
		return $packs;
	}

	/**
	 * Get auto-actioned pack names.
	 *
	 * @return array Array of pack names
	 */
	public function getAutoActionedPackNames(): array {
		$packs = [];
		foreach ( $this->packs as $packName => $packState ) {
			$autoReason = $packState['auto_selected_reason'] ?? null;
			if ( $autoReason !== null ) {
				$packs[] = $packName;
			}
		}
		return $packs;
	}

	/**
	 * Set pack action with optional auto-selection reason.
	 *
	 * @param string $packName Pack name
	 * @param string $action Action to set (install|update|remove|unchanged)
	 * @param string|null $autoReason Reason if auto-set, null if manual
	 */
	public function setPackAction( string $packName, string $action, ?string $autoReason = null ): void {
		if ( isset( $this->packs[$packName] ) ) {
			$this->packs[$packName]['action'] = $action;
			$this->packs[$packName]['auto_selected_reason'] = $autoReason;
			$this->updateHash();
		}
	}

	/**
	 * Update page final title in a pack.
	 *
	 * @param string $packName Pack name
	 * @param string $pageName Page name
	 * @param string $finalTitle New final title
	 */
	public function setPageFinalTitle( string $packName, string $pageName, string $finalTitle ): void {
		if ( isset( $this->packs[$packName]['pages'][$pageName] ) ) {
			$this->packs[$packName]['pages'][$pageName]['final_title'] = $finalTitle;
			$this->updateHash();
		}
	}

	/**
	 * Update pack prefix and recompute page titles.
	 *
	 * @param string $packName Pack name
	 * @param string $prefix New prefix
	 */
	public function setPackPrefix( string $packName, string $prefix ): void {
		if ( isset( $this->packs[$packName] ) ) {
			$this->packs[$packName]['prefix'] = $prefix;

			// Recompute default titles for all pages
			foreach ( $this->packs[$packName]['pages'] as $pageName => &$pageState ) {
				$oldDefaultTitle = $pageState['default_title'] ?? '';
				$defaultTitle = $prefix ? "{$prefix}/{$pageName}" : $pageName;
				$pageState['default_title'] = $defaultTitle;

				// Update final_title if it was using the old default
				if ( $pageState['final_title'] === $oldDefaultTitle ) {
					$pageState['final_title'] = $defaultTitle;
				}
			}

			$this->updateHash();
		}
	}

	/**
	 * Get state hash.
	 *
	 * @return string
	 */
	public function hash(): string {
		return $this->hash;
	}

	/**
	 * Get timestamp.
	 *
	 * @return int
	 */
	public function timestamp(): int {
		return $this->timestamp;
	}

	/**
	 * Compute state hash based on current state.
	 *
	 * @return string
	 */
	public function computeHash(): string {
		$data = [
			'refId' => $this->refId->toInt(),
			'userId' => $this->userId,
			'packs' => $this->packs,
		];
		return substr( md5( json_encode( $data ) ), 0, 12 );
	}

	/**
	 * Update hash and timestamp.
	 */
	private function updateHash(): void {
		$this->hash = $this->computeHash();
		$this->timestamp = time();
	}

	/**
	 * Convert state to array representation.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return [
			'ref_id' => $this->refId->toInt(),
			'user_id' => $this->userId,
			'packs' => $this->packs,
			'hash' => $this->hash,
			'timestamp' => $this->timestamp,
		];
	}

	/**
	 * Create state from array representation.
	 *
	 * @param array $data Array data
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			new ContentRefId( $data['ref_id'] ),
			$data['user_id'],
			$data['packs'] ?? [],
			$data['hash'] ?? null,
			$data['timestamp'] ?? null
		);
	}

	/**
	 * Create initial pack state structure from manifest pack definition.
	 *
	 * @param string $packName Pack name
	 * @param array $packDef Pack definition from manifest
	 * @param string|null $currentVersion Currently installed version (null if not installed)
	 * @param array $installedPageNames List of installed page names (empty if pack not installed)
	 * @return array Pack state structure
	 */
	public static function createPackState(
		string $packName,
		array $packDef,
		?string $currentVersion = null,
		array $installedPageNames = []
	): array {
		$targetVersion = $packDef['version'];
		$prefix = $packDef['prefix'] ?? '';

		// Determine action type - start with 'unchanged' for all
		// Users must explicitly click buttons to mark for install/update/remove
		$action = 'unchanged';

		// Build pack-level state
		$packState = [];
		foreach ( self::PACK_FIELDS as $field ) {
			$packState[$field] = match ( $field ) {
				'action' => $action,
				'auto_selected_reason' => null,
				'current_version' => $currentVersion,
				'target_version' => $targetVersion,
				'prefix' => $prefix,
				'installed' => $currentVersion !== null,
				default => null,
			};
		}

		// Build pages array
		$pages = [];
		$manifestPages = $packDef['pages'] ?? [];
		foreach ( $manifestPages as $pageName ) {
			$defaultTitle = $prefix ? "{$prefix}/{$pageName}" : $pageName;
			$isInstalled = in_array( $pageName, $installedPageNames, true );

			$pageState = [];
			foreach ( self::PAGE_FIELDS as $f ) {
				$pageState[$f] = match ( $f ) {
					'name' => $pageName,
					'default_title' => $defaultTitle,
					'final_title' => $defaultTitle,
					'has_conflict' => false,
					'conflict_type' => null,
					'installed' => $isInstalled,
					default => null,
				};
			}
			$pages[$pageName] = $pageState;
		}

		$packState['pages'] = $pages;

		return $packState;
	}
}
