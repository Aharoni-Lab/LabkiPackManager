<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Domain model for entries in labki_content_ref.
 */
final class ContentRef {
    public const TABLE = 'labki_content_ref';

    public const FIELDS = [
        'content_ref_id',
        'content_repo_id',
        'source_ref',
        'content_ref_name',
        'last_commit',
        'manifest_hash',
        'manifest_last_parsed',
        'worktree_path',
        'created_at',
        'updated_at',
    ];

    private ContentRefId $id;
    private ContentRepoId $repoId;
    private string $sourceRef;
    private ?string $refName;
    private ?string $lastCommit;
    private ?string $manifestHash;
    private ?int $manifestLastParsed;
    private ?string $worktreePath;
    private ?int $createdAt;
    private ?int $updatedAt;

    public function __construct(
        ContentRefId $id,
        ContentRepoId $repoId,
        string $sourceRef,
        ?string $refName = null,
        ?string $lastCommit = null,
        ?string $manifestHash = null,
        ?int $manifestLastParsed = null,
        ?string $worktreePath = null,
        ?int $createdAt = null,
        ?int $updatedAt = null
    ) {
        $this->id = $id;
        $this->repoId = $repoId;
        $this->sourceRef = $sourceRef;
        $this->refName = $refName;
        $this->lastCommit = $lastCommit;
        $this->manifestHash = $manifestHash;
        $this->manifestLastParsed = $manifestLastParsed;
        $this->worktreePath = $worktreePath;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function id(): ContentRefId { return $this->id; }
    public function repoId(): ContentRepoId { return $this->repoId; }
    public function sourceRef(): string { return $this->sourceRef; }
    public function refName(): ?string { return $this->refName; }
    public function lastCommit(): ?string { return $this->lastCommit; }
    public function manifestHash(): ?string { return $this->manifestHash; }
    public function manifestLastParsed(): ?int { return $this->manifestLastParsed; }
    public function worktreePath(): ?string { return $this->worktreePath; }
    public function createdAt(): ?int { return $this->createdAt; }
    public function updatedAt(): ?int { return $this->updatedAt; }

    public function toArray(): array {
        return [
            'content_ref_id'        => $this->id->toInt(),
            'content_repo_id'       => $this->repoId->toInt(),
            'source_ref'            => $this->sourceRef,
            'content_ref_name'      => $this->refName,
            'last_commit'           => $this->lastCommit,
            'manifest_hash'         => $this->manifestHash,
            'manifest_last_parsed'  => $this->manifestLastParsed,
            'worktree_path'         => $this->worktreePath,
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
        ];
    }

    public static function fromRow(object $row): self {
        return new self(
            new ContentRefId((int)$row->content_ref_id),
            new ContentRepoId((int)$row->content_repo_id),
            (string)$row->source_ref,
            $row->content_ref_name ?? null,
            $row->last_commit ?? null,
            $row->manifest_hash ?? null,
            isset($row->manifest_last_parsed) ? (int)$row->manifest_last_parsed : null,
            $row->worktree_path ?? null,
            isset($row->created_at) ? (int)$row->created_at : null,
            isset($row->updated_at) ? (int)$row->updated_at : null,
        );
    }
}
