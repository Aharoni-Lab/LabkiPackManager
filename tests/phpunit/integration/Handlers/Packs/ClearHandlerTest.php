<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Handlers\Packs;

use LabkiPackManager\Handlers\Packs\ClearHandler;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ClearHandler.
 *
 * Tests clearing/resetting session state to installed packs only.
 *
 * @covers \LabkiPackManager\Handlers\Packs\ClearHandler
 * @covers \LabkiPackManager\Handlers\Packs\BasePackHandler
 */
final class ClearHandlerTest extends TestCase {

	private function createTestManifest( array $packs = [], array $pages = [] ): array {
		return [
			'manifest' => [
				'schema_version' => '1.0.0',
				'name' => 'Test Manifest',
				'packs' => $packs,
				'pages' => $pages,
			],
		];
	}

	private function createTestContext( int $userId = 1, ?ContentRefId $refId = null ): array {
		return [
			'user_id' => $userId,
			'repo_url' => 'https://github.com/test/repo',
			'ref' => 'main',
			'ref_id' => $refId ?? new ContentRefId( 1 ),
			'repo_id' => new \LabkiPackManager\Domain\ContentRepoId( 1 ),
			'services' => null,
		];
	}

	public function testHandle_WithEmptyManifest_CreatesEmptyState(): void {
		$handler = new ClearHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertArrayHasKey( 'state', $result );
		$state = $result['state'];
		$this->assertInstanceOf( PackSessionState::class, $state );
		$this->assertEmpty( $state->packs() );
		$this->assertTrue( $result['save'] );
	}

	public function testHandle_ResetsToFreshState(): void {
		$handler = new ClearHandler();
		$manifest = $this->createTestManifest( [
			'pack-a' => [
				'display_name' => 'Pack A',
				'version' => '1.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();

		// Create existing state with modified actions
		$existingState = new PackSessionState( 
			new ContentRefId( 1 ), 
			1, 
			[
				'pack-a' => [
					'action' => 'install',
					'prefix' => 'Modified',
				],
			] 
		);

		$result = $handler->handle( $existingState, $manifest, [], $context );

		$state = $result['state'];
		$packs = $state->packs();
		
		// Should have reset to default state
		$this->assertArrayHasKey( 'pack-a', $packs );
		$this->assertSame( 'unchanged', $packs['pack-a']['action'] );
	}

	public function testHandle_ReturnsEmptyWarnings(): void {
		$handler = new ClearHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertIsArray( $result['warnings'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function testHandle_ReturnsSaveTrue(): void {
		$handler = new ClearHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertTrue( $result['save'] );
	}
}

