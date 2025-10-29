<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\PackId;
use MediaWiki\MediaWikiServices;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Title\Title;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Page\WikiPage;
use Status;
use Symfony\Component\Yaml\Yaml;

/**
 * LabkiPackManager Service
 *
 * Unified service for all pack and page operations:
 * - Installing packs and their pages
 * - Updating existing packs
 * - Removing packs and their pages
 *
 * This service handles the core business logic for pack management, including:
 * - Fetching content from git worktrees
 * - Building link rewrite maps
 * - Creating/updating MediaWiki pages
 * - Registering packs and pages in the database
 *
 * Design principles:
 * - Works with git worktrees (not direct fetches from GitHub)
 * - Returns results instead of dying with errors (async-friendly)
 * - Testable and reusable from jobs, CLI, or API
 * - Uses domain objects (ContentRefId, PackId, etc.)
 *
 * @ingroup Services
 */
class LabkiPackManager {

	private LabkiPackRegistry $packRegistry;
	private LabkiPageRegistry $pageRegistry;
	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private ManifestStore $manifestStore;

	/**
	 * Constructor.
	 *
	 * @param LabkiPackRegistry|null $packRegistry Optional pack registry (for testing)
	 * @param LabkiPageRegistry|null $pageRegistry Optional page registry (for testing)
	 * @param LabkiRepoRegistry|null $repoRegistry Optional repo registry (for testing)
	 * @param LabkiRefRegistry|null $refRegistry Optional ref registry (for testing)
	 */
	public function __construct(
		?LabkiPackRegistry $packRegistry = null,
		?LabkiPageRegistry $pageRegistry = null,
		?LabkiRepoRegistry $repoRegistry = null,
		?LabkiRefRegistry $refRegistry = null
	) {
		$this->packRegistry = $packRegistry ?? new LabkiPackRegistry();
		$this->pageRegistry = $pageRegistry ?? new LabkiPageRegistry();
		$this->repoRegistry = $repoRegistry ?? new LabkiRepoRegistry();
		$this->refRegistry = $refRegistry ?? new LabkiRefRegistry();
	}

	/**
	 * Validate pack dependencies before installation.
	 *
	 * Checks that all `depends_on` packs are either:
	 * 1. Already installed for this ref, OR
	 * 2. Included in the current installation request
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $packsToInstall Array of pack names to be installed
	 * @return array Array of missing dependency pack names (empty if all satisfied)
	 */
	public function validatePackDependencies( ContentRefId $refId, array $packsToInstall ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::validatePackDependencies() called for refId={$refId->toInt()}" );

		// Get ref to access worktree
		$ref = $this->refRegistry->getRefById( $refId );
		if ( !$ref ) {
			wfDebugLog( 'labkipack', "Ref not found: {$refId->toInt()}" );
			return [];
		}

		$worktreePath = $ref->worktreePath();
		if ( !$worktreePath || !is_dir( $worktreePath ) ) {
			wfDebugLog( 'labkipack', "Worktree not found: {$worktreePath}" );
			return [];
		}

		// Get manifest to extract depends_on for each pack
		$manifestPages = $this->getManifestPagesFromWorktree( $worktreePath );
		$manifestPath = $worktreePath . '/manifest.yml';

		if ( !file_exists( $manifestPath ) ) {
			wfDebugLog( 'labkipack', "Manifest not found for dependency validation: {$manifestPath}" );
			return [];
		}

		$manifestContent = file_get_contents( $manifestPath );
		if ( $manifestContent === false ) {
			wfDebugLog( 'labkipack', "Failed to read manifest: {$manifestPath}" );
			return [];
		}

		try {
			$manifest = Yaml::parse( $manifestContent );
			if ( !is_array( $manifest ) ) {
				return [];
			}
		} catch ( \Exception $e ) {
			wfDebugLog( 'labkipack', "Failed to parse manifest: " . $e->getMessage() );
			return [];
		}

		$packs = $manifest['packs'] ?? [];
		if ( !is_array( $packs ) ) {
			return [];
		}

		// Get list of already installed packs for this ref
		$installedPackNames = [];
		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		foreach ( $installedPacks as $pack ) {
			$installedPackNames[] = $pack->name();
		}

		wfDebugLog( 'labkipack', "Already installed packs: " . implode( ', ', $installedPackNames ) );

		// Collect all missing dependencies
		$missingDeps = [];

		foreach ( $packsToInstall as $packName ) {
			if ( !isset( $packs[$packName] ) ) {
				wfDebugLog( 'labkipack', "Pack {$packName} not found in manifest" );
				continue;
			}

			$packDef = $packs[$packName];
			$dependsOn = $packDef['depends_on'] ?? [];

			if ( !is_array( $dependsOn ) ) {
				continue;
			}

			wfDebugLog( 'labkipack', "Pack {$packName} depends on: " . implode( ', ', $dependsOn ) );

			foreach ( $dependsOn as $depName ) {
				// Check if dependency is satisfied
				$isInstalled = in_array( $depName, $installedPackNames, true );
				$isInCurrentRequest = in_array( $depName, $packsToInstall, true );

				if ( !$isInstalled && !$isInCurrentRequest ) {
					// Dependency is missing
					if ( !in_array( $depName, $missingDeps, true ) ) {
						$missingDeps[] = $depName;
						wfDebugLog( 'labkipack', "Missing dependency: {$depName} (required by {$packName})" );
					}
				}
			}
		}

		if ( !empty( $missingDeps ) ) {
			wfDebugLog( 'labkipack', "Validation failed: missing dependencies: " . implode( ', ', $missingDeps ) );
		} else {
			wfDebugLog( 'labkipack', "Validation passed: all dependencies satisfied" );
		}

		return $missingDeps;
	}

