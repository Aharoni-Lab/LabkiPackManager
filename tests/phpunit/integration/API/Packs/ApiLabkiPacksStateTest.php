<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Packs;

use ApiTestCase;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\PackStateStore;

/**
 * Integration tests for ApiLabkiPacksState.
 *
 * Tests the unified stateful pack management API with all commands:
 * - init: Initialize session with installed packs
 * - select: Select pack and auto-resolve dependencies
 * - deselect: Deselect pack with cascade support
 * - setPageTitle: Customize page titles
 * - setPackPrefix: Change pack prefix
 * - refresh: Revalidate state
 * - clear: Clear session
 * - apply: Execute operations
 *
 * @covers \LabkiPackManager\API\Packs\ApiLabkiPacksState
 * @covers \LabkiPackManager\Session\PackSessionState
 * @covers \LabkiPackManager\Services\PackStateStore
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
class ApiLabkiPacksStateTest extends ApiTestCase {

	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private LabkiPackRegistry $packRegistry;
	private PackStateStore $stateStore;
	private string $testWorktreePath;

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [
		'labki_content_repo',
		'labki_content_ref',
		'labki_pack',
		'labki_page',
		'labki_pack_dependency',
		'labki_operations',
		'job',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
		$this->stateStore = new PackStateStore();
		
		// Grant permissions
		$this->setGroupPermissions( 'user', 'labkipackmanager-manage', true );

		// Create test worktree
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_state_test_' . uniqid();
		mkdir( $this->testWorktreePath, 0777, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->testWorktreePath ) ) {
			$this->recursiveDelete( $this->testWorktreePath );
		}
		
		parent::tearDown();
	}

	private function recursiveDelete( string $dir ): void {
		if ( !is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->recursiveDelete( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function createTestManifest( array $packs ): void {
		$yaml = "schema_version: 1.0.0\n";
		$yaml .= "packs:\n";
		
		foreach ( $packs as $packName => $packDef ) {
			$yaml .= "  {$packName}:\n";
			$yaml .= "    version: {$packDef['version']}\n";
			$yaml .= "    prefix: " . ( $packDef['prefix'] ?? $packName ) . "\n";
			
			if ( !empty( $packDef['depends_on'] ) ) {
				$yaml .= "    depends_on:\n";
				foreach ( $packDef['depends_on'] as $dep ) {
					$yaml .= "      - {$dep}\n";
				}
			}
			
			if ( !empty( $packDef['pages'] ) ) {
				$yaml .= "    pages:\n";
				foreach ( $packDef['pages'] as $page ) {
					$yaml .= "      - {$page}\n";
				}
			}
		}

		file_put_contents( $this->testWorktreePath . '/manifest.yml', $yaml );
	}

	private function createTestRepo(): array {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://github.com/test/repo', [
			'default_ref' => 'main',
		] );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
			'last_commit' => 'abc123',
			'manifest_hash' => 'test-hash',
		] );
		
		return [ $repoId->toInt(), $refId->toInt() ];
	}

	public function testInitCommand(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'test pack' => [
				'version' => '1.0.0',
				'pages' => [ 'page1', 'page2' ],
			],
			'dep pack' => [
				'version' => '1.0.0',
				'pages' => [ 'page3' ],
			],
		] );

		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		$data = $result[0];
		
		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertArrayHasKey( 'state_hash', $data );
		$this->assertArrayHasKey( 'test pack', $data['packs'] );
		$this->assertArrayHasKey( 'dep pack', $data['packs'] );
		
		// Check pack structure
		$testPack = $data['packs']['test pack'];
		$this->assertFalse( $testPack['selected'] ); // Not installed yet
		$this->assertEquals( 'install', $testPack['action'] );
		$this->assertEquals( '1.0.0', $testPack['target_version'] );
		$this->assertArrayHasKey( 'pages', $testPack );
		$this->assertCount( 2, $testPack['pages'] );
	}

	public function testSelectCommand(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'pack a' => [
				'version' => '1.0.0',
				'pages' => [ 'page1' ],
			],
			'pack b' => [
				'version' => '1.0.0',
				'pages' => [ 'page2' ],
				'depends_on' => [ 'pack a' ],
			],
		] );

		// Init
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		// Select pack b (should auto-select pack a)
		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'select',
			'repo_id' => $repoId,
			'ref' => 'main',
			'payload' => json_encode( [ 'pack_name' => 'pack b' ] ),
		] );

		$data = $result[0];
		
		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'packs', $data );
		
		// Check that both packs are in the diff
		$this->assertArrayHasKey( 'pack b', $data['packs'] );
		$this->assertArrayHasKey( 'pack a', $data['packs'] );
		
		// pack b should be selected
		$this->assertTrue( $data['packs']['pack b']['selected'] );
		
		// pack a should be auto-selected
		$this->assertTrue( $data['packs']['pack a']['auto_selected'] );
		$this->assertStringContainsString( 'pack b', $data['packs']['pack a']['auto_selected_reason'] );
	}

	public function testDeselectCommand(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'pack a' => [
				'version' => '1.0.0',
				'pages' => [ 'page1' ],
			],
		] );

		// Init
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		// Select
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'select',
			'repo_id' => $repoId,
			'ref' => 'main',
			'payload' => json_encode( [ 'pack_name' => 'pack a' ] ),
		] );

		// Deselect
		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'deselect',
			'repo_id' => $repoId,
			'ref' => 'main',
			'payload' => json_encode( [ 'pack_name' => 'pack a' ] ),
		] );

		$data = $result[0];
		
		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'pack a', $data['packs'] );
		$this->assertFalse( $data['packs']['pack a']['selected'] );
	}

	public function testDeselectWithCascade(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'pack a' => [
				'version' => '1.0.0',
				'pages' => [ 'page1' ],
			],
			'pack b' => [
				'version' => '1.0.0',
				'pages' => [ 'page2' ],
				'depends_on' => [ 'pack a' ],
			],
		] );

		// Init and select pack b
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'select',
			'repo_id' => $repoId,
			'ref' => 'main',
			'payload' => json_encode( [ 'pack_name' => 'pack b' ] ),
		] );

		// Try to deselect pack a without cascade (should fail)
		try {
			$this->doApiRequestWithToken( [
				'action' => 'labkiPacksState',
				'command' => 'deselect',
				'repo_id' => $repoId,
				'ref' => 'main',
				'payload' => json_encode( [ 'pack_name' => 'pack a', 'cascade' => false ] ),
			] );
			$this->fail( 'Expected exception for cascade deselect' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'cascade', strtolower( $e->getMessage() ) );
		}

		// Deselect with cascade (should succeed)
		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'deselect',
			'repo_id' => $repoId,
			'ref' => 'main',
			'payload' => json_encode( [ 'pack_name' => 'pack a', 'cascade' => true ] ),
		] );

		$data = $result[0];
		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'cascade_deselected', $data );
		$this->assertContains( 'pack b', $data['cascade_deselected'] );
	}

	public function testSetPageTitleCommand(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'test pack' => [
				'version' => '1.0.0',
				'pages' => [ 'test page' ],
			],
		] );

		// Init
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		// Set page title
		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'setPageTitle',
			'repo_id' => $repoId,
			'ref' => 'main',
			'payload' => json_encode( [
				'pack_name' => 'test pack',
				'page_name' => 'test page',
				'final_title' => 'Custom/NewTitle',
			] ),
		] );

		$data = $result[0];
		
		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertArrayHasKey( 'test pack', $data['packs'] );
		
		// Only the page should be in the diff
		$this->assertArrayHasKey( 'pages', $data['packs']['test pack'] );
		$this->assertEquals( 'Custom/NewTitle', $data['packs']['test pack']['pages']['test page']['final_title'] );
	}

	public function testSetPackPrefixCommand(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'test pack' => [
				'version' => '1.0.0',
				'prefix' => 'OldPrefix',
				'pages' => [ 'page1', 'page2' ],
			],
		] );

		// Init
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		// Set pack prefix
		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'setPackPrefix',
			'repo_id' => $repoId,
			'ref' => 'main',
			'payload' => json_encode( [
				'pack_name' => 'test pack',
				'prefix' => 'NewPrefix',
			] ),
		] );

		$data = $result[0];
		
		$this->assertTrue( $data['ok'] );
		$this->assertEquals( 'NewPrefix', $data['packs']['test pack']['prefix'] );
		
		// All page titles should be updated
		$this->assertEquals( 'NewPrefix/page1', $data['packs']['test pack']['pages']['page1']['default_title'] );
		$this->assertEquals( 'NewPrefix/page2', $data['packs']['test pack']['pages']['page2']['default_title'] );
	}

	public function testRefreshCommand(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'test pack' => [
				'version' => '1.0.0',
				'pages' => [ 'page1' ],
			],
		] );

		// Init
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		// Refresh
		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'refresh',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		$data = $result[0];
		
		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'packs', $data );
		$this->assertArrayHasKey( 'state_hash', $data );
	}

	public function testClearCommand(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'test pack' => [
				'version' => '1.0.0',
				'pages' => [ 'page1' ],
			],
		] );

		// Init
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		// Clear
		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'clear',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		$data = $result[0];
		$this->assertTrue( $data['ok'] );
		$this->assertArrayNotHasKey( 'packs', $data );
	}

	public function testDeepDiffOnlyReturnsChangedFields(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'pack a' => [
				'version' => '1.0.0',
				'pages' => [ 'page1', 'page2' ],
			],
			'pack b' => [
				'version' => '1.0.0',
				'pages' => [ 'page3' ],
			],
		] );

		// Init
		$this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'init',
			'repo_id' => $repoId,
			'ref' => 'main',
		] );

		// Select pack a
		$result = $this->doApiRequestWithToken( [
			'action' => 'labkiPacksState',
			'command' => 'select',
			'repo_id' => $repoId,
			'ref' => 'main',
			'payload' => json_encode( [ 'pack_name' => 'pack a' ] ),
		] );

		$data = $result[0];
		
		// Only pack a should be in diff, not pack b
		$this->assertArrayHasKey( 'pack a', $data['packs'] );
		$this->assertArrayNotHasKey( 'pack b', $data['packs'] );
		
		// Only the 'selected' field should be present, not all fields
		$this->assertArrayHasKey( 'selected', $data['packs']['pack a'] );
		$this->assertTrue( $data['packs']['pack a']['selected'] );
	}

	public function testErrorOnInvalidCommand(): void {
		list( $repoId, $refId ) = $this->createTestRepo();

		try {
			$this->doApiRequestWithToken( [
				'action' => 'labkiPacksState',
				'command' => 'invalid_command',
				'repo_id' => $repoId,
				'ref' => 'main',
			] );
			$this->fail( 'Expected exception for invalid command' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'unrecognized', strtolower( $e->getMessage() ) );
		}
	}

	public function testErrorOnMissingState(): void {
		list( $repoId, $refId ) = $this->createTestRepo();
		
		$this->createTestManifest( [
			'test pack' => [
				'version' => '1.0.0',
				'pages' => [ 'page1' ],
			],
		] );
		
		// Try to select a pack without initializing state first
		try {
			$this->doApiRequestWithToken( [
				'action' => 'labkiPacksState',
				'command' => 'select',
				'repo_id' => $repoId,
				'ref' => 'main',
				'payload' => json_encode( [ 'pack_name' => 'test pack' ] ),
			] );
			$this->fail( 'Expected exception for missing state' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'state', strtolower( $e->getMessage() ) );
		}
	}
}

