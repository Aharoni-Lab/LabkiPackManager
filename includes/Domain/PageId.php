<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

final class PageId {
	private string $value;

	public function __construct( string $value ) {
		$this->value = $value;
	}

	public static function fromString( string $value ): self {
		return new self( $value );
	}

	public function equals( PageId $other ): bool {
		return $this->value === $other->value;
	}

	public function getValue(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}


