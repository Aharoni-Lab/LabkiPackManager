<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Domain model representing a content repository.
 *
 * A content repository hosts one or more Packs and is tracked per Git ref.
 * This class mirrors the labki_content_repo schema and stores manifest,
 * versioning, and synchronization metadata.
 */
final class ContentRepo {
    public const TABLE = 'labki_content_repo';

    /** @var string[] Database fields */
    public const FIELDS = [
        'content_repo_id',
        'content_repo_url',
        'content_repo_name',
        'source_ref',
        'manifest_path',
        'manifest_hash',
        'manifest_last_parsed',
        'last_commit',
        'created_at',
        'updated_at',
    ];

    private ContentRepoId $id;
    private string $url;
    private ?string $name;
    private ?string $sourceRef;
    private ?string $manifestPath;
    private ?string $manifestHash;
    private ?int $manifestLastParsed;
    private ?string $lastCommit;
    private ?int $createdAt;
    private ?int $updatedAt;

    public function __construct(
        ContentRepoId $id,
        string $url,
        ?string $name = null,
        ?string $sourceRef = null,
        ?string $manifestPath = null,
        ?string $manifestHash = null,
        ?int $manifestLastParsed = null,
        ?string $lastCommit = null,
        ?int $createdAt = null,
        ?int $updatedAt = null
    ) {
        $this->id = $id;
        $this->url = $url;
        $this->name = $name;
        $this->sourceRef = $sourceRef;
        $this->manifestPath = $manifestPath;
        $this->manifestHash = $manifestHash;
        $this->manifestLastParsed = $manifestLastParsed;
        $this->lastCommit = $lastCommit;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    // ────────────────────────────────────────────────
    // Getters
    // ────────────────────────────────────────────────

    public function id(): ContentRepoId { return $this->id; }
    public function url(): string { return $this->url; }
    public function name(): ?string { return $this->name; }
    public function sourceRef(): ?string { return $this->sourceRef; }
    public function manifestPath(): ?string { return $this->manifestPath; }
    public function manifestHash(): ?string { return $this->manifestHash; }
    public function manifestLastParsed(): ?int { return $this->manifestLastParsed; }
    public function lastCommit(): ?string { return $this->lastCommit; }
    public function createdAt(): ?int { return $this->createdAt; }
    public function updatedAt(): ?int { return $this->updatedAt; }

    // ────────────────────────────────────────────────
    // Derived helpers
    // ────────────────────────────────────────────────

    /**
     * Return a short label, preferring manifest name > repo name > URL base.
     */
    public function displayName(): string {
        if ($this->name) {
            return $this->name;
        }
        $base = basename(parse_url($this->url, PHP_URL_PATH) ?? '');
        return preg_replace('/\.git$/', '', $base);
    }

    /**
     * Return combined string of repo URL and source ref.
     */
    public function urlWithRef(): string {
        return $this->url . '@' . ($this->sourceRef ?? 'main');
    }

    // ────────────────────────────────────────────────
    // Conversion helpers
    // ────────────────────────────────────────────────

    public function toArray(): array {
        return [
            'content_repo_id'       => $this->id->toInt(),
            'content_repo_url'      => $this->url,
            'content_repo_name'     => $this->name,
            'source_ref'            => $this->sourceRef,
            'manifest_path'         => $this->manifestPath,
            'manifest_hash'         => $this->manifestHash,
            'manifest_last_parsed'  => $this->manifestLastParsed,
            'last_commit'           => $this->lastCommit,
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
        ];
    }

    /**
     * Build from a database row having columns in self::FIELDS.
     */
    public static function fromRow(object $row): self {
        return new self(
            new ContentRepoId((int)$row->content_repo_id),
            (string)$row->content_repo_url,
            $row->content_repo_name !== null ? (string)$row->content_repo_name : null,
            $row->source_ref !== null ? (string)$row->source_ref : null,
            $row->manifest_path !== null ? (string)$row->manifest_path : null,
            $row->manifest_hash !== null ? (string)$row->manifest_hash : null,
            isset($row->manifest_last_parsed) ? (int)$row->manifest_last_parsed : null,
            $row->last_commit !== null ? (string)$row->last_commit : null,
            isset($row->created_at) ? (int)$row->created_at : null,
            isset($row->updated_at) ? (int)$row->updated_at : null,
        );
    }
}
