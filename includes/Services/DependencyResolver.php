<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\Pack;

/**
 * DependencyResolver
 *
 * Handles dependency resolution, conflict detection, and version comparison
 * for pack selection operations. Works with manifest data and installed packs
 * to compute auto-selections, validate removals, and detect conflicts.
 *
 * @package LabkiPackManager\Services
 */
final class DependencyResolver {

	private LabkiPackRegistry $packRegistry;

	/**
	 * Constructor.
	 *
	 * @param LabkiPackRegistry|null $packRegistry Optional registry instance
	 */
	public function __construct( ?LabkiPackRegistry $packRegistry = null ) {
		$this->packRegistry = $packRegistry ?? new LabkiPackRegistry();
	}

	/**
	 * Resolve auto-selections for selected packs.
	 *
	 * Returns packs that must be auto-selected to satisfy dependencies.
	 * Recursively resolves transitive dependencies.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $selectedPacks Array of selected pack names
	 * @param array $manifest Parsed manifest data
	 * @return array Map of pack_name => reason for auto-selection
	 */
	public function resolveAutoSelections(
		ContentRefId $refId,
		array $selectedPacks,
		array $manifest
	): array {
		$packs = $manifest['packs'] ?? [];
		$autoSelected = [];
		$processed = [];

		// Get installed packs to check what's already available
		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		$installedPackNames = [];
		foreach ( $installedPacks as $pack ) {
			$installedPackNames[] = $pack->name();
		}

		// Recursively process dependencies
		$toProcess = $selectedPacks;
		while ( !empty( $toProcess ) ) {
			$packName = array_shift( $toProcess );

			if ( isset( $processed[$packName] ) ) {
				continue;
			}
			$processed[$packName] = true;

			if ( !isset( $packs[$packName] ) ) {
				continue;
			}

			$packDef = $packs[$packName];
			$dependencies = $packDef['depends_on'] ?? [];

			foreach ( $dependencies as $depName ) {
				// Skip if already selected by user
				if ( in_array( $depName, $selectedPacks, true ) ) {
					continue;
				}

				// Skip if already installed (unless we need to update it)
				// For now, we assume installed dependencies are sufficient
				if ( in_array( $depName, $installedPackNames, true ) ) {
					continue;
				}

				// Auto-select this dependency
				if ( !isset( $autoSelected[$depName] ) ) {
					$autoSelected[$depName] = "Required by {$packName}";
					// Add to queue to process its dependencies
					$toProcess[] = $depName;
				}
			}
		}

		return $autoSelected;
	}

	/**
	 * Validate pack removal.
	 *
	 * Checks if removing packs would break dependencies of other selected packs.
	 * Returns array of conflicts (pack_name => dependent_pack_names).
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $packsToRemove Array of pack names to remove
	 * @param array $selectedPacks Array of pack names that will remain selected
	 * @param array $manifest Parsed manifest data
	 * @return array Map of pack_name => [dependent_pack_names]
	 */
	public function validateRemoval(
		ContentRefId $refId,
		array $packsToRemove,
		array $selectedPacks,
		array $manifest
	): array {
		$conflicts = [];
		$packs = $manifest['packs'] ?? [];

		// Check each pack being removed
		foreach ( $packsToRemove as $packName ) {
			$dependents = [];

			// Check if any selected pack depends on this one
			foreach ( $selectedPacks as $selectedPack ) {
				if ( !isset( $packs[$selectedPack] ) ) {
					continue;
				}

				$dependencies = $packs[$selectedPack]['depends_on'] ?? [];
				if ( in_array( $packName, $dependencies, true ) ) {
					$dependents[] = $selectedPack;
				}
			}

			if ( !empty( $dependents ) ) {
				$conflicts[$packName] = $dependents;
			}
		}

		return $conflicts;
	}

