<?php

declare(strict_types=1);

namespace LabkiPackManager\Services;

use LabkiPackManager\Domain\ContentRepo;
use LabkiPackManager\Domain\ContentRepoId;
use MediaWiki\MediaWikiServices;
use RuntimeException;

/**
 * LabkiRepoRegistry
 *
 * Repository-level registry service for the labki_content_repo table.
 *
 * This service manages content repository metadata, where each entry corresponds
 * to one bare Git repository (unique per content_repo_url). It provides CRUD
 * operations and "ensure" semantics for repository entries.
 *
 * Responsibilities:
 * - Creating and updating repository entries
 * - Tracking repository metadata (URL, default ref, bare path, last fetched)
 * - Querying repositories by URL or ID
 * - Listing all repositories
 * - Deleting repositories (cascades to refs, packs, pages)
 *
 * Related tables:
 * - labki_content_ref: Per-branch/tag metadata (managed by LabkiRefRegistry)
 * - labki_pack: Pack metadata (managed by LabkiPackRegistry)
 * - labki_page: Page metadata (managed by LabkiPageRegistry)
 *
 * Note: Not marked as final to allow mocking in unit tests.
 */
class LabkiRepoRegistry {
    private const TABLE = 'labki_content_repo';

    /**
     * Ensure a repository entry exists (create or update) and return its ID.
     *
     * If a repository with the given URL already exists, it will be updated with
     * the provided extra fields. Otherwise, a new repository entry is created.
     *
     * This is the recommended method for most use cases as it provides idempotent
     * behavior - calling it multiple times with the same URL is safe.
     *
     * @param string $contentRepoUrl Repository URL (should be normalized)
     * @param array<string,mixed> $extraFields Optional metadata (e.g., bare_path, last_fetched)
     * @return ContentRepoId The repository ID (existing or newly created)
     */
    public function ensureRepoEntry(
        string $contentRepoUrl,
        array $extraFields = []
    ): ContentRepoId {
        $now = \wfTimestampNow();

        wfDebugLog('labkipack', "ensureRepoEntry() called for {$contentRepoUrl}");

        // Check if repo already exists
        $existingId = $this->getRepoId($contentRepoUrl);
        if ($existingId !== null) {
            wfDebugLog('labkipack', "ensureRepoEntry(): found existing repo (ID={$existingId->toInt()}) → updating");
            $this->updateRepoEntry($existingId, $extraFields);
            return $existingId;
        }

        // Otherwise create new entry
        return $this->addRepoEntry($contentRepoUrl, $extraFields);
    }

    /**
     * Insert a new repository entry.
     *
     * Creates a new repository record with default values for required fields.
     * Use ensureRepoEntry() instead if you want idempotent behavior.
     *
     * Default values:
     * - default_ref: 'main'
     * - bare_path: null
     * - last_fetched: null
     * - created_at: current timestamp
     * - updated_at: current timestamp
     *
     * @param string $contentRepoUrl Repository URL (should be normalized)
     * @param array<string,mixed> $extraFields Optional metadata to override defaults
     * @return ContentRepoId The newly created repository ID
     * @throws \Exception If database insertion fails
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
     *
     * Updates the specified fields for a repository. The updated_at timestamp
     * is automatically set to the current time unless explicitly provided.
     *
     * @param int|ContentRepoId $repoId Repository ID to update
     * @param array<string,mixed> $fields Fields to update (e.g., bare_path, last_fetched)
     * @return void
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
     *
     * Performs an exact match lookup on the content_repo_url field.
     * The URL should be normalized before calling this method.
     *
     * @param string $contentRepoUrl Repository URL to look up
     * @return ContentRepoId|null Repository ID if found, null otherwise
     */
    public function getRepoId(string $contentRepoUrl): ?ContentRepoId {
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
     * Fetch a complete repository record by ID, URL, or ContentRepoId object.
     *
     * This is a flexible lookup method that accepts multiple identifier types:
     * - int: Treated as repository ID
     * - ContentRepoId: Treated as repository ID
     * - string: Treated as repository URL (should be normalized)
     *
     * Examples:
     * ```php
     * $repo = $registry->getRepo(1);                                    // By int ID
     * $repo = $registry->getRepo(new ContentRepoId(1));                 // By ContentRepoId
     * $repo = $registry->getRepo('https://github.com/user/repo');       // By URL
     * ```
     *
     * @param int|ContentRepoId|string $identifier Repository identifier (ID or URL)
     * @return ContentRepo|null Repository object if found, null otherwise
     */
    public function getRepo(int|ContentRepoId|string $identifier): ?ContentRepo {
        // Handle string (URL) - look up ID first
        if (is_string($identifier)) {
            $repoId = $this->getRepoId($identifier);
            if ($repoId === null) {
                return null;
            }
            $identifier = $repoId;
        }

        // Now we have either int or ContentRepoId - fetch the record
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $row = $dbr->newSelectQueryBuilder()
            ->select(ContentRepo::FIELDS)
            ->from(self::TABLE)
            ->where([
                'content_repo_id' => $identifier instanceof ContentRepoId ? $identifier->toInt() : $identifier,
            ])
            ->caller(__METHOD__)
            ->fetchRow();

        return $row ? ContentRepo::fromRow($row) : null;
    }

    /**
     * List all repositories.
     *
     * Returns all repository records ordered by ID.
     *
     * @return array<int,ContentRepo> Array of ContentRepo objects
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
     * Delete a repository entry.
     *
     * Removes the repository record from the database. Due to foreign key constraints,
     * this will cascade delete all associated refs, packs, and pages.
     *
     * Warning: This is a destructive operation. Ensure all associated data should
     * be removed before calling this method.
     *
     * @param int|ContentRepoId $repoId Repository ID to delete
     * @return void
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
