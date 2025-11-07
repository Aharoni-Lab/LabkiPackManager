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
		$status = null;
		if ( isset( $row->status ) && $row->status !== null ) {
			$status = (string)$row->status;
		} elseif ( isset( $row->STATUS ) && $row->STATUS !== null ) {
			$status = (string)$row->STATUS;
		}

        return new self(
            new PackId( (int)$row->pack_id ),
            new ContentRefId( (int)$row->content_ref_id ),
            (string)$row->name,
            isset( $row->version ) && $row->version !== null ? (string)$row->version : null,
            isset( $row->source_commit ) && $row->source_commit !== null ? (string)$row->source_commit : null,
            isset( $row->installed_at ) && $row->installed_at !== null ? (int)$row->installed_at : null,
			isset( $row->installed_by ) && $row->installed_by !== null ? (int)$row->installed_by : null,
			isset( $row->updated_at ) && $row->updated_at !== null ? (int)$row->updated_at : null,
			// Handle both lowercase 'status' and uppercase 'STATUS' (generated SQL may vary)
		isset( $row->status ) && $row->status !== null ? (string)$row->status : 
		(isset( $row->STATUS ) && $row->STATUS !== null ? (string)$row->STATUS : null),
        );
    }
}
