<?php

declare(strict_types=1);

namespace LabkiPackManager\Handlers\Packs;

/**
 * Exception thrown when frontend and backend pack session states are out of sync.
 * Carries the authoritative server state along with computed differences and
 * suggested reconcile commands so the API layer can return structured data to
 * the client.
 */
final class StateOutOfSyncException extends \RuntimeException {

	/** @var array<string, array> */
	private array $serverPacks;

	private string $serverHash;

	/** @var array */
	private array $differences;

	/** @var array<int, array<string, mixed>> */
	private array $reconcileCommands;

	/**
	 * @param string $message Exception message
	 * @param array<string, array> $serverPacks Authoritative server pack state
	 * @param string $serverHash Authoritative server hash
	 * @param array $differences Field-level differences between client and server
	 * @param array<int, array<string, mixed>> $reconcileCommands Suggested commands for reconciliation
	 */
	public function __construct(
		string $message,
		array $serverPacks,
		string $serverHash,
		array $differences,
		array $reconcileCommands
	) {
		parent::__construct( $message );
		$this->serverPacks = $serverPacks;
		$this->serverHash = $serverHash;
		$this->differences = $differences;
		$this->reconcileCommands = $reconcileCommands;
	}

	/**
	 * @return array<string, array>
	 */
	public function getServerPacks(): array {
		return $this->serverPacks;
	}

	public function getServerHash(): string {
		return $this->serverHash;
	}

	/**
	 * @return array
	 */
	public function getDifferences(): array {
		return $this->differences;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getReconcileCommands(): array {
		return $this->reconcileCommands;
	}
}


