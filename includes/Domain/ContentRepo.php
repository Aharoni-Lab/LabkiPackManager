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
    public const FIELDS = [
        'content_repo_id',
        'content_repo_url',
        'default_ref',
        'bare_path',
        'last_fetched',
        'created_at',
        'updated_at',
    ];

    private ContentRepoId $id;
    private string $url;
    private ?string $defaultRef;
    private ?string $barePath;
    private ?int $lastFetched;
    private ?int $createdAt;
    private ?int $updatedAt;

    public function __construct(
        ContentRepoId $id,
        string $url,
        ?string $defaultRef = 'main',
        ?string $barePath = null,
        ?int $lastFetched = null,
        ?int $createdAt = null,
        ?int $updatedAt = null
    ) {
        $this->id = $id;
        $this->url = $url;
        $this->defaultRef = $defaultRef;
        $this->barePath = $barePath;
        $this->lastFetched = $lastFetched;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function id(): ContentRepoId { return $this->id; }
    public function url(): string { return $this->url; }
    public function defaultRef(): ?string { return $this->defaultRef; }
    public function barePath(): ?string { return $this->barePath; }
    public function lastFetched(): ?int { return $this->lastFetched; }
    public function createdAt(): ?int { return $this->createdAt; }
    public function updatedAt(): ?int { return $this->updatedAt; }

    public function toArray(): array {
        return [
            'content_repo_id' => $this->id->toInt(),
            'content_repo_url' => $this->url,
            'default_ref' => $this->defaultRef,
            'bare_path' => $this->barePath,
            'last_fetched' => $this->lastFetched,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public static function fromRow(object $row): self {
        return new self(
            new ContentRepoId((int)$row->content_repo_id),
            (string)$row->content_repo_url,
            $row->default_ref ?? 'main',
            $row->bare_path ?? null,
            isset($row->last_fetched) ? (int)$row->last_fetched : null,
            isset($row->created_at) ? (int)$row->created_at : null,
            isset($row->updated_at) ? (int)$row->updated_at : null,
        );
    }
}
