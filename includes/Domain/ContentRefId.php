<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Strongly-typed identifier for a content reference.
 */
final class ContentRefId {
    private int $id;

    public function __construct(int $id) {
        $this->id = $id;
    }

    public function toInt(): int {
        return $this->id;
    }

    public function equals(ContentRefId $other): bool {
        return $this->id === $other->id;
    }

    public function __toString(): string {
        return (string)$this->id;
    }
}
