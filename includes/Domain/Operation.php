<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Domain model representing a background job or API operation.
 *
 * Operations track long-running tasks such as repository initialization,
 * synchronization, and pack installation through their lifecycle.
 */
final class Operation {
    public const TABLE = 'labki_operations';
    public const FIELDS = [
        'operation_id',
        'operation_type',
        'status',
        'progress',
        'message',
        'result_data',
        'user_id',
        'created_at',
        'started_at',
        'updated_at',
    ];

    // Operation status constants
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

	// Common operation types
	public const TYPE_REPO_ADD = 'repo_add';
	public const TYPE_REPO_SYNC = 'repo_sync';
	public const TYPE_REPO_REMOVE = 'repo_remove';
	public const TYPE_PACK_INSTALL = 'pack_install';
	public const TYPE_PACK_UPDATE = 'pack_update';
	public const TYPE_PACK_REMOVE = 'pack_remove';
	public const TYPE_PACK_APPLY = 'pack_apply';

    private OperationId $id;
    private string $type;
    private string $status;
    private ?int $progress;
    private ?string $message;
    private ?string $resultData;
    private ?int $userId;
    private ?int $createdAt;
    private ?int $startedAt;
    private ?int $updatedAt;

    public function __construct(
        OperationId $id,
        string $type,
        string $status = self::STATUS_QUEUED,
        ?int $progress = null,
        ?string $message = null,
        ?string $resultData = null,
        ?int $userId = null,
        ?int $createdAt = null,
        ?int $startedAt = null,
        ?int $updatedAt = null
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->status = $status;
        $this->progress = $progress;
        $this->message = $message;
        $this->resultData = $resultData;
        $this->userId = $userId;
        $this->createdAt = $createdAt;
        $this->startedAt = $startedAt;
        $this->updatedAt = $updatedAt;
    }

    public function id(): OperationId { return $this->id; }
    public function type(): string { return $this->type; }
    public function status(): string { return $this->status; }
    public function progress(): ?int { return $this->progress; }
    public function message(): ?string { return $this->message; }
    public function resultData(): ?string { return $this->resultData; }
    public function userId(): ?int { return $this->userId; }
    public function createdAt(): ?int { return $this->createdAt; }
    public function startedAt(): ?int { return $this->startedAt; }
    public function updatedAt(): ?int { return $this->updatedAt; }

    public function toArray(): array {
        return [
            'operation_id' => $this->id->toString(),
            'operation_type' => $this->type,
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'result_data' => $this->resultData,
            'user_id' => $this->userId,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public static function fromRow(object $row): self {
        // Handle both lowercase 'status' and uppercase 'STATUS' (generated SQL may vary)
        $status = $row->status ?? $row->STATUS;
        
        return new self(
            new OperationId( (string)$row->operation_id ),
            (string)$row->operation_type,
            (string)$status,
            isset($row->progress) && $row->progress !== null ? (int)$row->progress : null,
            $row->message ?? null,
            $row->result_data ?? null,
            isset($row->user_id) && $row->user_id !== null ? (int)$row->user_id : null,
            isset($row->created_at) && $row->created_at !== null ? (int)$row->created_at : null,
            isset($row->started_at) && $row->started_at !== null ? (int)$row->started_at : null,
            isset($row->updated_at) && $row->updated_at !== null ? (int)$row->updated_at : null,
        );
    }
}

