<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\ContentRef;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use MediaWiki\MediaWikiServices;
use LabkiPackManager\Services\LabkiRepoRegistry;
use Wikimedia\Rdbms\IDatabase;

/**
 * LabkiRefRegistry
 *
 * Ref-level registry service for the labki_content_ref table.
 *
 * This service manages Git reference metadata (branches, tags, commits) for
 * content repositories. Each entry corresponds to a specific ref within a
 * repository, storing worktree paths, commit hashes, and manifest metadata.
 *
 * Responsibilities:
 * - Creating and updating ref entries for repositories
 * - Tracking ref metadata (worktree path, last commit, manifest hash)
 * - Querying refs by repository and ref name
 * - Listing all refs for a repository
 * - Deleting refs (cascades to packs and pages)
 * - Resolving repository identifiers (ID, URL, or ContentRepoId)
 *
 * Related tables:
 * - labki_content_repo: Parent repository (managed by LabkiRepoRegistry)
 * - labki_pack: Pack metadata (managed by LabkiPackRegistry)
 * - labki_page: Page metadata (managed by LabkiPageRegistry)
 *
 * Note: Not marked as final to allow mocking in unit tests.
 */
class LabkiRefRegistry {
    private const TABLE = 'labki_content_ref';

    private LabkiRepoRegistry $repoRegistry;

    public function __construct(?LabkiRepoRegistry $repoRegistry = null) {
        $this->repoRegistry = $repoRegistry ?? new LabkiRepoRegistry();
    }

    /**
     * Convert timestamp fields to database format.
     * @param IDatabase $dbw Database connection
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function convertTimestamps( IDatabase $dbw, array $fields ): array {
        $timestampFields = [ 'created_at', 'updated_at', 'manifest_last_parsed' ];
        foreach ( $timestampFields as $field ) {
            if ( isset( $fields[$field] ) && $fields[$field] !== null ) {
                $fields[$field] = $dbw->timestamp( $fields[$field] );
            }
        }
        return $fields;
    }

    /**
     * Ensure a ref entry exists (create or update) for a given repo + ref.
     *
     * If a ref with the given repository and ref name already exists, it will be
     * updated with the provided extra fields. Otherwise, a new ref entry is created.
     *
     * This is the recommended method for most use cases as it provides idempotent
     * behavior - calling it multiple times with the same repo/ref is safe.
     *
     * @param int|string|ContentRepoId $contentRepoIdentifier Repository ID, URL, or ContentRepoId
     * @param string $sourceRef Branch, tag, or commit name (e.g., 'main', 'v1.0.0')
     * @param array<string,mixed> $extraFields Optional metadata (worktree_path, last_commit, etc.)
     * @return ContentRefId The ref ID (existing or newly created)
     */
    public function ensureRefEntry(
        int|string|ContentRepoId $contentRepoIdentifier,
        string $sourceRef,
        array $extraFields = []
    ): ContentRefId {

        $repoId = $this->resolveRepoId($contentRepoIdentifier);

        wfDebugLog('labkipack', "ensureRefEntry() called for repo={$repoId} ref={$sourceRef}");

        // Check if ref already exists
        $existingId = $this->getRefIdByRepoAndRef($repoId, $sourceRef);
        if ($existingId !== null) {
            wfDebugLog('labkipack', "ensureRefEntry(): found existing ref (ID={$existingId->toInt()}) → updating");
            $this->updateRefEntry($existingId, $extraFields);
            return $existingId;
        }

        // Otherwise create new entry
        return $this->addRefEntry($repoId, $sourceRef, $extraFields);
    }

