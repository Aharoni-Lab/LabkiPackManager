<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

/**
 * Common interface for content nodes (packs and pages).
 */
interface ContentNode {
	/**
	 * Returns the stable identifier as a string.
	 */
	public function getIdString(): string;

	/**
	 * Returns a short type string, e.g. "pack" or "page".
	 */
	public function getNodeType(): string;
}


