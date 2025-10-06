<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Domain model representing an installed page within a Pack.
 */
final class Page {
    public const TABLE = 'labki_page';
    /** @var string[] */
    public const FIELDS = [ 
		'page_id',
		'pack_id',
		'name',
		'final_title',
		'page_namespace',
		'wiki_page_id',
		'last_rev_id',
		'content_hash',
		'created_at',
		'updated_at', 
	];
    private PageId $id;
    private PackId $packId;
    private string $name;
    private string $finalTitle;
    private int $namespace;
    private ?int $wikiPageId;
    private ?int $lastRevId;
    private ?string $contentHash;
    private ?int $createdAt;
    private ?int $updatedAt;

    public function __construct(
        PageId $id,
        PackId $packId,
        string $name,
        string $finalTitle,
        int $namespace,
        ?int $wikiPageId = null,
        ?int $lastRevId = null,
        ?string $contentHash = null,
        ?int $createdAt = null,
        ?int $updatedAt = null
    ) {
        $this->id = $id;
        $this->packId = $packId;
        $this->name = $name;
        $this->finalTitle = $finalTitle;
        $this->namespace = $namespace;
        $this->wikiPageId = $wikiPageId;
        $this->lastRevId = $lastRevId;
        $this->contentHash = $contentHash;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function id(): PageId { return $this->id; }
    public function packId(): PackId { return $this->packId; }
    public function name(): string { return $this->name; }
    public function finalTitle(): string { return $this->finalTitle; }
    public function namespace(): int { return $this->namespace; }
    public function wikiPageId(): ?int { return $this->wikiPageId; }
    public function lastRevId(): ?int { return $this->lastRevId; }
    public function contentHash(): ?string { return $this->contentHash; }
    public function createdAt(): ?int { return $this->createdAt; }
    public function updatedAt(): ?int { return $this->updatedAt; }

    public function toArray(): array {
        return [
            'page_id' => $this->id->toInt(),
            'pack_id' => $this->packId->toInt(),
            'name' => $this->name,
            'final_title' => $this->finalTitle,
            'page_namespace' => $this->namespace,
            'wiki_page_id' => $this->wikiPageId,
            'last_rev_id' => $this->lastRevId,
            'content_hash' => $this->contentHash,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Build from a database row having columns in self::FIELDS
     */
    public static function fromRow( object $row ): self {
        return new self(
            new PageId( (int)$row->page_id ),
            new PackId( (int)$row->pack_id ),
            (string)$row->name,
            (string)$row->final_title,
            (int)$row->page_namespace,
            isset( $row->wiki_page_id ) && $row->wiki_page_id !== null ? (int)$row->wiki_page_id : null,
            isset( $row->last_rev_id ) && $row->last_rev_id !== null ? (int)$row->last_rev_id : null,
            isset( $row->content_hash ) && $row->content_hash !== null ? (string)$row->content_hash : null,
            isset( $row->created_at ) && $row->created_at !== null ? (int)$row->created_at : null,
            isset( $row->updated_at ) && $row->updated_at !== null ? (int)$row->updated_at : null,
        );
    }
}