    /**
     * Insert a new ref entry.
     *
     * Creates a new ref record with default values for required fields.
     * Use ensureRefEntry() instead if you want idempotent behavior.
     *
     * Default values:
     * - last_commit: null
     * - manifest_hash: null
     * - manifest_last_parsed: null
     * - worktree_path: null
     * - created_at: current timestamp
     * - updated_at: current timestamp
     *
     * @param int $repoId Parent repository ID
     * @param string $sourceRef Branch, tag, or commit name
     * @param array<string,mixed> $extraFields Optional metadata to override defaults
     * @return ContentRefId The newly created ref ID
     * @throws \Exception If database insertion fails
     */
    public function addRefEntry(
        int $repoId,
        string $sourceRef,
        array $extraFields = []
    ): ContentRefId {
        wfDebugLog('labkipack', "addRefEntry() inserting repo={$repoId} ref={$sourceRef}");

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        $now = \wfTimestampNow();

        $row = array_merge([
            'content_repo_id'      => $repoId,
            'source_ref'           => $sourceRef,
            'last_commit'          => null,
            'manifest_hash'        => null,
            'manifest_last_parsed' => null,
            'worktree_path'        => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ], $extraFields);
        
        // Convert timestamps to DB format
        $row = $this->convertTimestamps( $dbw, $row );

        try {
            $dbw->newInsertQueryBuilder()
                ->insertInto(self::TABLE)
                ->row($row)
                ->caller(__METHOD__)
                ->execute();

            $newId = (int) $dbw->insertId();
            wfDebugLog('labkipack', "addRefEntry(): created new ref (ID={$newId}) for repo={$repoId}@{$sourceRef}");
            return new ContentRefId($newId);

        } catch (\Exception $e) {
            wfDebugLog('labkipack', "addRefEntry() failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update an existing ref record by ID.
     *
     * @param int|ContentRefId $refId Ref ID
     * @param array<string,mixed> $fields Fields to update
     */
    public function updateRefEntry(int|ContentRefId $refId, array $fields): void {
        if (empty($fields)) {
            return; // nothing to update
        }

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        $id = $refId instanceof ContentRefId ? $refId->toInt() : (int) $refId;

        $fields['updated_at'] = $fields['updated_at'] ?? \wfTimestampNow();
        
        // Convert timestamps to DB format
        $fields = $this->convertTimestamps( $dbw, $fields );

        $dbw->newUpdateQueryBuilder()
            ->update(self::TABLE)
            ->set($fields)
            ->where(['content_ref_id' => $id])
            ->caller(__METHOD__)
            ->execute();

        wfDebugLog('labkipack', "updateRefEntry(): updated ref ID={$id} with fields: " . json_encode(array_keys($fields)));
    }

    /**
     * Get a ref ID by (repo_id, source_ref) pair.
     *
     * Performs an exact match lookup on the repository ID and ref name.
     *
     * @param int|string|ContentRepoId $contentRepoIdentifier Repository ID, URL, or ContentRepoId
     * @param string $sourceRef Branch, tag, or commit name to look up
     * @return ContentRefId|null Ref ID if found, null otherwise
     */
    public function getRefIdByRepoAndRef(int|string|ContentRepoId $contentRepoIdentifier, string $sourceRef): ?ContentRefId {
        $repoId = $this->resolveRepoId($contentRepoIdentifier);
        wfDebugLog('labkipack', "getRefIdByRepoAndRef() repoId={$repoId} ref={$sourceRef}");

        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $row = $dbr->newSelectQueryBuilder()
            ->select('content_ref_id')
            ->from(self::TABLE)
            ->where([
                'content_repo_id' => $repoId,
                'source_ref' => $sourceRef,
            ])
            ->caller(__METHOD__)
            ->fetchRow();
        
        if ($row) {
            wfDebugLog('labkipack', "getRefIdByRepoAndRef() returning refId={$row->content_ref_id}");
            return new ContentRefId((int) $row->content_ref_id);
        }
        
        wfDebugLog('labkipack', "getRefIdByRepoAndRef() ref not found for repoId={$repoId} ref={$sourceRef}");
        return null;
    }

    /**
     * Fetch a ref record by ID.
     */
    public function getRefById(int|ContentRefId $refId): ?ContentRef {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $id = $refId instanceof ContentRefId ? $refId->toInt() : (int) $refId;

        $row = $dbr->newSelectQueryBuilder()
            ->select(ContentRef::FIELDS)
            ->from(self::TABLE)
            ->where(['content_ref_id' => $id])
            ->caller(__METHOD__)
            ->fetchRow();

        return $row ? ContentRef::fromRow($row) : null;
    }

    /**
     * List all refs for a given repository.
     *
     * @param int|string|ContentRepoId|ContentRepo $identifier Parent repository ID, URL, or ContentRepo object
     * @return array<int,ContentRef>
     */
    public function listRefsForRepo(int|string|ContentRepoId|ContentRepo $identifier): array {
        $repoId = $this->resolveRepoId($identifier);

        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $res = $dbr->newSelectQueryBuilder()
            ->select(ContentRef::FIELDS)
            ->from(self::TABLE)
            ->where(['content_repo_id' => $repoId])
            ->orderBy('source_ref')
            ->caller(__METHOD__)
            ->fetchResultSet();

        $out = [];
        foreach ($res as $row) {
            $out[] = ContentRef::fromRow($row);
        }

        return $out;
    }

    /**
     * Delete a ref entry (cascade removes packs/pages under it).
     */
    public function deleteRef(int|ContentRefId $refId): void {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        $id = $refId instanceof ContentRefId ? $refId->toInt() : (int) $refId;

        $dbw->newDeleteQueryBuilder()
            ->deleteFrom(self::TABLE)
            ->where(['content_ref_id' => $id])
            ->caller(__METHOD__)
            ->execute();

        wfDebugLog('labkipack', "deleteRef(): deleted ref ID={$id}");
    }

    public function getWorktreePath(int|string|ContentRepoId $contentRepoIdentifier, string $ref): string {
        $repoId = $this->resolveRepoId($contentRepoIdentifier);
        $refId = $this->getRefIdByRepoAndRef($repoId, $ref);
        wfDebugLog('labkipack', "getWorktreePath() called for repo={$repoId} ref={$ref} refId={$refId->toInt()}");
        return $this->getRefById($refId)->worktreePath();
    }

    /**
     * Normalize a repository identifier to its integer ID.
     *
     * Accepts:
     *   - int: treated as content_repo_id
     *   - ContentRepoId: converted via ->toInt()
     *   - string: treated as repo URL (resolved through LabkiRepoRegistry)
     *
     * @param int|string|ContentRepoId $contentRepoIdentifier
     * @return int Repo ID
     * @throws RuntimeException if repo cannot be resolved
     */
    private function resolveRepoId(int|string|ContentRepoId|ContentRepo $identifier): int {
        $repoId = match(true) {
            $identifier instanceof ContentRepo => $identifier->id()->toInt(),
            $identifier instanceof ContentRepoId => $identifier->toInt(),
            is_int($identifier) => $identifier,
            is_string($identifier) => $this->repoRegistry->getRepoId($identifier)->toInt(),
        };
        if ($repoId === null) {
            throw new \RuntimeException("Repository not found for identifier: {$identifier}");
        }
        return $repoId;
    }

}
