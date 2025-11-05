<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Handlers\Packs;

use LabkiPackManager\Handlers\Packs\RefreshHandler;
use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RefreshHandler.
 *
 * Tests refreshing/revalidating session state from manifest.
 *
 * @covers \LabkiPackManager\Handlers\Packs\RefreshHandler
 * @covers \LabkiPackManager\Handlers\Packs\BasePackHandler
 */
final class RefreshHandlerTest extends TestCase {

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

	public function testHandle_RebuildsStateFromManifest(): void {
		$handler = new RefreshHandler();
		$manifest = $this->createTestManifest( [
			'pack-a' => [
				'display_name' => 'Pack A',
				'version' => '2.0.0',
				'contains' => [],
				'depends_on' => [],
			],
		] );
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$state = $result['state'];
		$this->assertInstanceOf( PackSessionState::class, $state );
		$packs = $state->packs();
		$this->assertArrayHasKey( 'pack-a', $packs );
		$this->assertSame( '2.0.0', $packs['pack-a']['target_version'] );
	}

	public function testHandle_CanWorkWithoutExistingState(): void {
		$handler = new RefreshHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertArrayHasKey( 'state', $result );
		$this->assertInstanceOf( PackSessionState::class, $result['state'] );
	}

	public function testHandle_ReturnsSaveTrue(): void {
		$handler = new RefreshHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertTrue( $result['save'] );
	}

	public function testHandle_ReturnsEmptyWarnings(): void {
		$handler = new RefreshHandler();
		$manifest = $this->createTestManifest();
		$context = $this->createTestContext();

		$result = $handler->handle( null, $manifest, [], $context );

		$this->assertIsArray( $result['warnings'] );
		$this->assertEmpty( $result['warnings'] );
	}
}

