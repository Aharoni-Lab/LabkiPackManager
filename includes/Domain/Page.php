<?php

declare(strict_types=1);

namespace LabkiPackManager\Domain;

final class Page implements ContentNode {
	private PageId $id;
	private ?string $title;
	private ?string $sourcePath;

	public function __construct( PageId $id, ?string $title = null, ?string $sourcePath = null ) {
		$this->id = $id;
		$this->title = $title;
		$this->sourcePath = $sourcePath;
	}

	public function getId(): PageId { return $this->id; }
	public function getIdString(): string { return (string)$this->id; }
	public function getNodeType(): string { return 'page'; }
	public function getTitle(): ?string { return $this->title; }
	public function getSourcePath(): ?string { return $this->sourcePath; }
}