	/**
	 * Compute update paths for installed packs.
	 *
	 * Compares installed pack versions with manifest versions to determine
	 * which packs have updates available.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $manifest Parsed manifest data
	 * @return array Map of pack_name => ['current' => version, 'available' => version, 'action' => 'update'|'current']
	 */
	public function computeUpdatePaths(
		ContentRefId $refId,
		array $manifest
	): array {
		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		$manifestPacks = $manifest['packs'] ?? [];
		$updatePaths = [];

		foreach ( $installedPacks as $pack ) {
			$packName = $pack->name();
			$currentVersion = $pack->version();

			if ( !isset( $manifestPacks[$packName] ) ) {
				// Pack no longer in manifest
				$updatePaths[$packName] = [
					'current' => $currentVersion,
					'available' => null,
					'action' => 'orphaned',
					'message' => 'Pack no longer exists in manifest',
				];
				continue;
			}

			$manifestVersion = $manifestPacks[$packName]['version'] ?? null;

			if ( $manifestVersion === null ) {
				$updatePaths[$packName] = [
					'current' => $currentVersion,
					'available' => null,
					'action' => 'unknown',
					'message' => 'Manifest version missing',
				];
				continue;
			}

			$comparison = $this->compareVersions( $currentVersion, $manifestVersion );

			if ( $comparison < 0 ) {
				// Update available
				$updatePaths[$packName] = [
					'current' => $currentVersion,
					'available' => $manifestVersion,
					'action' => 'update',
				];
			} elseif ( $comparison > 0 ) {
				// Installed version is newer (downgrade?)
				$updatePaths[$packName] = [
					'current' => $currentVersion,
					'available' => $manifestVersion,
					'action' => 'downgrade',
					'message' => 'Installed version is newer than manifest',
				];
			} else {
				// Up to date
				$updatePaths[$packName] = [
					'current' => $currentVersion,
					'available' => $manifestVersion,
					'action' => 'current',
				];
			}
		}

		return $updatePaths;
	}

	/**
	 * Detect version conflicts in selected packs.
	 *
	 * Checks if any selected packs require updates to their dependencies
	 * due to version constraints. For now, this is simplified: if PackA
	 * depends on PackB and PackB has an update, we flag it.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $selectedPacks Array of selected pack names
	 * @param array $manifest Parsed manifest data
	 * @return array Array of conflicts with update requirements
	 */
	public function detectVersionConflicts(
		ContentRefId $refId,
		array $selectedPacks,
		array $manifest
	): array {
		$conflicts = [];
		$packs = $manifest['packs'] ?? [];
		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		$installedMap = [];

		foreach ( $installedPacks as $pack ) {
			$installedMap[$pack->name()] = $pack;
		}

		// Check each selected pack
		foreach ( $selectedPacks as $packName ) {
			if ( !isset( $packs[$packName] ) ) {
				continue;
			}

			$packDef = $packs[$packName];
			$dependencies = $packDef['depends_on'] ?? [];

			foreach ( $dependencies as $depName ) {
				// If dependency is installed, check if it needs update
				if ( isset( $installedMap[$depName] ) ) {
					$installedDep = $installedMap[$depName];
					$currentVersion = $installedDep->version();

					if ( isset( $packs[$depName]['version'] ) ) {
						$manifestVersion = $packs[$depName]['version'];
						$comparison = $this->compareVersions( $currentVersion, $manifestVersion );

						if ( $comparison < 0 ) {
							// Dependency needs update
							$conflicts[] = [
								'type' => 'dependency_update_required',
								'pack' => $depName,
								'required_by' => $packName,
								'current_version' => $currentVersion,
								'required_version' => $manifestVersion,
								'message' => "{$depName} will be updated from {$currentVersion} to {$manifestVersion} (required by {$packName})",
							];
						}
					}
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Compare two semantic version strings.
	 *
	 * @param string $version1 First version
	 * @param string $version2 Second version
	 * @return int -1 if v1 < v2, 0 if equal, 1 if v1 > v2
	 */
	private function compareVersions( string $version1, string $version2 ): int {
		// Simple semantic version comparison
		$v1 = $this->parseVersion( $version1 );
		$v2 = $this->parseVersion( $version2 );

		// Compare major
		if ( $v1['major'] !== $v2['major'] ) {
			return $v1['major'] <=> $v2['major'];
		}

		// Compare minor
		if ( $v1['minor'] !== $v2['minor'] ) {
			return $v1['minor'] <=> $v2['minor'];
		}

		// Compare patch
		return $v1['patch'] <=> $v2['patch'];
	}

	/**
	 * Parse semantic version string.
	 *
	 * @param string $version Version string (e.g., "1.2.3")
	 * @return array ['major' => int, 'minor' => int, 'patch' => int]
	 */
	private function parseVersion( string $version ): array {
		// Remove leading 'v' if present
		$version = ltrim( $version, 'vV' );

		$parts = explode( '.', $version );

		return [
			'major' => isset( $parts[0] ) ? (int)$parts[0] : 0,
			'minor' => isset( $parts[1] ) ? (int)$parts[1] : 0,
			'patch' => isset( $parts[2] ) ? (int)$parts[2] : 0,
		];
	}
}

