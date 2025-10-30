<?php

declare(strict_types=1);

namespace LabkiPackManager\API\Packs;

use LabkiPackManager\API\LabkiApiBase;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;

/**
 * Base class for pack-related API endpoints.
 *
 * Extends LabkiApiBase with pack-specific functionality:
 * - Pack and page registry accessors
 * - Repository and ref registry accessors
 * - Identifier resolution helpers (repo, ref, pack)
 *
 * Subclasses must implement execute().
 *
 * @ingroup API
 */
abstract class PackApiBase extends LabkiApiBase {

	/**
	 * Get the pack registry.
	 *
	 * @return LabkiPackRegistry
	 */
	protected function getPackRegistry(): LabkiPackRegistry {
		return new LabkiPackRegistry();
	}

	/**
	 * Get the page registry.
	 *
	 * @return LabkiPageRegistry
	 */
	protected function getPageRegistry(): LabkiPageRegistry {
		return new LabkiPageRegistry();
	}

	/**
	 * Get the repository registry.
	 *
	 * @return LabkiRepoRegistry
	 */
	protected function getRepoRegistry(): LabkiRepoRegistry {
		return new LabkiRepoRegistry();
	}

	/**
	 * Get the ref registry.
	 *
	 * @return LabkiRefRegistry
	 */
	protected function getRefRegistry(): LabkiRefRegistry {
		return new LabkiRefRegistry();
	}

	/**
	 * Resolve a repository identifier (ID or URL) to a ContentRepoId.
	 *
	 * @param int|string $identifier Repository ID or URL
	 * @return ContentRepoId|null Repository ID, or null if not found
	 */
	protected function resolveRepoId( int|string $identifier ): ?ContentRepoId {
		$repoRegistry = $this->getRepoRegistry();

		if ( is_int( $identifier ) ) {
			// Verify the ID exists
			$repo = $repoRegistry->getRepo( $identifier );
			return $repo ? $repo->id() : null;
		}

		// String - treat as URL
		$normalizedUrl = $this->resolveRepoUrl( $identifier );
		$repo = $repoRegistry->getRepo( $normalizedUrl );
		return $repo ? $repo->id() : null;
	}

	/**
	 * Resolve a ref identifier (ID or name) to a ContentRefId.
	 *
	 * @param ContentRepoId $repoId Parent repository ID
	 * @param int|string $refIdentifier Ref ID or ref name
	 * @return ContentRefId|null Ref ID, or null if not found
	 */
	protected function resolveRefId( ContentRepoId $repoId, int|string $refIdentifier ): ?ContentRefId {
		$refRegistry = $this->getRefRegistry();

		if ( is_int( $refIdentifier ) ) {
			// Verify the ID exists and belongs to the repo
			$ref = $refRegistry->getRefById( $refIdentifier );
			if ( $ref && $ref->contentRepoId()->toInt() === $repoId->toInt() ) {
				return $ref->id();
			}
			return null;
		}

		// String - treat as ref name
		return $refRegistry->getRefIdByRepoAndRef( $repoId, $refIdentifier );
	}
}