	/**
	 * Validate pack removal dependencies.
	 *
	 * Checks if any other installed packs depend on the packs being removed.
	 * Uses database-stored dependencies (as installed) rather than current manifest.
	 * Returns a map of pack names to their dependent pack names.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $packsToRemove Array of pack names to be removed
	 * @return array Map of pack name => array of dependent pack names (empty if removal is safe)
	 */
	public function validatePackRemoval( ContentRefId $refId, array $packsToRemove ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::validatePackRemoval() called for refId={$refId->toInt()}" );

		// Get all installed packs for this ref
		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		
		// Build a map of pack name => pack object for quick lookup
		$packMap = [];
		$packsBeingRemoved = [];
		foreach ( $installedPacks as $pack ) {
			$packMap[$pack->name()] = $pack;
			if ( in_array( $pack->name(), $packsToRemove, true ) ) {
				$packsBeingRemoved[$pack->name()] = $pack;
			}
		}

		wfDebugLog( 'labkipack', "Checking dependencies for " . count( $packsBeingRemoved ) . " pack(s) being removed" );

		// Check if any packs (not being removed) depend on the packs being removed
		$blockingDependencies = [];

		foreach ( $packsBeingRemoved as $packName => $pack ) {
			// Get all packs that depend on this pack
			$dependentPacks = $this->packRegistry->getPacksDependingOn( $refId, $pack->id() );
			
			foreach ( $dependentPacks as $dependentPack ) {
				// If the dependent pack is also being removed, it's not a blocking dependency
				if ( in_array( $dependentPack->name(), $packsToRemove, true ) ) {
					continue;
				}
				
				// This is a blocking dependency
				if ( !isset( $blockingDependencies[$packName] ) ) {
					$blockingDependencies[$packName] = [];
				}
				$blockingDependencies[$packName][] = $dependentPack->name();
				wfDebugLog( 'labkipack', "Blocking dependency: {$dependentPack->name()} depends on {$packName}" );
			}
		}

		if ( !empty( $blockingDependencies ) ) {
			wfDebugLog( 'labkipack', "Removal validation failed: " . count( $blockingDependencies ) . " pack(s) have dependents" );
		} else {
			wfDebugLog( 'labkipack', "Removal validation passed: no blocking dependencies" );
		}

		return $blockingDependencies;
	}

	/**
	 * Validate that all specified packs are installed.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $packNames Array of pack names to check
	 * @return array Array of pack names that are NOT installed (empty if all are installed)
	 */
	public function validatePacksInstalled( ContentRefId $refId, array $packNames ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::validatePacksInstalled() called for refId={$refId->toInt()}" );

		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		$installedPackNames = array_map( fn( $pack ) => $pack->name(), $installedPacks );

		$notInstalled = [];
		foreach ( $packNames as $packName ) {
			if ( !in_array( $packName, $installedPackNames, true ) ) {
				$notInstalled[] = $packName;
				wfDebugLog( 'labkipack', "Pack not installed: {$packName}" );
			}
		}

		return $notInstalled;
	}

	/**
	 * Validate version compatibility for pack updates.
	 * Ensures major version does not change (e.g., 1.x.x → 2.0.0 is blocked).
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $packs Array of pack definitions with name and optional target_version
	 * @return array Map of pack name => error message (empty if all versions compatible)
	 */
	public function validatePackVersions( ContentRefId $refId, array $packs ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::validatePackVersions() called for refId={$refId->toInt()}" );

		// Get ref to access worktree for target versions
		$ref = $this->refRegistry->getRefById( $refId );
		if ( !$ref ) {
			wfDebugLog( 'labkipack', "Ref not found: {$refId->toInt()}" );
			return [];
		}

		$worktreePath = $ref->worktreePath();
		if ( !$worktreePath || !is_dir( $worktreePath ) ) {
			wfDebugLog( 'labkipack', "Worktree not found: {$worktreePath}" );
			return [];
		}

		// Get manifest to extract target versions
		$manifestPath = $worktreePath . '/manifest.yml';
		if ( !file_exists( $manifestPath ) ) {
			wfDebugLog( 'labkipack', "Manifest not found: {$manifestPath}" );
			return [];
		}

		$manifestContent = file_get_contents( $manifestPath );
		if ( $manifestContent === false ) {
			wfDebugLog( 'labkipack', "Failed to read manifest: {$manifestPath}" );
			return [];
		}

		try {
			$manifest = \Symfony\Component\Yaml\Yaml::parse( $manifestContent );
			if ( !is_array( $manifest ) ) {
				return [];
			}
		} catch ( \Exception $e ) {
			wfDebugLog( 'labkipack', "Failed to parse manifest: " . $e->getMessage() );
			return [];
		}

		$manifestPacks = $manifest['packs'] ?? [];

		$versionErrors = [];

		foreach ( $packs as $packDef ) {
			$packName = $packDef['name'];
			
			// Get currently installed version
			$packId = $this->packRegistry->getPackIdByName( $refId, $packName );
			if ( $packId === null ) {
				continue; // Pack not installed (should be caught by validatePacksInstalled)
			}

			$installedPack = $this->packRegistry->getPack( $packId );
			if ( !$installedPack ) {
				continue;
			}

			$currentVersion = $installedPack->version();
			
			// Get target version from manifest or pack definition
			$targetVersion = $packDef['target_version'] ?? null;
			if ( $targetVersion === null && isset( $manifestPacks[$packName]['version'] ) ) {
				$targetVersion = $manifestPacks[$packName]['version'];
			}

			// If no target version specified, skip validation (will use manifest version)
			if ( $targetVersion === null || $currentVersion === null ) {
				continue;
			}

			// Parse versions to check major version
			$currentParts = $this->parseVersion( $currentVersion );
			$targetParts = $this->parseVersion( $targetVersion );

			if ( $currentParts === null || $targetParts === null ) {
				wfDebugLog( 'labkipack', "Invalid version format for {$packName}: current={$currentVersion}, target={$targetVersion}" );
				$versionErrors[$packName] = "Invalid version format (current: {$currentVersion}, target: {$targetVersion})";
				continue;
			}

			// Check if major version changed
			if ( $currentParts['major'] !== $targetParts['major'] ) {
				wfDebugLog( 'labkipack', "Major version change detected for {$packName}: {$currentVersion} → {$targetVersion}" );
				$versionErrors[$packName] = "Major version cannot change ({$currentVersion} → {$targetVersion})";
			}
		}

		return $versionErrors;
	}

