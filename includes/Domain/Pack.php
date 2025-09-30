<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

final class Pack implements ContentNode {
	private PackId $id;
	private ?string $description;
	private ?string $version;
	/** @var PackId[] */
	private array $dependsOnPacks;
	/** @var PageId[] */
	private array $includedPages;

	/**
	 * @param PackId[] $dependsOnPacks
	 * @param PageId[] $includedPages
	 */
	public function __construct(
		PackId $id,
		?string $description = null,
		?string $version = null,
		array $dependsOnPacks = [],
		array $includedPages = []
	) {
		$this->id = $id;
		$this->description = $description;
		$this->version = $version;
		$this->dependsOnPacks = $dependsOnPacks;
		$this->includedPages = $includedPages;
	}

	public function getId(): PackId { return $this->id; }
	public function getIdString(): string { return (string)$this->id; }
	public function getNodeType(): string { return 'pack'; }
	public function getDescription(): ?string { return $this->description; }
	public function getVersion(): ?string { return $this->version; }
	/** @return PackId[] */
	public function getDependsOnPacks(): array { return $this->dependsOnPacks; }
	/** @return PageId[] */
	public function getIncludedPages(): array { return $this->includedPages; }
}


