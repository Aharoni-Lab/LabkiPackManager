<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Strongly-typed identifier for an operation.
 * 
 * Unlike other IDs in the system, OperationId is a string-based identifier
 * to allow for descriptive operation IDs (e.g., "repo_add_abc123").
 */
final class OperationId {
    private string $id;

    public function __construct(string $id) {
        $this->id = $id;
    }

    public function toString(): string {
        return $this->id;
    }

    public function equals(OperationId $other): bool {
        return $this->id === $other->id;
    }

    public function __toString(): string {
        return $this->id;
    }
}