	/**
	 * Validate dependency compatibility for pack updates.
	 * Ensures that updating the specified packs won't break dependency relationships.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $packNames Array of pack names being updated
	 * @return array Array of error messages (empty if dependencies are compatible)
	 */
	public function validatePackUpdateDependencies( ContentRefId $refId, array $packNames ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::validatePackUpdateDependencies() called for refId={$refId->toInt()}" );

		$errors = [];

		// Get all installed packs for this ref
		$installedPacks = $this->packRegistry->listPacksByRef( $refId );
		$installedPackMap = [];
		foreach ( $installedPacks as $pack ) {
			$installedPackMap[$pack->name()] = $pack;
		}

		// For each pack being updated, check if any OTHER installed packs depend on it
		foreach ( $packNames as $packName ) {
			if ( !isset( $installedPackMap[$packName] ) ) {
				continue; // Not installed
			}

			$pack = $installedPackMap[$packName];
			$dependents = $this->packRegistry->getPacksDependingOn( $refId, $pack->id() );

			foreach ( $dependents as $dependent ) {
				// If the dependent is also being updated, that's fine
				if ( in_array( $dependent->name(), $packNames, true ) ) {
					continue;
				}

				// Otherwise, this is a potential issue
				// Note: We're being conservative here - in reality, the update might still be compatible
				// A more sophisticated check would compare dependency constraints
				wfDebugLog( 'labkipack', "Pack {$packName} is being updated but {$dependent->name()} depends on it and is NOT being updated" );
				$errors[] = "Pack '{$packName}' has dependent '{$dependent->name()}' which is not being updated";
			}
		}

		return $errors;
	}

	/**
	 * Parse semantic version string into major, minor, patch components.
	 *
	 * @param string $version Version string (e.g., "1.2.3")
	 * @return array{major:int,minor:int,patch:int}|null Parsed version or null if invalid
	 */
	private function parseVersion( string $version ): ?array {
		// Support semantic versioning (major.minor.patch)
		if ( preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $version, $matches ) ) {
			return [
				'major' => (int)$matches[1],
				'minor' => (int)$matches[2],
				'patch' => (int)$matches[3],
			];
		}

		// Support major.minor format
		if ( preg_match( '/^(\d+)\.(\d+)/', $version, $matches ) ) {
			return [
				'major' => (int)$matches[1],
				'minor' => (int)$matches[2],
				'patch' => 0,
			];
		}

		// Support major only
		if ( preg_match( '/^(\d+)$/', $version, $matches ) ) {
			return [
				'major' => (int)$matches[1],
				'minor' => 0,
				'patch' => 0,
			];
		}

