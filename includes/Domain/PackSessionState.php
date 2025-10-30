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
 * - selected: User manually selected this pack
 * - auto_selected: Pack was auto-selected as dependency
 * - auto_selected_reason: Why it was auto-selected
 * - action: install|update|remove|unchanged
 * - current_version: Version currently installed (null if not installed)
 * - target_version: Version from manifest
 * - prefix: Pack prefix for page titles (user-customizable)
 * - pages: Array of page states
 *
 * ## Page State Fields
 * - name: Original page name from manifest
 * - default_title: Computed default title (prefix/name)
 * - final_title: User-customized title or default
 * - has_conflict: Whether this title conflicts with existing page
 * - conflict_type: Type of conflict (title_exists, namespace_invalid, etc.)
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
	 * Get all selected pack names (user-selected + auto-selected).
	 *
	 * @return array Array of pack names
	 */
	public function getSelectedPackNames(): array {
		$selected = [];
		foreach ( $this->packs as $packName => $packState ) {
			if ( ( $packState['selected'] ?? false ) || ( $packState['auto_selected'] ?? false ) ) {
				$selected[] = $packName;
			}
		}
		return $selected;
	}

	/**
	 * Get user-selected pack names (excluding auto-selected).
	 *
	 * @return array Array of pack names
	 */
	public function getUserSelectedPackNames(): array {
		$selected = [];
		foreach ( $this->packs as $packName => $packState ) {
			if ( $packState['selected'] ?? false ) {
				$selected[] = $packName;
			}
		}
		return $selected;
	}

	/**
	 * Get auto-selected pack names.
	 *
	 * @return array Array of pack names
	 */
	public function getAutoSelectedPackNames(): array {
		$selected = [];
		foreach ( $this->packs as $packName => $packState ) {
			if ( $packState['auto_selected'] ?? false ) {
				$selected[] = $packName;
			}
		}
		return $selected;
	}

	/**
	 * Mark pack as selected.
	 *
	 * @param string $packName Pack name
	 */
	public function selectPack( string $packName ): void {
		if ( isset( $this->packs[$packName] ) ) {
			$this->packs[$packName]['selected'] = true;
			$this->packs[$packName]['auto_selected'] = false;
			$this->updateHash();
		}
	}

	/**
	 * Mark pack as deselected.
	 *
	 * @param string $packName Pack name
	 */
	public function deselectPack( string $packName ): void {
		if ( isset( $this->packs[$packName] ) ) {
			$this->packs[$packName]['selected'] = false;
			$this->packs[$packName]['auto_selected'] = false;
			$this->updateHash();
		}
	}

	/**
	 * Mark pack as auto-selected.
	 *
	 * @param string $packName Pack name
	 * @param string $reason Reason for auto-selection
	 */
	public function autoSelectPack( string $packName, string $reason ): void {
		if ( isset( $this->packs[$packName] ) ) {
			$this->packs[$packName]['auto_selected'] = true;
			$this->packs[$packName]['auto_selected_reason'] = $reason;
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
	 * @return array Pack state structure
	 */
	public static function createPackState(
		string $packName,
		array $packDef,
		?string $currentVersion = null
	): array {
		$targetVersion = $packDef['version'] ?? '0.0.0';
		$prefix = $packDef['prefix'] ?? $packName;

		// Determine action
		if ( $currentVersion === null ) {
			$action = 'install';
		} elseif ( $currentVersion !== $targetVersion ) {
			$action = 'update';
		} else {
			$action = 'unchanged';
		}

		// Build pages array
		$pages = [];
		$manifestPages = $packDef['pages'] ?? [];
		foreach ( $manifestPages as $pageName ) {
			$defaultTitle = $prefix ? "{$prefix}/{$pageName}" : $pageName;
			$pages[$pageName] = [
				'name' => $pageName,
				'default_title' => $defaultTitle,
				'final_title' => $defaultTitle,
				'has_conflict' => false,
				'conflict_type' => null,
			];
		}

		return [
			'selected' => $currentVersion !== null, // Pre-select if installed
			'auto_selected' => false,
			'auto_selected_reason' => null,
			'action' => $action,
			'current_version' => $currentVersion,
			'target_version' => $targetVersion,
			'prefix' => $prefix,
			'pages' => $pages,
		];
	}
}
