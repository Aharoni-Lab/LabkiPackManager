<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Strongly-typed identifier for a content repository.
 */
final class ContentRepoId {
    private int $id;

    public function __construct(int $id) {
        $this->id = $id;
    }

    public function toInt(): int {
        return $this->id;
    }

    public function equals(ContentRepoId $other): bool {
        return $this->id === $other->id;
    }

    public function __toString(): string {
        return (string)$this->id;
    }
}
