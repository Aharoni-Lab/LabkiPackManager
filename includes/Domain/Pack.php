<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Domain model representing a content pack.
 *
 * A pack belongs to a single repository and contains one or more Pages.
 */
final class Pack {
    private PackId $id;
    private ContentRepoId $content_repo_id;
    private string $name;
    private ?string $version;
    private ?string $sourceRef;
    private ?string $sourceCommit;
    private ?int $installedAt;
    private ?int $installedBy;

    public function __construct(
        PackId $id,
        ContentRepoId $content_repo_id,
        string $name,
        ?string $version = null,
        ?string $sourceRef = null,
        ?string $sourceCommit = null,
        ?int $installedAt = null,
        ?int $installedBy = null
    ) {
        $this->id = $id;
        $this->content_repo_id = $content_repo_id;
        $this->name = $name;
        $this->version = $version;
        $this->sourceRef = $sourceRef;
        $this->sourceCommit = $sourceCommit;
        $this->installedAt = $installedAt;
        $this->installedBy = $installedBy;
    }

    public function id(): PackId { return $this->id; }
    public function content_repo_id(): ContentRepoId { return $this->content_repo_id; }
    public function name(): string { return $this->name; }
    public function version(): ?string { return $this->version; }
    public function sourceRef(): ?string { return $this->sourceRef; }
    public function sourceCommit(): ?string { return $this->sourceCommit; }
    public function installedAt(): ?int { return $this->installedAt; }
    public function installedBy(): ?int { return $this->installedBy; }

    public function toArray(): array {
        return [
            'pack_id' => $this->id->toInt(),
            'content_repo_id' => $this->content_repo_id()->toInt(),
            'name' => $this->name,
            'version' => $this->version,
            'source_ref' => $this->sourceRef,
            'source_commit' => $this->sourceCommit,
            'installed_at' => $this->installedAt,
            'installed_by' => $this->installedBy,
        ];
    }
}
