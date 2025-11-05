<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Handlers\Packs;

use LabkiPackManager\Handlers\Packs\RenamePageHandler;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RenamePageHandler.
 *
 * Tests renaming pages within packs.
 *
 * @covers \LabkiPackManager\Handlers\Packs\RenamePageHandler
 * @covers \LabkiPackManager\Handlers\Packs\BasePackHandler
 */
final class RenamePageHandlerTest extends TestCase {

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
		$handler = new RenamePageHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'state cannot be null' );
		
		$handler->handle( null, $manifest, [], $context );
	}

	public function testHandle_RequiresPackName(): void {
		$handler = new RenamePageHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid or missing pack_name' );
		
		$handler->handle( $state, $manifest, [ 'page_name' => 'test', 'new_title' => 'New' ], $context );
	}

	public function testHandle_RequiresPageName(): void {
		$handler = new RenamePageHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid or missing page_name' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test', 'new_title' => 'New' ], $context );
	}

	public function testHandle_RequiresNewTitle(): void {
		$handler = new RenamePageHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'new_title is required' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test', 'page_name' => 'page' ], $context );
	}

	public function testHandle_ValidatesNewTitleIsString(): void {
		$handler = new RenamePageHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'new_title must be a string' );
		
		$handler->handle( $state, $manifest, [ 'pack_name' => 'test', 'page_name' => 'page', 'new_title' => 123 ], $context );
	}

	public function testHandle_ValidatesPackAndPageExist(): void {
		$handler = new RenamePageHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'pages' => [ 'other-page' => [] ],
			],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not found in pack' );
		
		$handler->handle( $state, $manifest, [ 
			'pack_name' => 'test-pack', 
			'page_name' => 'nonexistent-page', 
			'new_title' => 'New Title' 
		], $context );
	}

	public function testHandle_RenamesPageSuccessfully(): void {
		$handler = new RenamePageHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'action' => 'unchanged',
				'pages' => [ 
					'test-page' => [
						'final_title' => 'OldTitle',
					],
				],
			],
		] );

		$result = $handler->handle( $state, $manifest, [ 
			'pack_name' => 'test-pack', 
			'page_name' => 'test-page', 
			'new_title' => 'New/Custom/Title' 
		], $context );

		$newState = $result['state'];
		$pack = $newState->getPack( 'test-pack' );
		$this->assertSame( 'New/Custom/Title', $pack['pages']['test-page']['final_title'] );
	}

	public function testHandle_ReturnsWarningsArray(): void {
		$handler = new RenamePageHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();
		$state = new PackSessionState( new ContentRefId( 1 ), 1, [
			'test-pack' => [
				'pages' => [ 'test-page' => [ 'final_title' => 'Old' ] ],
			],
		] );

		$result = $handler->handle( $state, $manifest, [ 
			'pack_name' => 'test-pack', 
			'page_name' => 'test-page', 
			'new_title' => 'New' 
		], $context );

		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertIsArray( $result['warnings'] );
	}
}

