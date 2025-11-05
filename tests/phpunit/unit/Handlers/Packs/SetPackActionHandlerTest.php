<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Handlers\Packs;

use LabkiPackManager\Handlers\Packs\SetPackActionHandler;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SetPackActionHandler.
 *
 * Tests setting pack actions (install/update/remove/unchanged) with dependency resolution.
 *
 * @covers \LabkiPackManager\Handlers\Packs\SetPackActionHandler
 * @covers \LabkiPackManager\Handlers\Packs\BasePackHandler
 */
final class SetPackActionHandlerTest extends TestCase {

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

	public function testHandle_RequiresState(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'state cannot be null' );
		
		$handler->handle( null, $manifest, [ 'pack_name' => 'test', 'action' => 'install' ], $context );
	}

	public function testHandle_RequiresPackName(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid or missing pack_name' );
		
		$handler->handle( $state, $manifest, [ 'action' => 'install' ], $context );
	}

	public function testHandle_RequiresAction(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid or missing action' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test' ], $context );
	}

	public function testHandle_ValidatesActionValue(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid action' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test', 'action' => 'invalid_action' ], $context );
	}

	public function testHandle_ValidatesPackExistsInManifest(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest( [] );
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not found in manifest' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'nonexistent', 'action' => 'install' ], $context );
	}

	public function testHandle_ValidatesPackExistsInState(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest( [
			'test-pack' => [
				'display_name' => 'Test Pack',
				'version' => '1.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();
		// State without the pack
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not found in state' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test-pack', 'action' => 'install' ], $context );
	}

	public function testHandle_PreventInstallOnInstalledPack(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest( [
			'test-pack' => [
				'display_name' => 'Test Pack',
				'version' => '1.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'action' => 'unchanged',
				'current_version' => '1.0.0', // Already installed
				'target_version' => '1.0.0',
				'installed' => true,
				'pages' => [],
			],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot install' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test-pack', 'action' => 'install' ], $context );
	}

	public function testHandle_PreventUpdateOnUninstalledPack(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest( [
			'test-pack' => [
				'display_name' => 'Test Pack',
				'version' => '1.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'action' => 'unchanged',
				'current_version' => null, // Not installed
				'target_version' => '1.0.0',
				'installed' => false,
				'pages' => [],
			],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot update' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test-pack', 'action' => 'update' ], $context );
	}

	public function testHandle_PreventRemoveOnUninstalledPack(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest( [
			'test-pack' => [
				'display_name' => 'Test Pack',
				'version' => '1.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'action' => 'unchanged',
				'current_version' => null, // Not installed
				'target_version' => '1.0.0',
				'installed' => false,
				'pages' => [],
			],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot remove' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test-pack', 'action' => 'remove' ], $context );
	}

	public function testHandle_SetsActionSuccessfully(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest( [
			'test-pack' => [
				'display_name' => 'Test Pack',
				'version' => '1.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'action' => 'unchanged',
				'current_version' => null,
				'target_version' => '1.0.0',
				'installed' => false,
				'pages' => [],
			],
		] );

		$result = $handler->handle( $state, $manifest, [ 'pack_name' => 'test-pack', 'action' => 'install' ], $context );

		$newState = $result['state'];
		$pack = $newState->getPack( 'test-pack' );
		$this->assertSame( 'install', $pack['action'] );
	}

	public function testHandle_ReturnsWarningsArray(): void {
		$handler = new SetPackActionHandler();
		$manifest = $this->createTestManifest( [
			'test-pack' => [
				'display_name' => 'Test Pack',
				'version' => '1.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'action' => 'unchanged',
				'current_version' => null,
				'target_version' => '1.0.0',
				'installed' => false,
				'pages' => [],
			],
		] );

		$result = $handler->handle( $state, $manifest, [ 'pack_name' => 'test-pack', 'action' => 'install' ], $context );

		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertIsArray( $result['warnings'] );
	}
}

