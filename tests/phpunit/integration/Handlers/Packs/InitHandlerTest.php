<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Handlers\Packs;

use LabkiPackManager\Handlers\Packs\InitHandler;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\PackStateStore;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InitHandler.
 *
 * Tests the initialization of pack session state from manifest and installed packs.
 *
 * @covers \LabkiPackManager\Handlers\Packs\InitHandler
 * @covers \LabkiPackManager\Handlers\Packs\BasePackHandler
 */
final class InitHandlerTest extends TestCase {

	/**
	 * Create a minimal test manifest.
	 */
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

	/**
	 * Create test context array.
	 */
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
		$handler = new InitHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertArrayHasKey( 'state', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertArrayHasKey( 'save', $result );
		
		$state = $result['state'];
		$this->assertInstanceOf( PackSessionState::class, $state );
		$this->assertEmpty( $state->packs() );
		$this->assertTrue( $result['save'] );
		$this->assertIsArray( $result['warnings'] );
	}

	public function testHandle_WithPacksInManifest_CreatesStateWithPacks(): void {
		$handler = new InitHandler();
		$manifest = $this->createTestManifest(
			[
				'test-pack' => [
					'display_name' => 'Test Pack',
					'version' => '1.0.0',
					'description' => 'A test pack',
					'contains' => [ 'test-page' ],
					'depends_on' => [],
				],
			],
			[
				'test-page' => [
					'title' => 'Test Page',
					'source' => 'test.md',
				],
			]
		);
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$state = $result['state'];
		$this->assertInstanceOf( PackSessionState::class, $state );
		$packs = $state->packs();
		$this->assertArrayHasKey( 'test-pack', $packs );
		
		$pack = $packs['test-pack'];
		$this->assertSame( 'unchanged', $pack['action'] );
		$this->assertFalse( $pack['installed'] );
		$this->assertSame( '1.0.0', $pack['target_version'] );
	}

	public function testHandle_IgnoresExistingState(): void {
		// Init should create fresh state regardless of what's passed in
		$handler = new InitHandler();
		$manifest = $this->createTestManifest( [
			'pack-a' => [
				'display_name' => 'Pack A',
				'version' => '1.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();

		// Pass in a dummy existing state - should be ignored
		$oldState = new PackSessionState( 
			new ContentRefId( 1 ), 
			1, 
			[ 'old-pack' => [] ] 
		);

		$result = $handler->handle( $oldState, $manifest, [], $context );

		$state = $result['state'];
		$packs = $state->packs();
		
		// Should have the manifest pack, not the old pack
		$this->assertArrayHasKey( 'pack-a', $packs );
		$this->assertArrayNotHasKey( 'old-pack', $packs );
	}

	public function testHandle_ReturnsEmptyWarnings(): void {
		$handler = new InitHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertIsArray( $result['warnings'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function testHandle_ReturnsSaveTrue(): void {
		$handler = new InitHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertTrue( $result['save'] );
	}
}

