<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Handlers\Packs;

use LabkiPackManager\Handlers\Packs\SetPackPrefixHandler;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SetPackPrefixHandler.
 *
 * Tests setting pack prefixes which automatically recomputes page titles.
 *
 * @covers \LabkiPackManager\Handlers\Packs\SetPackPrefixHandler
 * @covers \LabkiPackManager\Handlers\Packs\BasePackHandler
 */
final class SetPackPrefixHandlerTest extends TestCase {

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
			'services' => null,
		];
	}

	public function testHandle_RequiresState(): void {
		$handler = new SetPackPrefixHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'state cannot be null' );
		
		$handler->handle( null, $manifest, [], $context );
	}

	public function testHandle_RequiresPackName(): void {
		$handler = new SetPackPrefixHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid or missing pack_name' );
		
		$handler->handle( $state, $manifest, [ 'prefix' => 'Test' ], $context );
	}

	public function testHandle_RequiresPrefix(): void {
		$handler = new SetPackPrefixHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'prefix is required' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test' ], $context );
	}

	public function testHandle_ValidatesPrefixIsString(): void {
		$handler = new SetPackPrefixHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'prefix must be a string' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test', 'prefix' => 123 ], $context );
	}

	public function testHandle_ValidatesPackExists(): void {
		$handler = new SetPackPrefixHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not found in state' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'nonexistent', 'prefix' => 'Test' ], $context );
	}

	public function testHandle_SetsPrefixSuccessfully(): void {
		$handler = new SetPackPrefixHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'prefix' => 'OldPrefix',
				'pages' => [],
			],
		] );

		$result = $handler->handle( $state, $manifest, [ 'pack_name' => 'test-pack', 'prefix' => 'NewPrefix' ], $context );

		$newState = $result['state'];
		$pack = $newState->getPack( 'test-pack' );
		$this->assertSame( 'NewPrefix', $pack['prefix'] );
	}

	public function testHandle_ReturnsWarningsArray(): void {
		$handler = new SetPackPrefixHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [ 'prefix' => 'Old', 'pages' => [] ],
		] );

		$result = $handler->handle( $state, $manifest, [ 'pack_name' => 'test-pack', 'prefix' => 'New' ], $context );

		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertIsArray( $result['warnings'] );
	}
}

