<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Packs;

use ApiTestCase;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\PackStateStore;
use LabkiPackManager\Services\ManifestStore;
use MediaWiki\Status\Status;

/**
 * Integration tests for ApiLabkiPacksAction.
 *
 * Tests the unified pack action endpoint that handles commands via JSON payloads.
 * Covers command validation, payload parsing, state management, and handler execution.
 *
 * @covers \LabkiPackManager\API\Packs\ApiLabkiPacksAction
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
final class ApiLabkiPacksActionTest extends ApiTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private PackStateStore $stateStore;

	/** @var string[] Database tables used by these tests */
	protected $tablesUsed = [
		'labki_content_repo',
		'labki_content_ref',
		'labki_pack',
		'labki_page',
		'labki_pack_session_state',
	];

	protected function setUp(): void {
		parent::setUp();
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->stateStore = new PackStateStore();
		
		// Grant manage permission by default for most tests
		$this->setGroupPermissions( 'user', 'labkipackmanager-manage', true );
	}

	/**
	 * Helper to create a test repository.
	 */
	private function createTestRepo( string $url = 'https://github.com/test/repo', string $defaultRef = 'main' ): ContentRepoId {
		return $this->repoRegistry->ensureRepoEntry( $url, [
			'default_ref' => $defaultRef,
		] );
	}

	/**
	 * Helper to create a test ref.
	 */
	private function createTestRef( ContentRepoId $repoId, string $ref = 'main' ): ContentRefId {
		return $this->refRegistry->ensureRefEntry(
			$repoId,
			$ref,
			[
				'worktree_path' => '/tmp/test/worktree',
				'last_commit' => 'abc123',
				'manifest_hash' => 'test-hash',
			]
		);
	}

	/**
	 * Helper to create a minimal test manifest.
	 */
	private function createTestManifest(): array {
		return [
			'meta' => [
				'schema_version' => 1,
				'repo_url' => 'https://github.com/test/repo',
				'ref' => 'main',
				'hash' => 'abc123',
			],
			'manifest' => [
				'schema_version' => '1.0.0',
				'name' => 'Test Manifest',
				'packs' => [
					'test-pack' => [
						'display_name' => 'Test Pack',
						'version' => '1.0.0',
						'description' => 'A test pack',
						'contains' => ['test-page'],
					],
				],
				'pages' => [
					'test-page' => [
						'title' => 'Test Page',
						'source' => 'test-page.md',
					],
				],
			],
		];
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// Payload Validation Tests
	// ─────────────────────────────────────────────────────────────────────────────

	public function testMissingPayload_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
		] );
	}

	public function testInvalidJsonPayload_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => '{invalid json}',
		], null, false, $this->getTestUser()->getUser() );
	}

	public function testPayloadMissingCommand_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'repo_url' => 'https://github.com/test/repo',
				'ref' => 'main',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	public function testPayloadMissingRepoUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'ref' => 'main',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	public function testPayloadMissingRef_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'repo_url' => 'https://github.com/test/repo',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	public function testPayloadMissingData_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'repo_url' => 'https://github.com/test/repo',
				'ref' => 'main',
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// Command and Resource Validation Tests
	// ─────────────────────────────────────────────────────────────────────────────

	public function testUnknownCommand_ReturnsError(): void {
		$repoId = $this->createTestRepo();
		$this->createTestRef( $repoId, 'main' );

		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'unknown_command',
				'repo_url' => 'https://github.com/test/repo',
				'ref' => 'main',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	public function testNonExistentRepo_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'repo_url' => 'https://github.com/nonexistent/repo',
				'ref' => 'main',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	public function testNonExistentRef_ReturnsError(): void {
		$this->createTestRepo();

		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'repo_url' => 'https://github.com/test/repo',
				'ref' => 'nonexistent-ref',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// Manifest Validation Tests
	// ─────────────────────────────────────────────────────────────────────────────

	public function testManifestNotFound_ReturnsError(): void {
		$repoId = $this->createTestRepo();
		$this->createTestRef( $repoId, 'main' );

		// Without a real manifest file, this should fail
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'repo_url' => 'https://github.com/test/repo',
				'ref' => 'main',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// Permission Tests
	// ─────────────────────────────────────────────────────────────────────────────

	public function testRequiresManagePermission(): void {
		$this->setGroupPermissions( 'user', 'labkipackmanager-manage', false );
		$user = $this->getTestUser()->getUser();

		$repoId = $this->createTestRepo();
		$this->createTestRef( $repoId, 'main' );

		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'repo_url' => 'https://github.com/test/repo',
				'ref' => 'main',
				'data' => [],
			] ),
		], null, false, $user );
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// API Properties Tests
	// ─────────────────────────────────────────────────────────────────────────────

	public function testApiProperties_PostRequiredWriteModeInternal(): void {
		$api = new \LabkiPackManager\API\Packs\ApiLabkiPacksAction(
			new \ApiMain( new \MediaWiki\Request\FauxRequest( [] ) ),
			'labkiPacksAction'
		);

		$this->assertTrue( $api->mustBePosted() );
		$this->assertTrue( $api->isWriteMode() );
		$this->assertTrue( $api->isInternal() );
	}

	// ─────────────────────────────────────────────────────────────────────────────
	// Error Handling Tests
	// ─────────────────────────────────────────────────────────────────────────────

	public function testEmptyCommand_ReturnsError(): void {
		$repoId = $this->createTestRepo();
		$this->createTestRef( $repoId, 'main' );

		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => '',
				'repo_url' => 'https://github.com/test/repo',
				'ref' => 'main',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	public function testEmptyRepoUrl_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'repo_url' => '',
				'ref' => 'main',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}

	public function testEmptyRef_ReturnsError(): void {
		$this->expectException( \ApiUsageException::class );
		$this->doApiRequest( [
			'action' => 'labkiPacksAction',
			'payload' => json_encode( [
				'command' => 'init',
				'repo_url' => 'https://github.com/test/repo',
				'ref' => '',
				'data' => [],
			] ),
		], null, false, $this->getTestUser()->getUser() );
	}
}

