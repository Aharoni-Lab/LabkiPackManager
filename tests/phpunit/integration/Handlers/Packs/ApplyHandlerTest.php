<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Handlers\Packs;

use LabkiPackManager\Handlers\Packs\ApplyHandler;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApplyHandler.
 *
 * Tests the apply command which queues actual pack installation/removal jobs.
 *
 * @covers \LabkiPackManager\Handlers\Packs\ApplyHandler
 * @covers \LabkiPackManager\Handlers\Packs\BasePackHandler
 */
final class ApplyHandlerTest extends TestCase {

	private function createTestManifest(): array {
		return [
			'manifest' => [
				'schema_version' => '1.0.0',
				'name' => 'Test Manifest',
				'packs' => [],
				'pages' => [],
			],
		];
	}

	private function createTestContext(): array {
		return [
			'user_id' => 1,
			'repo_url' => 'https://github.com/test/repo',
			'ref' => 'main',
			'ref_id' => new ContentRefId( 1 ),
			'repo_id' => new \LabkiPackManager\Domain\ContentRepoId( 1 ),
			'services' => null, // In unit tests, services can be null
		];
	}

	public function testHandle_RequiresState(): void {
		$handler = new ApplyHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'state cannot be null' );
		
		$handler->handle( null, $manifest, [ 'state_hash' => 'abc123' ], $context );
	}

	public function testHandle_RequiresStateHashMatch(): void {
		$handler = new ApplyHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );
		$correctHash = $state->hash();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'state hash mismatch' );
		
		$handler->handle( $state, $manifest, [ 'state_hash' => 'wrong_hash' ], $context );
	}

	public function testHandle_WithNoChanges_ThrowsError(): void {
		$handler = new ApplyHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		
		// State with no actions
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'action' => 'unchanged',
			],
		] );
		$correctHash = $state->hash();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no operations to apply' );
		
		$handler->handle( $state, $manifest, [ 'state_hash' => $correctHash ], $context );
	}
}

