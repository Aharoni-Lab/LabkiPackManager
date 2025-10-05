<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Domain model representing a content repository.
 *
 * A content repository hosts one or more Packs.
 * This class is a pure data container with no persistence logic.
 */
final class ContentRepo {
    public const TABLE = 'labki_content_repo';
    /** @var string[] */
    public const FIELDS = [ 
        'content_repo_id', 
        'repo_url', 
        'default_ref', 
        'created_at', 
        'updated_at', 
    ];

    private ContentRepoId $id;
    private string $url;
    private ?string $defaultRef;
    private ?int $createdAt;
    private ?int $updatedAt;

    public function __construct(
        ContentRepoId $id,
        string $url,
        ?string $defaultRef = null,
        ?int $createdAt = null,
        ?int $updatedAt = null
    ) {
        $this->id = $id;
        $this->url = $url;
        $this->defaultRef = $defaultRef;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function id(): ContentRepoId { return $this->id; }
    public function url(): string { return $this->url; }
    public function defaultRef(): ?string { return $this->defaultRef; }
    public function createdAt(): ?int { return $this->createdAt; }
    public function updatedAt(): ?int { return $this->updatedAt; }

    public function toArray(): array {
        return [
            'content_repo_id' => $this->id->toInt(),
            'content_repo_url' => $this->url,
            'default_ref' => $this->defaultRef,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Build from a database row having columns in self::FIELDS
     */
    public static function fromRow( object $row ): self {
        return new self(
            new ContentRepoId( (int)$row->content_repo_id ),
            (string)$row->content_repo_url,
            isset( $row->default_ref ) && $row->default_ref !== null ? (string)$row->default_ref : null,
            isset( $row->created_at ) && $row->created_at !== null ? (int)$row->created_at : null,
            isset( $row->updated_at ) && $row->updated_at !== null ? (int)$row->updated_at : null,
        );
    }
}
