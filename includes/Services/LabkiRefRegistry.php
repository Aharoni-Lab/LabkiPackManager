<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\ContentRef;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\ContentRepoId;
use MediaWiki\MediaWikiServices;

/**
 * Ref-level registry service for labki_content_ref table.
 *
 * Each entry corresponds to a specific branch, tag, or commit (source_ref)
 * within a single content repository (labki_content_repo).
 *
 * This service provides CRUD and "ensure" semantics similar to
 * LabkiRepoRegistry, but scoped to (repo_id, source_ref).
 */
final class LabkiRefRegistry {
    private const TABLE = 'labki_content_ref';

    /**
     * Ensure a ref entry exists (create or update) for a given repo + ref.
     *
     * @param int|ContentRepoId $contentRepoId Parent repository ID
     * @param string $sourceRef Branch, tag, or commit name
     * @param array<string,mixed> $extraFields Optional metadata (manifest path, last commit, etc.)
     * @return ContentRefId
     */
    public function ensureRefEntry(
        int|ContentRepoId $contentRepoId,
        string $sourceRef,
        array $extraFields = []
    ): ContentRefId {
        $repoId = $contentRepoId instanceof ContentRepoId
            ? $contentRepoId->toInt()
            : (int) $contentRepoId;

        $safeRef = trim($sourceRef) !== '' ? trim($sourceRef) : 'main';
        $now = \wfTimestampNow();

        wfDebugLog('labkipack', "ensureRefEntry() called for repo={$repoId} ref={$safeRef}");

        // Check if ref already exists
        $existingId = $this->getRefIdByRepoAndRef($repoId, $safeRef);
        if ($existingId !== null) {
            wfDebugLog('labkipack', "ensureRefEntry(): found existing ref (ID={$existingId->toInt()}) → updating");
            $this->updateRefEntry($existingId, $extraFields);
            return $existingId;
        }

        // Otherwise create new entry
        return $this->addRefEntry($repoId, $safeRef, $extraFields);
    }

    /**
     * Insert a new ref entry.
     *
     * @param int $repoId Parent repository ID
     * @param string $sourceRef Branch, tag, or commit
     * @param array<string,mixed> $extraFields Optional metadata
     * @return ContentRefId
     */
    public function addRefEntry(
        int $repoId,
        string $sourceRef,
        array $extraFields = []
    ): ContentRefId {
        $safeRef = trim($sourceRef) !== '' ? trim($sourceRef) : 'main';
        $now = \wfTimestampNow();

        wfDebugLog('labkipack', "addRefEntry() inserting repo={$repoId} ref={$safeRef}");

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);

        $row = array_merge([
            'content_repo_id'      => $repoId,
            'source_ref'           => $safeRef,
            'last_commit'          => null,
            'manifest_path'        => null,
            'manifest_hash'        => null,
            'manifest_last_parsed' => null,
            'worktree_path'        => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ], $extraFields);

        try {
            $dbw->newInsertQueryBuilder()
                ->insertInto(self::TABLE)
                ->row($row)
                ->caller(__METHOD__)
                ->execute();

            $newId = (int) $dbw->insertId();
            wfDebugLog('labkipack', "addRefEntry(): created new ref (ID={$newId}) for repo={$repoId}@{$safeRef}");
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
     */
    public function getRefIdByRepoAndRef(int $repoId, string $sourceRef): ?ContentRefId {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $safeRef = trim($sourceRef) !== '' ? trim($sourceRef) : 'main';

        $row = $dbr->newSelectQueryBuilder()
            ->select('content_ref_id')
            ->from(self::TABLE)
            ->where([
                'content_repo_id' => $repoId,
                'source_ref' => $safeRef,
            ])
            ->caller(__METHOD__)
            ->fetchRow();

        return $row ? new ContentRefId((int) $row->content_ref_id) : null;
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
     * @param int|ContentRepoId $repoId Parent repository ID
     * @return array<int,ContentRef>
     */
    public function listRefsForRepo(int|ContentRepoId $repoId): array {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $repoIdInt = $repoId instanceof ContentRepoId ? $repoId->toInt() : (int) $repoId;

        $res = $dbr->newSelectQueryBuilder()
            ->select(ContentRef::FIELDS)
            ->from(self::TABLE)
            ->where(['content_repo_id' => $repoIdInt])
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
}
