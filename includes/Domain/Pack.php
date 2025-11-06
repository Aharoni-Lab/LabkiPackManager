<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Domain model representing a content pack.
 *
 * A pack belongs to a single content ref (branch/tag) and contains one or more Pages.
 */
final class Pack {
    public const TABLE = 'labki_pack';
    /** @var string[] */
    public const FIELDS = [ 
		'pack_id',
		'content_ref_id',
		'name',
		'version',
		'source_commit',
		'installed_at',
		'installed_by',
		'updated_at',
		'status',
	];
    private PackId $id;
    private ContentRefId $contentRefId;
    private string $name;
    private ?string $version;
    private ?string $sourceCommit;
    private ?int $installedAt;
    private ?int $installedBy;
	private ?int $updatedAt;
	private ?string $status;

    public function __construct(
        PackId $id,
        ContentRefId $contentRefId,
        string $name,
        ?string $version = null,
        ?string $sourceCommit = null,
        ?int $installedAt = null,
		?int $installedBy = null,
		?int $updatedAt = null,
		?string $status = null
    ) {
        $this->id = $id;
        $this->contentRefId = $contentRefId;
        $this->name = $name;
        $this->version = $version;
        $this->sourceCommit = $sourceCommit;
        $this->installedAt = $installedAt;
		$this->installedBy = $installedBy;
		$this->updatedAt = $updatedAt;
		$this->status = $status;
    }

    public function id(): PackId { return $this->id; }
    public function contentRefId(): ContentRefId { return $this->contentRefId; }
    public function name(): string { return $this->name; }
    public function version(): ?string { return $this->version; }
    public function sourceCommit(): ?string { return $this->sourceCommit; }
    public function installedAt(): ?int { return $this->installedAt; }
    public function installedBy(): ?int { return $this->installedBy; }
	public function updatedAt(): ?int { return $this->updatedAt; }
	public function status(): ?string { return $this->status; }

    public function toArray(): array {
        return [
            'pack_id' => $this->id->toInt(),
            'content_ref_id' => $this->contentRefId->toInt(),
            'name' => $this->name,
            'version' => $this->version,
            'source_commit' => $this->sourceCommit,
            'installed_at' => $this->installedAt,
            'installed_by' => $this->installedBy,
			'updated_at' => $this->updatedAt,
			'status' => $this->status,
        ];
    }

    /**
     * Build from a database row having columns in self::FIELDS
     */
    public static function fromRow( object $row ): self {
        // Handle case-insensitive column names (some DBs return uppercase, some lowercase)
        $packId = $row->pack_id ?? $row->PACK_ID ?? null;
        $contentRefId = $row->content_ref_id ?? $row->CONTENT_REF_ID ?? null;
        $name = $row->name ?? $row->NAME ?? '';
        $version = $row->version ?? $row->VERSION ?? null;
        $sourceCommit = $row->source_commit ?? $row->SOURCE_COMMIT ?? null;
        $installedAt = $row->installed_at ?? $row->INSTALLED_AT ?? null;
        $installedBy = $row->installed_by ?? $row->INSTALLED_BY ?? null;
        $updatedAt = $row->updated_at ?? $row->UPDATED_AT ?? null;
        $status = $row->status ?? $row->STATUS ?? null;
        
        return new self(
            new PackId( (int)$packId ),
            new ContentRefId( (int)$contentRefId ),
            (string)$name,
            $version !== null ? (string)$version : null,
            $sourceCommit !== null ? (string)$sourceCommit : null,
            $installedAt !== null ? (int)$installedAt : null,
            $installedBy !== null ? (int)$installedBy : null,
            $updatedAt !== null ? (int)$updatedAt : null,
            $status !== null ? (string)$status : null,
        );
    }
}