		return null;
	}

	/**
	 * Update a pack by name (wrapper around updatePack that resolves name to PackId).
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param string $packName Pack name
	 * @param string|null $targetVersion Optional target version
	 * @param int $userId User ID performing the update
	 * @return array Update result
	 */
	public function updatePackByName( ContentRefId $refId, string $packName, ?string $targetVersion, int $userId ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::updatePackByName() called for pack={$packName}, refId={$refId->toInt()}" );

		// Resolve pack name to pack ID
		$packId = $this->packRegistry->getPackIdByName( $refId, $packName );
		if ( $packId === null ) {
			return [
				'success' => false,
				'pack' => $packName,
				'error' => "Pack not found: {$packName}",
			];
		}

		// Get ref to access worktree
		$ref = $this->refRegistry->getRefById( $refId );
		if ( !$ref ) {
			return [
				'success' => false,
				'pack' => $packName,
				'error' => "Ref not found: {$refId->toInt()}",
			];
		}

		$worktreePath = $ref->worktreePath();
		if ( !$worktreePath || !is_dir( $worktreePath ) ) {
			return [
				'success' => false,
				'pack' => $packName,
				'error' => "Worktree not found: {$worktreePath}",
			];
		}

		// Get manifest to build pack definition
		$manifestPath = $worktreePath . '/manifest.yml';
		if ( !file_exists( $manifestPath ) ) {
			return [
				'success' => false,
				'pack' => $packName,
				'error' => "Manifest not found: {$manifestPath}",
			];
		}

		$manifestContent = file_get_contents( $manifestPath );
		if ( $manifestContent === false ) {
			return [
				'success' => false,
				'pack' => $packName,
				'error' => "Failed to read manifest: {$manifestPath}",
			];
		}

		try {
			$manifest = \Symfony\Component\Yaml\Yaml::parse( $manifestContent );
			if ( !is_array( $manifest ) ) {
				return [
					'success' => false,
					'pack' => $packName,
					'error' => 'Invalid manifest format',
				];
			}
		} catch ( \Exception $e ) {
			return [
				'success' => false,
				'pack' => $packName,
				'error' => "Failed to parse manifest: " . $e->getMessage(),
			];
		}

		$manifestPacks = $manifest['packs'] ?? [];
		$manifestPages = $manifest['pages'] ?? [];

		if ( !isset( $manifestPacks[$packName] ) ) {
			return [
				'success' => false,
				'pack' => $packName,
				'error' => "Pack not found in manifest: {$packName}",
			];
		}

		$packDef = $manifestPacks[$packName];
		$packDef['version'] = $targetVersion ?? ( $packDef['version'] ?? null );
		
		// Build pages array for this pack
		$pageList = $packDef['pages'] ?? [];
		$pages = [];
		foreach ( $pageList as $pageName ) {
			if ( isset( $manifestPages[$pageName] ) ) {
				$pages[] = [
					'name' => $pageName,
					'file' => $manifestPages[$pageName]['file'] ?? null,
				];
			}
		}
		$packDef['pages'] = $pages;

		// Call the existing updatePack method
		return $this->updatePack( $packId, $refId, $packDef, $userId );
	}

	/**
	 * Install one or more packs.
	 *
	 * @param ContentRefId $refId Content ref ID (repo + branch/tag)
	 * @param array $packs Array of pack definitions with pages
	 * @param int $userId User ID performing the installation
	 * @return array Installation results with success status and details
	 */
	public function installPacks( ContentRefId $refId, array $packs, int $userId ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::installPacks() called for refId={$refId->toInt()}, userId={$userId}" );

		$results = [
			'success' => true,
			'installed' => [],
			'failed' => [],
			'errors' => [],
		];

		// Get ref and repo information
		$ref = $this->refRegistry->getRefById( $refId );
		if ( !$ref ) {
			$results['success'] = false;
			$results['errors'][] = "Ref not found: {$refId->toInt()}";
			return $results;
		}

		$repo = $this->repoRegistry->getRepo( $ref->contentRepoId()->toInt() );
		if ( !$repo ) {
			$results['success'] = false;
			$results['errors'][] = "Repo not found for ref: {$refId->toInt()}";
			return $results;
		}

		$worktreePath = $ref->worktreePath();
		if ( !$worktreePath || !is_dir( $worktreePath ) ) {
			$results['success'] = false;
			$results['errors'][] = "Worktree not found: {$worktreePath}";
			return $results;
		}

		wfDebugLog( 'labkipack', "Using worktree: {$worktreePath}" );

		// Build rewrite map (includes existing pages + new pages from all packs)
		$rewriteMap = $this->buildRewriteMap( $refId, $packs );
		wfDebugLog( 'labkipack', "Built rewrite map with " . count( $rewriteMap ) . " entries" );

		// Get manifest pages map (page name -> file path)
		$manifestPages = $this->getManifestPagesFromWorktree( $worktreePath );
		wfDebugLog( 'labkipack', "Loaded " . count( $manifestPages ) . " pages from manifest" );

		// Install each pack
		foreach ( $packs as $packDef ) {
			$packName = $packDef['name'] ?? '';
			$version = $packDef['version'] ?? '';

			if ( $packName === '' ) {
				$results['failed'][] = [
					'pack' => '(unnamed)',
					'error' => 'Pack name is required',
				];
				$results['success'] = false;
				continue;
			}

			try {
				$installResult = $this->installSinglePack(
					$refId,
					$packDef,
					$userId,
					$worktreePath,
					$rewriteMap,
					$manifestPages
				);

				if ( $installResult['success'] ) {
					$results['installed'][] = $installResult;
				} else {
					$results['failed'][] = $installResult;
					$results['success'] = false;
				}
			} catch ( \Exception $e ) {
				wfDebugLog( 'labkipack', "Exception installing pack {$packName}: " . $e->getMessage() );
				$results['failed'][] = [
					'pack' => $packName,
					'error' => $e->getMessage(),
				];
				$results['success'] = false;
			}
		}

		return $results;
	}

	/**
	 * Install a single pack.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $packDef Pack definition with name, version, and pages
	 * @param int $userId User ID performing the installation
	 * @param string|null $worktreePath Path to git worktree (auto-fetched if null)
	 * @param array|null $rewriteMap Link rewrite map (auto-built if null)
	 * @param array|null $manifestPages Map of page name to file path (auto-loaded if null)
	 * @return array Installation result for this pack
	 */
	public function installSinglePack(
		ContentRefId $refId,
		array $packDef,
		int $userId,
		?string $worktreePath = null,
		?array $rewriteMap = null,
		?array $manifestPages = null
	): array {
		$packName = $packDef['name'];
		$version = $packDef['version'] ?? null;
		$pages = $packDef['pages'] ?? [];

		wfDebugLog( 'labkipack', "Installing pack: {$packName} (version: {$version})" );

		// Auto-fetch worktree path if not provided
		if ( $worktreePath === null ) {
			$ref = $this->refRegistry->getRefById( $refId );
			if ( !$ref ) {
				return [
					'success' => false,
					'pack' => $packName,
					'error' => "Ref not found: {$refId->toInt()}",
				];
			}
			$worktreePath = $ref->worktreePath();
			if ( !$worktreePath || !is_dir( $worktreePath ) ) {
				return [
					'success' => false,
					'pack' => $packName,
					'error' => "Worktree not found for ref {$refId->toInt()}",
				];
			}
		}

		// Auto-load manifest pages if not provided
		if ( $manifestPages === null ) {
			$manifestPath = $worktreePath . '/manifest.yml';
			if ( !file_exists( $manifestPath ) ) {
				return [
					'success' => false,
					'pack' => $packName,
					'error' => "Manifest not found: {$manifestPath}",
				];
			}

			$manifestContent = file_get_contents( $manifestPath );
			if ( $manifestContent === false ) {
				return [
					'success' => false,
					'pack' => $packName,
					'error' => "Failed to read manifest: {$manifestPath}",
				];
			}

			$manifest = Yaml::parse( $manifestContent );
			$pagesSection = $manifest['pages'] ?? [];
			
			// Convert pages section to flat map of name => file path
			$manifestPages = [];
			foreach ( $pagesSection as $pageName => $pageDef ) {
				if ( is_array( $pageDef ) && isset( $pageDef['file'] ) ) {
					$manifestPages[$pageName] = $pageDef['file'];
				} elseif ( is_string( $pageDef ) ) {
					$manifestPages[$pageName] = $pageDef;
				}
			}
		}

		// Auto-build rewrite map if not provided
		if ( $rewriteMap === null ) {
			$rewriteMap = [];
		}

		// Create/update pack pages in MediaWiki
		$createdPages = $this->importPackPages(
			$pages,
			$worktreePath,
			$rewriteMap,
			$manifestPages,
			$userId
		);

		$successCount = count( array_filter( $createdPages, fn( $p ) => $p['success'] ) );
		$failedCount = count( $createdPages ) - $successCount;

		// Only register pack if at least some pages were created successfully
		// (or if there were no pages to create at all)
		if ( $successCount === 0 && count( $pages ) > 0 ) {
			return [
				'success' => false,
				'pack' => $packName,
				'version' => $version,
				'error' => 'No pages were created successfully',
				'pages_attempted' => count( $pages ),
				'pages_failed' => $failedCount,
			];
		}

		// Register pack in database
		$packId = $this->packRegistry->registerPack( $refId, $packName, $version, $userId );

		// Remove old pages for this pack (if updating)
		$this->pageRegistry->removePagesByPack( $packId );

		// Register successfully created pages
		foreach ( $createdPages as $pageResult ) {
			if ( !$pageResult['success'] ) {
				continue;
			}

			$this->pageRegistry->addPage( $packId, [
				'name' => $pageResult['name'],
				'final_title' => $pageResult['final_title'],
				'page_namespace' => $pageResult['page_namespace'],
				'wiki_page_id' => $pageResult['wiki_page_id'],
				'content_hash' => $pageResult['content_hash'] ?? null,
			] );
		}

		// Store pack dependencies as they were at install time
		$this->storePackDependencies( $refId, $packId, $packName, $worktreePath );

		wfDebugLog( 'labkipack', "Pack {$packName} installed: {$successCount} pages created, {$failedCount} failed" );

		return [
			'success' => true,
			'pack' => $packName,
			'version' => $version,
			'pack_id' => $packId->toInt(),
			'pages_created' => $successCount,
			'pages_failed' => $failedCount,
			'pages' => $createdPages,
		];
	}

	/**
	 * Import pages for a pack into MediaWiki.
	 *
	 * @param array $pages Array of page definitions
	 * @param string $worktreePath Path to git worktree
	 * @param array $rewriteMap Link rewrite map
	 * @param array $manifestPages Map of page name to file path
	 * @param int $userId User ID performing the import
	 * @return array Results for each page
	 */
	private function importPackPages(
		array $pages,
		string $worktreePath,
		array $rewriteMap,
		array $manifestPages,
		int $userId
	): array {
		$results = [];

		foreach ( $pages as $pageDef ) {
			$finalTitle = $pageDef['finalTitle'] ?? $pageDef['final_title'] ?? '';
			$sourceName = $pageDef['original'] ?? $pageDef['name'] ?? '';

			if ( $finalTitle === '' || $sourceName === '' ) {
				wfDebugLog( 'labkipack', "Skipping page with missing title or name" );
				$results[] = [
					'success' => false,
					'name' => $sourceName,
					'error' => 'Missing final title or source name',
				];
				continue;
			}

			// Look up file path from manifest
			$relPath = $manifestPages[$sourceName] ?? null;
			if ( !$relPath ) {
				wfDebugLog( 'labkipack', "No file path found for page: {$sourceName}" );
				$results[] = [
					'success' => false,
					'name' => $sourceName,
					'final_title' => $finalTitle,
					'error' => 'File path not found in manifest',
				];
				continue;
			}

			// Read file from worktree
			$fullPath = $worktreePath . '/' . ltrim( $relPath, '/' );
			$wikitext = $this->readFileFromWorktree( $fullPath );

			if ( $wikitext === null ) {
				wfDebugLog( 'labkipack', "Failed to read file: {$fullPath}" );
				$results[] = [
					'success' => false,
					'name' => $sourceName,
					'final_title' => $finalTitle,
					'error' => "Failed to read file: {$relPath}",
				];
				continue;
			}

			// Rewrite internal links
			$updatedText = $this->rewriteLinks( $wikitext, $rewriteMap );

			// Create MediaWiki page
			$pageResult = $this->createOrUpdatePage( $finalTitle, $updatedText, $userId );

			$results[] = [
				'success' => $pageResult['success'],
				'name' => $sourceName,
				'final_title' => $finalTitle,
				'page_namespace' => $pageResult['namespace'] ?? 0,
				'wiki_page_id' => $pageResult['page_id'] ?? null,
				'content_hash' => md5( $updatedText ),
				'error' => $pageResult['error'] ?? null,
			];
		}

		return $results;
	}

	/**
	 * Update an existing pack.
	 *
	 * @param PackId $packId Pack ID to update
	 * @param ContentRefId $refId Content ref ID (for fetching new content)
	 * @param array $packDef Updated pack definition
	 * @param int $userId User ID performing the update
	 * @return array Update result
	 */
	public function updatePack( PackId $packId, ContentRefId $refId, array $packDef, int $userId ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::updatePack() called for packId={$packId->toInt()}" );

		// Get existing pack
		$existingPack = $this->packRegistry->getPack( $packId );
		if ( !$existingPack ) {
			return [
				'success' => false,
				'error' => "Pack not found: {$packId->toInt()}",
			];
		}

		// Get ref and worktree
		$ref = $this->refRegistry->getRefById( $refId );
		if ( !$ref ) {
			return [
				'success' => false,
				'error' => "Ref not found: {$refId->toInt()}",
			];
		}

		$worktreePath = $ref->worktreePath();
		if ( !$worktreePath || !is_dir( $worktreePath ) ) {
			return [
				'success' => false,
				'error' => "Worktree not found: {$worktreePath}",
			];
		}

		// Build rewrite map
		$rewriteMap = $this->buildRewriteMap( $refId, [ $packDef ] );
		$manifestPages = $this->getManifestPagesFromWorktree( $worktreePath );

		// Update pages
		$pages = $packDef['pages'] ?? [];
		$updatedPages = $this->importPackPages(
			$pages,
			$worktreePath,
			$rewriteMap,
			$manifestPages,
			$userId
		);

		$successCount = count( array_filter( $updatedPages, fn( $p ) => $p['success'] ) );
		$failedCount = count( $updatedPages ) - $successCount;

		// Update pack version if provided
		$newVersion = $packDef['version'] ?? null;
		if ( $newVersion && $newVersion !== $existingPack->version() ) {
			$this->packRegistry->updatePack( $packId, [
				'version' => $newVersion,
				'source_commit' => $packDef['source_commit'] ?? null,
			] );
		}

		// Update page registry
		$this->pageRegistry->removePagesByPack( $packId );
		foreach ( $updatedPages as $pageResult ) {
			if ( !$pageResult['success'] ) {
				continue;
			}

			$this->pageRegistry->addPage( $packId, [
				'name' => $pageResult['name'],
				'final_title' => $pageResult['final_title'],
				'page_namespace' => $pageResult['page_namespace'],
				'wiki_page_id' => $pageResult['wiki_page_id'],
				'content_hash' => $pageResult['content_hash'] ?? null,
			] );
		}

		// Update pack dependencies (they may have changed in the new version)
		$this->storePackDependencies( $refId, $packId, $existingPack->name(), $worktreePath );

		return [
			'success' => true,
			'pack_id' => $packId->toInt(),
			'pack' => $existingPack->name(),
			'old_version' => $existingPack->version(),
			'new_version' => $newVersion,
			'pages_updated' => $successCount,
			'pages_failed' => $failedCount,
			'pages' => $updatedPages,
		];
	}

	/**
	 * Remove a pack and optionally its pages.
	 *
	 * @param PackId $packId Pack ID to remove
	 * @param bool $removePages Whether to delete the MediaWiki pages
	 * @param int $userId User ID performing the removal
	 * @return array Removal result
	 */
	public function removePack( PackId $packId, bool $removePages, int $userId ): array {
		wfDebugLog( 'labkipack', "LabkiPackManager::removePack() called for packId={$packId->toInt()}, removePages={$removePages}" );

		// Get pack info
		$pack = $this->packRegistry->getPack( $packId );
		if ( !$pack ) {
			return [
				'success' => false,
				'error' => "Pack not found: {$packId->toInt()}",
			];
		}

		$packName = $pack->name();

		// Get all pages for this pack
		$pages = $this->pageRegistry->listPagesByPack( $packId );
		$pageCount = count( $pages );

		$deletedPages = 0;
		$failedPages = 0;

		// Delete MediaWiki pages if requested
		if ( $removePages ) {
			foreach ( $pages as $page ) {
				$deleted = $this->deleteMediaWikiPage( $page->finalTitle(), $userId );
				if ( $deleted ) {
					$deletedPages++;
				} else {
					$failedPages++;
				}
			}
		}

		// Remove pages from page registry
		$this->pageRegistry->removePagesByPack( $packId );

		// Remove pack from pack registry
		$this->packRegistry->removePack( $packId );

		wfDebugLog( 'labkipack', "Pack {$packName} removed: {$deletedPages} pages deleted, {$failedPages} failed" );

		return [
			'success' => true,
			'pack_id' => $packId->toInt(),
			'pack' => $packName,
			'pages_total' => $pageCount,
			'pages_deleted' => $deletedPages,
			'pages_failed' => $failedPages,
			'pages_removed_from_registry' => $pageCount,
		];
	}

	/**
	 * Build a link rewrite map for pack installation.
	 *
	 * Combines existing pages in the ref with new pages being installed.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param array $incomingPacks Array of pack definitions
	 * @return array Map of original name => final title
	 */
	private function buildRewriteMap( ContentRefId $refId, array $incomingPacks ): array {
		// Get existing pages for this ref
		$existingMap = [];
		$packs = $this->packRegistry->listPacksByRef( $refId );

		foreach ( $packs as $pack ) {
			$pages = $this->pageRegistry->listPagesByPack( $pack->id() );
			foreach ( $pages as $page ) {
				$existingMap[$page->name()] = $page->finalTitle();
			}
		}

		// Add incoming pages
		$map = $existingMap;
		foreach ( $incomingPacks as $packDef ) {
			foreach ( $packDef['pages'] ?? [] as $pageDef ) {
				$orig = $pageDef['original'] ?? $pageDef['name'] ?? '';
				$final = $pageDef['finalTitle'] ?? $pageDef['final_title'] ?? '';
				if ( $orig !== '' && $final !== '' ) {
					$map[$orig] = $final;
				}
			}
		}

		return $map;
	}

	/**
	 * Get manifest pages from a git worktree.
	 *
	 * Reads the manifest.yml file and extracts page name => file path mappings.
	 *
	 * @param string $worktreePath Path to git worktree
	 * @return array Map of page name => relative file path
	 */
	private function getManifestPagesFromWorktree( string $worktreePath ): array {
		$manifestPath = $worktreePath . '/manifest.yml';

		if ( !file_exists( $manifestPath ) ) {
			wfDebugLog( 'labkipack', "Manifest not found: {$manifestPath}" );
			return [];
		}

		$manifestContent = file_get_contents( $manifestPath );
		if ( $manifestContent === false ) {
			wfDebugLog( 'labkipack', "Failed to read manifest: {$manifestPath}" );
			return [];
		}

		// Parse YAML
		try {
			$manifest = Yaml::parse( $manifestContent );
			if ( !is_array( $manifest ) ) {
				wfDebugLog( 'labkipack', "Invalid manifest format" );
				return [];
			}
		} catch ( \Exception $e ) {
			wfDebugLog( 'labkipack', "Failed to parse manifest: " . $e->getMessage() );
			return [];
		}

		// Extract pages
		$pages = $manifest['pages'] ?? [];
		if ( !is_array( $pages ) ) {
			return [];
		}

		$map = [];
		foreach ( $pages as $name => $info ) {
			if ( !is_array( $info ) ) {
				continue;
			}

			$path = $info['file'] ?? null;
			if ( $path ) {
				$map[$name] = $path;
			}
		}

		wfDebugLog( 'labkipack', "Loaded " . count( $map ) . " pages from manifest" );
		return $map;
	}

	/**
	 * Read a file from the git worktree.
	 *
	 * @param string $fullPath Full path to file
	 * @return string|null File contents, or null on failure
	 */
	private function readFileFromWorktree( string $fullPath ): ?string {
		if ( !file_exists( $fullPath ) ) {
			wfDebugLog( 'labkipack', "File not found: {$fullPath}" );
			return null;
		}

		$content = file_get_contents( $fullPath );
		if ( $content === false ) {
			wfDebugLog( 'labkipack', "Failed to read file: {$fullPath}" );
			return null;
		}

		// Remove BOM if present
		$clean = preg_replace( '/^\xEF\xBB\xBF/', '', $content );

		return $clean;
	}

	/**
	 * Rewrite internal links in wikitext.
	 *
	 * Replaces links and transclusions using the rewrite map.
	 *
	 * @param string $text Original wikitext
	 * @param array $map Rewrite map (original name => final title)
	 * @return string Updated wikitext
	 */
	private function rewriteLinks( string $text, array $map ): string {
		foreach ( $map as $orig => $final ) {
			if ( $orig === $final ) {
				continue;
			}

			// Match either exact or underscore variant of the original
			$escapedOrig = preg_quote( $orig, '/' );
			$escapedAlt = preg_quote( str_replace( ' ', '_', $orig ), '/' );

			// Rewrite [[links]]
			$pattern = '/\[\[(?:' . $escapedOrig . '|' . $escapedAlt . ')(\|[^]]*)?\]\]/u';
			$text = preg_replace( $pattern, '[[' . $final . '$1]]', $text );

			// Rewrite {{transclusions}}
			$patternTmpl = '/\{\{(?:' . $escapedOrig . '|' . $escapedAlt . ')(\|[^}]*)?\}\}/u';
			$text = preg_replace( $patternTmpl, '{{' . $final . '$1}}', $text );
		}

		return $text;
	}

	/**
	 * Create or update a MediaWiki page.
	 *
	 * @param string $titleText Page title
	 * @param string $wikitext Page content
	 * @param int $userId User ID performing the edit
	 * @return array Result with success status and page info
	 */
	private function createOrUpdatePage( string $titleText, string $wikitext, int $userId ): array {
		$title = Title::newFromText( $titleText );
		if ( !$title ) {
			wfDebugLog( 'labkipack', "Invalid title: {$titleText}" );
			return [
				'success' => false,
				'error' => "Invalid title: {$titleText}",
			];
		}

		$content = new WikitextContent( $wikitext );
		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		$page = $wikiPageFactory->newFromTitle( $title );

		// Get user for edit
		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $userId );
		if ( !$user || !$user->isRegistered() ) {
			wfDebugLog( 'labkipack', "Invalid user ID: {$userId}" );
			return [
				'success' => false,
				'error' => "Invalid user ID: {$userId}",
			];
		}

		// Create page updater
		$comment = CommentStoreComment::newUnsavedComment( 'Imported via LabkiPackManager' );
		$pageUpdater = $page->newPageUpdater( $user );
		$pageUpdater->setContent( SlotRecord::MAIN, $content );
		$pageUpdater->saveRevision( $comment );
		$status = $pageUpdater->getStatus();

		if ( !$status || !$status->isOK() ) {
			$errorMsg = $status ? Status::wrap( $status )->getWikiText() : 'Unknown error';
			wfDebugLog( 'labkipack', "Failed to save page {$titleText}: {$errorMsg}" );
			return [
				'success' => false,
				'error' => "Failed to save page: {$errorMsg}",
			];
		}

		wfDebugLog( 'labkipack', "Successfully created/updated page: {$titleText}" );

		return [
			'success' => true,
			'page_id' => $page->getId(),
			'namespace' => $title->getNamespace(),
		];
	}

	/**
	 * Delete a MediaWiki page.
	 *
	 * @param string $titleText Page title
	 * @param int $userId User ID performing the deletion
	 * @return bool True if deleted successfully
	 */
	private function deleteMediaWikiPage( string $titleText, int $userId ): bool {
		$title = Title::newFromText( $titleText );
		if ( !$title || !$title->exists() ) {
			wfDebugLog( 'labkipack', "Page not found for deletion: {$titleText}" );
			return false;
		}

		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		$page = $wikiPageFactory->newFromTitle( $title );

		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $userId );
		if ( !$user || !$user->isRegistered() ) {
			wfDebugLog( 'labkipack', "Invalid user ID for deletion: {$userId}" );
			return false;
		}

		// Delete the page
		$reason = 'Removed via LabkiPackManager';
		$status = $page->doDeleteArticleReal( $reason, $user );

		if ( !$status || !$status->isOK() ) {
			$errorMsg = $status ? Status::wrap( $status )->getWikiText() : 'Unknown error';
			wfDebugLog( 'labkipack', "Failed to delete page {$titleText}: {$errorMsg}" );
			return false;
		}

		wfDebugLog( 'labkipack', "Successfully deleted page: {$titleText}" );
		return true;
	}

	/**
	 * Store pack dependencies as they were at install time.
	 * Reads the manifest to get the depends_on list and resolves pack names to IDs.
	 *
	 * @param ContentRefId $refId Content ref ID
	 * @param PackId $packId The pack being installed
	 * @param string $packName Name of the pack being installed
	 * @param string $worktreePath Path to git worktree
	 */
	private function storePackDependencies( ContentRefId $refId, PackId $packId, string $packName, string $worktreePath ): void {
		wfDebugLog( 'labkipack', "Storing dependencies for pack {$packName}" );

		// First, remove any existing dependencies for this pack (in case of update)
		$this->packRegistry->removeDependencies( $packId );

		// Read manifest to get depends_on for this pack
		$manifestPath = $worktreePath . '/manifest.yml';
		if ( !file_exists( $manifestPath ) ) {
			wfDebugLog( 'labkipack', "Manifest not found, no dependencies to store: {$manifestPath}" );
			return;
		}

		$manifestContent = file_get_contents( $manifestPath );
		if ( $manifestContent === false ) {
			wfDebugLog( 'labkipack', "Failed to read manifest: {$manifestPath}" );
			return;
		}

		try {
			$manifest = Yaml::parse( $manifestContent );
			if ( !is_array( $manifest ) ) {
				return;
			}
		} catch ( \Exception $e ) {
			wfDebugLog( 'labkipack', "Failed to parse manifest: " . $e->getMessage() );
			return;
		}

		$packs = $manifest['packs'] ?? [];
		if ( !is_array( $packs ) || !isset( $packs[$packName] ) ) {
			wfDebugLog( 'labkipack', "Pack {$packName} not found in manifest" );
			return;
		}

		$packDef = $packs[$packName];
		$dependsOn = $packDef['depends_on'] ?? [];

		if ( !is_array( $dependsOn ) || empty( $dependsOn ) ) {
			wfDebugLog( 'labkipack', "Pack {$packName} has no dependencies" );
			return;
		}

		wfDebugLog( 'labkipack', "Pack {$packName} depends on: " . implode( ', ', $dependsOn ) );

		// Resolve dependency pack names to pack IDs
		$dependencyPackIds = [];
		foreach ( $dependsOn as $depPackName ) {
			$depPackId = $this->packRegistry->getPackIdByName( $refId, $depPackName );
			if ( $depPackId === null ) {
				wfDebugLog( 'labkipack', "Warning: Dependency pack {$depPackName} not found for pack {$packName}" );
				continue;
			}
			$dependencyPackIds[] = $depPackId;
		}

		if ( empty( $dependencyPackIds ) ) {
			wfDebugLog( 'labkipack', "No valid dependency pack IDs found for pack {$packName}" );
			return;
		}

		// Store dependencies
		$this->packRegistry->storeDependencies( $packId, $dependencyPackIds );
		wfDebugLog( 'labkipack', "Stored " . count( $dependencyPackIds ) . " dependencies for pack {$packName}" );
	}
}

