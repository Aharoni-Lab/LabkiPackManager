<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use MediaWiki\MediaWikiServices;
use RuntimeException;

/**
 * Repository-level registry service for labki_content_repo table.
 *
 * Each entry corresponds to one bare Git repository (unique per content_repo_url).
 * Per-branch/tag metadata is stored in labki_content_ref via LabkiRefRegistry.
 */
final class LabkiRepoRegistry {
    private const TABLE = 'labki_content_repo';

    /**
     * Ensure a bare repository entry exists (create or update) and return its ID.
     *
     * @param string $contentRepoUrl Repository URL
     * @param array<string,mixed> $extraFields Optional metadata (e.g. name, bare_path, last_fetched)
     * @return ContentRepoId
     */
    public function ensureRepoEntry(
        string $contentRepoUrl,
        array $extraFields = []
    ): ContentRepoId {
        $now = \wfTimestampNow();

        wfDebugLog('labkipack', "ensureRepoEntry() called for {$contentRepoUrl}");

        // Check if repo already exists
        $existingId = $this->getRepoIdByUrl($contentRepoUrl);
        if ($existingId !== null) {
            wfDebugLog('labkipack', "ensureRepoEntry(): found existing repo (ID={$existingId->toInt()}) → updating");
            $this->updateRepoEntry($existingId, $extraFields);
            return $existingId;
        }

        // Otherwise create new entry
        return $this->addRepoEntry($contentRepoUrl, $extraFields);
    }

    /**
     * Insert a repository entry.
     *
     * @param string $contentRepoUrl Repository URL
     * @param array<string,mixed> $extraFields Optional metadata
     * @return ContentRepoId
     */
    public function addRepoEntry(
        string $contentRepoUrl,
        array $extraFields = []
    ): ContentRepoId {
        $now = \wfTimestampNow();

        wfDebugLog('labkipack', "addRepoEntry() inserting {$contentRepoUrl}");

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);

        $row = array_merge([
            'content_repo_url'  => $contentRepoUrl,
            'default_ref'       => 'main',
            'bare_path'         => null,
            'last_fetched'      => null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ], $extraFields);

        try {
            $dbw->newInsertQueryBuilder()
                ->insertInto(self::TABLE)
                ->row($row)
                ->caller(__METHOD__)
                ->execute();

            $newId = (int)$dbw->insertId();
            wfDebugLog('labkipack', "addRepoEntry(): created new repo entry (ID={$newId}) for {$contentRepoUrl}");
            return new ContentRepoId($newId);

        } catch (\Exception $e) {
            wfDebugLog('labkipack', "addRepoEntry() failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update an existing repository record by ID.
     */
    public function updateRepoEntry(int|ContentRepoId $repoId, array $fields): void {
        if (empty($fields)) {
            return; // nothing to update
        }

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        $id = $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId;

        $fields['updated_at'] = $fields['updated_at'] ?? \wfTimestampNow();

        $dbw->newUpdateQueryBuilder()
            ->update(self::TABLE)
            ->set($fields)
            ->where(['content_repo_id' => $id])
            ->caller(__METHOD__)
            ->execute();

        wfDebugLog('labkipack', "updateRepoEntry(): updated repo ID={$id} with fields: " . json_encode(array_keys($fields)));
    }

    /**
     * Get a repository ID by its canonical URL.
     */
    public function getRepoIdByUrl(string $contentRepoUrl): ?ContentRepoId {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);

        $row = $dbr->newSelectQueryBuilder()
            ->select('content_repo_id')
            ->from(self::TABLE)
            ->where(['content_repo_url' => $contentRepoUrl])
            ->caller(__METHOD__)
            ->fetchRow();

        return $row ? new ContentRepoId((int)$row->content_repo_id) : null;
    }

    /**
     * Fetch a repository record by ID.
     */
    public function getRepoById(int|ContentRepoId $repoId): ?ContentRepo {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $row = $dbr->newSelectQueryBuilder()
            ->select(ContentRepo::FIELDS)
            ->from(self::TABLE)
            ->where([
                'content_repo_id' => $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId,
            ])
            ->caller(__METHOD__)
            ->fetchRow();

        return $row ? ContentRepo::fromRow($row) : null;
    }

    /**
     * List all repositories.
     *
     * @return array<int,ContentRepo>
     */
    public function listRepos(): array {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $res = $dbr->newSelectQueryBuilder()
            ->select(ContentRepo::FIELDS)
            ->from(self::TABLE)
            ->orderBy('content_repo_id')
            ->caller(__METHOD__)
            ->fetchResultSet();

        $out = [];
        foreach ($res as $row) {
            $out[] = ContentRepo::fromRow($row);
        }
        return $out;
    }

    /**
     * Delete a repository (cascade removes refs, packs, pages).
     */
    public function deleteRepo(int|ContentRepoId $repoId): void {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
        $id = $repoId instanceof ContentRepoId ? $repoId->toInt() : $repoId;

        $dbw->newDeleteQueryBuilder()
            ->deleteFrom(self::TABLE)
            ->where(['content_repo_id' => $id])
            ->caller(__METHOD__)
            ->execute();

        wfDebugLog('labkipack', "deleteRepo(): deleted repo ID={$id}");
    }

}
