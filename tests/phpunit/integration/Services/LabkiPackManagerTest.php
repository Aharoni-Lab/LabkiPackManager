<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Services\LabkiPackManager;
use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Domain\ContentRefId;
use LabkiPackManager\Domain\PackId;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for LabkiPackManager service.
 *
 * Tests core pack management operations:
 * - Pack installation with pages
 * - Dependency validation
 * - Pack updates
 * - Pack removal
 *
 * @covers \LabkiPackManager\Services\LabkiPackManager
 * @group Database
 * @group LabkiPackManager
 */
class LabkiPackManagerTest extends MediaWikiIntegrationTestCase {

	private LabkiPackManager $packManager;
	private LabkiRepoRegistry $repoRegistry;
	private LabkiRefRegistry $refRegistry;
	private LabkiPackRegistry $packRegistry;
	private LabkiPageRegistry $pageRegistry;
	private string $testWorktreePath;

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [
		'labki_content_repo',
		'labki_content_ref',
		'labki_pack',
		'labki_page',
	];

	protected function setUp(): void {
		parent::setUp();
		
		$this->packManager = new LabkiPackManager();
		$this->repoRegistry = new LabkiRepoRegistry();
		$this->refRegistry = new LabkiRefRegistry();
		$this->packRegistry = new LabkiPackRegistry();
		$this->pageRegistry = new LabkiPageRegistry();

		// Create temporary directory for test worktree
		$this->testWorktreePath = sys_get_temp_dir() . '/labki_test_worktree_' . uniqid();
		mkdir( $this->testWorktreePath, 0777, true );
	}

	protected function tearDown(): void {
		// Clean up test worktree
		if ( is_dir( $this->testWorktreePath ) ) {
			$this->recursiveDelete( $this->testWorktreePath );
		}
		
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 */
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

	/**
	 * Create a test ref with worktree.
	 */
	private function createTestRef(): ContentRefId {
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => $this->testWorktreePath,
		] );

		return $refId;
	}

	/**
	 * Create a test manifest file in the worktree.
	 */
	private function createTestManifest( array $packs = [], array $pages = [] ): void {
		$yaml = "schema_version: 1\n";
		
		// Add packs
		if ( !empty( $packs ) ) {
			$yaml .= "packs:\n";
			foreach ( $packs as $packName => $packDef ) {
				$yaml .= "  {$packName}:\n";
				$yaml .= "    name: {$packName}\n";
				if ( isset( $packDef['version'] ) ) {
					$yaml .= "    version: \"{$packDef['version']}\"\n";
				}
				if ( isset( $packDef['depends_on'] ) && is_array( $packDef['depends_on'] ) ) {
					if ( empty( $packDef['depends_on'] ) ) {
						$yaml .= "    depends_on: []\n";
					} else {
						$yaml .= "    depends_on:\n";
						foreach ( $packDef['depends_on'] as $dep ) {
							$yaml .= "      - {$dep}\n";
						}
					}
				}
				if ( isset( $packDef['pages'] ) && is_array( $packDef['pages'] ) ) {
					if ( empty( $packDef['pages'] ) ) {
						$yaml .= "    pages: []\n";
					} else {
						$yaml .= "    pages:\n";
						foreach ( $packDef['pages'] as $page ) {
							$yaml .= "      - {$page}\n";
						}
					}
				}
			}
		}
		
		// Add pages
		if ( !empty( $pages ) ) {
			$yaml .= "pages:\n";
			foreach ( $pages as $pageName => $pageDef ) {
				$yaml .= "  {$pageName}:\n";
				if ( isset( $pageDef['file'] ) ) {
					$yaml .= "    file: {$pageDef['file']}\n";
				}
			}
		}

		$manifestPath = $this->testWorktreePath . '/manifest.yml';
		file_put_contents( $manifestPath, $yaml );
	}

	/**
	 * Create a test page file in the worktree.
	 */
	private function createTestPageFile( string $relPath, string $content ): void {
		$fullPath = $this->testWorktreePath . '/' . ltrim( $relPath, '/' );
		$dir = dirname( $fullPath );
		
		if ( !is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		
		file_put_contents( $fullPath, $content );
	}

	public function testValidatePackDependencies_NoDependencies_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [
				'name' => 'PackA',
				'version' => '1.0.0',
				'depends_on' => [],
				'pages' => [],
			],
		] );

		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackA' ] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Should return empty array when no dependencies' );
	}

	public function testValidatePackDependencies_MissingDependency_ReturnsMissing(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [
				'name' => 'PackA',
				'depends_on' => [],
				'pages' => [],
			],
			'PackB' => [
				'name' => 'PackB',
				'depends_on' => [ 'PackA' ],
				'pages' => [],
			],
		] );

		// Try to install PackB without PackA
		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackB' ] );

		$this->assertIsArray( $result );
		$this->assertContains( 'PackA', $result, 'Should identify PackA as missing dependency' );
	}

	public function testValidatePackDependencies_DependencyInRequest_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [
				'name' => 'PackA',
				'depends_on' => [],
				'pages' => [],
			],
			'PackB' => [
				'name' => 'PackB',
				'depends_on' => [ 'PackA' ],
				'pages' => [],
			],
		] );

		// Install both PackA and PackB together
		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackA', 'PackB' ] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Should return empty when dependency is in request' );
	}

	public function testValidatePackDependencies_DependencyAlreadyInstalled_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [
				'name' => 'PackA',
				'depends_on' => [],
				'pages' => [],
			],
			'PackB' => [
				'name' => 'PackB',
				'depends_on' => [ 'PackA' ],
				'pages' => [],
			],
		] );

		// Install PackA first
		$this->packRegistry->registerPack( $refId, 'PackA', '1.0.0', 1 );

		// Now try to install PackB
		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackB' ] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Should return empty when dependency is already installed' );
	}

	public function testValidatePackDependencies_MultipleMissingDependencies_ReturnsAll(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [ 'depends_on' => [], 'pages' => [] ],
			'PackB' => [ 'depends_on' => [], 'pages' => [] ],
			'PackC' => [ 'depends_on' => [ 'PackA', 'PackB' ], 'pages' => [] ],
		] );

		// Try to install PackC without dependencies
		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackC' ] );

		$this->assertIsArray( $result );
		$this->assertContains( 'PackA', $result );
		$this->assertContains( 'PackB', $result );
		$this->assertCount( 2, $result );
	}

	public function testInstallPacks_SinglePack_Success(): void {
		$refId = $this->createTestRef();
		
		// Create manifest
		$this->createTestManifest(
			[
				'TestPack' => [
					'name' => 'TestPack',
					'version' => '1.0.0',
					'pages' => [ 'TestPage' ],
				],
			],
			[
				'TestPage' => [
					'file' => 'pages/TestPage.wiki',
				],
			]
		);

		// Create page file
		$this->createTestPageFile( 'pages/TestPage.wiki', '== Test Page ==' );

		// Install pack
		$packDef = [
			'name' => 'TestPack',
			'version' => '1.0.0',
			'pages' => [
				[
					'name' => 'TestPage',
					'original' => 'TestPage',
					'final_title' => 'TestPack/TestPage',
				],
			],
		];

		$result = $this->packManager->installPacks( $refId, [ $packDef ], 1 );

		$this->assertTrue( $result['success'], 'Installation should succeed' );
		$this->assertCount( 1, $result['installed'] );
		$this->assertEmpty( $result['failed'] );
		
		$installed = $result['installed'][0];
		$this->assertEquals( 'TestPack', $installed['pack'] );
		$this->assertEquals( '1.0.0', $installed['version'] );
		$this->assertEquals( 1, $installed['pages_created'] );
		$this->assertEquals( 0, $installed['pages_failed'] );
	}

	public function testInstallPacks_MultiplePacks_Success(): void {
		$refId = $this->createTestRef();
		
		// Create manifest
		$this->createTestManifest(
			[
				'PackA' => [ 'name' => 'PackA', 'pages' => [ 'PageA' ] ],
				'PackB' => [ 'name' => 'PackB', 'pages' => [ 'PageB' ] ],
			],
			[
				'PageA' => [ 'file' => 'pages/PageA.wiki' ],
				'PageB' => [ 'file' => 'pages/PageB.wiki' ],
			]
		);

		// Create page files
		$this->createTestPageFile( 'pages/PageA.wiki', '== Page A ==' );
		$this->createTestPageFile( 'pages/PageB.wiki', '== Page B ==' );

		// Install packs
		$packs = [
			[
				'name' => 'PackA',
				'version' => '1.0.0',
				'pages' => [
					[ 'name' => 'PageA', 'original' => 'PageA', 'final_title' => 'PackA/PageA' ],
				],
			],
			[
				'name' => 'PackB',
				'version' => '1.0.0',
				'pages' => [
					[ 'name' => 'PageB', 'original' => 'PageB', 'final_title' => 'PackB/PageB' ],
				],
			],
		];

		$result = $this->packManager->installPacks( $refId, $packs, 1 );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['installed'] );
		$this->assertEmpty( $result['failed'] );
	}

	public function testInstallPacks_MissingPageFile_ReportsError(): void {
		$refId = $this->createTestRef();
		
		// Create manifest but no page file
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'pages' => [ 'MissingPage' ] ] ],
			[ 'MissingPage' => [ 'file' => 'pages/Missing.wiki' ] ]
		);

		// Try to install pack
		$packDef = [
			'name' => 'TestPack',
			'version' => '1.0.0',
			'pages' => [
				[ 'name' => 'MissingPage', 'original' => 'MissingPage', 'final_title' => 'Test/Missing' ],
			],
		];

		$result = $this->packManager->installPacks( $refId, [ $packDef ], 1 );

		$this->assertFalse( $result['success'], 'Should fail when page file is missing' );
		$this->assertCount( 1, $result['failed'] );
		$this->assertEmpty( $result['installed'] );
	}

	public function testInstallPacks_EmptyPackList_ReturnsSuccess(): void {
		$refId = $this->createTestRef();
		$this->createTestManifest();

		// Install empty pack list (should succeed trivially)
		$result = $this->packManager->installPacks( $refId, [], 1 );

		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $result['installed'] );
		$this->assertEmpty( $result['failed'] );
	}

	public function testInstallPacks_InvalidRef_ReturnsError(): void {
		$invalidRefId = new ContentRefId( 99999 );

		$result = $this->packManager->installPacks( $invalidRefId, [], 1 );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Ref not found', $result['errors'][0] );
	}

	public function testRemovePack_Success(): void {
		$refId = $this->createTestRef();
		
		// Create and install a pack with a real page in MediaWiki
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );
		
		// Create a real MediaWiki page
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$title = $services->getTitleFactory()->newFromText( 'TestPack/Page1' );
		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
		$user = $this->getTestUser()->getUser();
		$content = new \WikitextContent( 'Test content' );
		$status = $wikiPage->doUserEditContent( $content, $user, 'Test' );
		$this->assertTrue( $status->isOK() );
		
		// Register in page registry
		$this->pageRegistry->addPage( $packId, [
			'name' => 'Page1',
			'final_title' => 'TestPack/Page1',
			'page_namespace' => 0,
			'wiki_page_id' => $wikiPage->getId(),
		] );

		// Remove pack (userId from test user)
		$result = $this->packManager->removePack( $packId, $user->getId() );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'TestPack', $result['pack'] );
		$this->assertEquals( 1, $result['pages_total'] );
		$this->assertEquals( 1, $result['pages_removed_from_registry'] );

		// Verify pack is removed from registry
		$pack = $this->packRegistry->getPack( $packId );
		$this->assertNull( $pack );
	}

	public function testRemovePack_InvalidPackId_ReturnsError(): void {
		$invalidPackId = new PackId( 99999 );

		$result = $this->packManager->removePack( $invalidPackId, 1 );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Pack not found', $result['error'] );
	}

	public function testUpdatePack_UpdatesVersionAndPages(): void {
		$refId = $this->createTestRef();
		
		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'pages' => [ 'UpdatedPage' ] ] ],
			[ 'UpdatedPage' => [ 'file' => 'pages/Updated.wiki' ] ]
		);
		$this->createTestPageFile( 'pages/Updated.wiki', '== Updated Content ==' );

		// Install initial version
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		// Update pack
		$packDef = [
			'name' => 'TestPack',
			'version' => '2.0.0',
			'pages' => [
				[ 'name' => 'UpdatedPage', 'original' => 'UpdatedPage', 'final_title' => 'TestPack/UpdatedPage' ],
			],
		];

		$result = $this->packManager->updatePack( $packId, $refId, $packDef, 1 );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'TestPack', $result['pack'] );
		$this->assertEquals( '1.0.0', $result['old_version'] );
		$this->assertEquals( '2.0.0', $result['new_version'] );
		$this->assertEquals( 1, $result['pages_updated'] );
	}

	public function testUpdatePack_InvalidPackId_ReturnsError(): void {
		$refId = $this->createTestRef();
		$invalidPackId = new PackId( 99999 );

		// Need valid pack definition with required fields
		$packDef = [
			'name' => 'InvalidPack',
			'version' => '1.0.0',
			'pages' => [],
		];

		$result = $this->packManager->updatePack( $invalidPackId, $refId, $packDef, 1 );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function testValidatePackRemoval_NoDependencies_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with independent packs
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [], 'pages' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [], 'pages' => [] ],
			],
			[]
		);

		// Install both packs
		$this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$this->packRegistry->registerPack( $refId, 'Pack2', '1.0.0', 1 );

		// Try to remove Pack1 (no dependencies)
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'Pack1' ] );

		$this->assertEmpty( $blockingDeps, 'Should have no blocking dependencies' );
	}

	public function testValidatePackRemoval_WithBlockingDependency_ReturnsDependents(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with dependencies
		$this->createTestManifest(
			[
				'BasePackage' => [ 'name' => 'BasePackage', 'depends_on' => [], 'pages' => [] ],
				'DependentPackage' => [ 'name' => 'DependentPackage', 'depends_on' => [ 'BasePackage' ], 'pages' => [] ],
			],
			[]
		);

		// Install both packs
		$basePackId = $this->packRegistry->registerPack( $refId, 'BasePackage', '1.0.0', 1 );
		$depPackId = $this->packRegistry->registerPack( $refId, 'DependentPackage', '1.0.0', 1 );

		// Store dependency in database (DependentPackage depends on BasePackage)
		$this->packRegistry->storeDependencies( $depPackId, [ $basePackId ] );

		// Try to remove BasePackage (DependentPackage depends on it)
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'BasePackage' ] );

		$this->assertNotEmpty( $blockingDeps, 'Should have blocking dependencies' );
		$this->assertArrayHasKey( 'BasePackage', $blockingDeps );
		$this->assertContains( 'DependentPackage', $blockingDeps['BasePackage'] );
	}

	public function testValidatePackRemoval_RemovingBothDependentAndDependency_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with dependencies
		$this->createTestManifest(
			[
				'BasePackage' => [ 'name' => 'BasePackage', 'depends_on' => [], 'pages' => [] ],
				'DependentPackage' => [ 'name' => 'DependentPackage', 'depends_on' => [ 'BasePackage' ], 'pages' => [] ],
			],
			[]
		);

		// Install both packs
		$basePackId = $this->packRegistry->registerPack( $refId, 'BasePackage', '1.0.0', 1 );
		$depPackId = $this->packRegistry->registerPack( $refId, 'DependentPackage', '1.0.0', 1 );

		// Store dependency in database
		$this->packRegistry->storeDependencies( $depPackId, [ $basePackId ] );

		// Try to remove both packs together
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'BasePackage', 'DependentPackage' ] );

		$this->assertEmpty( $blockingDeps, 'Should have no blocking dependencies when removing both' );
	}

	public function testValidatePackRemoval_WithMultipleDependents_ReturnsAllDependents(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with multiple dependents
		$this->createTestManifest(
			[
				'CorePackage' => [ 'name' => 'CorePackage', 'depends_on' => [], 'pages' => [] ],
				'Dependent1' => [ 'name' => 'Dependent1', 'depends_on' => [ 'CorePackage' ], 'pages' => [] ],
				'Dependent2' => [ 'name' => 'Dependent2', 'depends_on' => [ 'CorePackage' ], 'pages' => [] ],
				'Dependent3' => [ 'name' => 'Dependent3', 'depends_on' => [ 'CorePackage' ], 'pages' => [] ],
			],
			[]
		);

		// Install all packs
		$corePackId = $this->packRegistry->registerPack( $refId, 'CorePackage', '1.0.0', 1 );
		$dep1PackId = $this->packRegistry->registerPack( $refId, 'Dependent1', '1.0.0', 1 );
		$dep2PackId = $this->packRegistry->registerPack( $refId, 'Dependent2', '1.0.0', 1 );
		$dep3PackId = $this->packRegistry->registerPack( $refId, 'Dependent3', '1.0.0', 1 );

		// Store dependencies in database
		$this->packRegistry->storeDependencies( $dep1PackId, [ $corePackId ] );
		$this->packRegistry->storeDependencies( $dep2PackId, [ $corePackId ] );
		$this->packRegistry->storeDependencies( $dep3PackId, [ $corePackId ] );

		// Try to remove CorePackage
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'CorePackage' ] );

		$this->assertNotEmpty( $blockingDeps );
		$this->assertArrayHasKey( 'CorePackage', $blockingDeps );
		$this->assertCount( 3, $blockingDeps['CorePackage'], 'Should list all three dependents' );
		$this->assertContains( 'Dependent1', $blockingDeps['CorePackage'] );
		$this->assertContains( 'Dependent2', $blockingDeps['CorePackage'] );
		$this->assertContains( 'Dependent3', $blockingDeps['CorePackage'] );
	}

	public function testValidatePackRemoval_WithChainedDependencies_ReturnsCorrectDependents(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with chained dependencies: Pack3 -> Pack2 -> Pack1
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [], 'pages' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [ 'Pack1' ], 'pages' => [] ],
				'Pack3' => [ 'name' => 'Pack3', 'depends_on' => [ 'Pack2' ], 'pages' => [] ],
			],
			[]
		);

		// Install all packs
		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$pack2Id = $this->packRegistry->registerPack( $refId, 'Pack2', '1.0.0', 1 );
		$pack3Id = $this->packRegistry->registerPack( $refId, 'Pack3', '1.0.0', 1 );

		// Store dependencies in database (Pack3 -> Pack2 -> Pack1)
		$this->packRegistry->storeDependencies( $pack2Id, [ $pack1Id ] );
		$this->packRegistry->storeDependencies( $pack3Id, [ $pack2Id ] );

		// Try to remove Pack1 (Pack2 depends on it)
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'Pack1' ] );

		$this->assertNotEmpty( $blockingDeps );
		$this->assertArrayHasKey( 'Pack1', $blockingDeps );
		$this->assertContains( 'Pack2', $blockingDeps['Pack1'] );

		// Try to remove Pack2 (Pack3 depends on it)
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'Pack2' ] );

		$this->assertNotEmpty( $blockingDeps );
		$this->assertArrayHasKey( 'Pack2', $blockingDeps );
		$this->assertContains( 'Pack3', $blockingDeps['Pack2'] );

		// Try to remove Pack3 (nothing depends on it)
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'Pack3' ] );

		$this->assertEmpty( $blockingDeps, 'Pack3 has no dependents' );
	}

	public function testValidatePackRemoval_OnlyChecksInstalledPacks_IgnoresUninstalled(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with dependencies
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [], 'pages' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [ 'Pack1' ], 'pages' => [] ],
			],
			[]
		);

		// Install only Pack1 (Pack2 is in manifest but not installed)
		$this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );

		// Try to remove Pack1 (Pack2 is not installed, so no blocking dependency)
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'Pack1' ] );

		$this->assertEmpty( $blockingDeps, 'Should ignore uninstalled packs' );
	}

	public function testValidatePackRemoval_WithMultiplePacksToRemove_ChecksAll(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with complex dependencies
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [], 'pages' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [], 'pages' => [] ],
				'Dependent1' => [ 'name' => 'Dependent1', 'depends_on' => [ 'Pack1' ], 'pages' => [] ],
				'Dependent2' => [ 'name' => 'Dependent2', 'depends_on' => [ 'Pack2' ], 'pages' => [] ],
			],
			[]
		);

		// Install all packs
		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$pack2Id = $this->packRegistry->registerPack( $refId, 'Pack2', '1.0.0', 1 );
		$dep1Id = $this->packRegistry->registerPack( $refId, 'Dependent1', '1.0.0', 1 );
		$dep2Id = $this->packRegistry->registerPack( $refId, 'Dependent2', '1.0.0', 1 );

		// Store dependencies in database
		$this->packRegistry->storeDependencies( $dep1Id, [ $pack1Id ] );
		$this->packRegistry->storeDependencies( $dep2Id, [ $pack2Id ] );

		// Try to remove both Pack1 and Pack2 (both have dependents)
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'Pack1', 'Pack2' ] );

		$this->assertNotEmpty( $blockingDeps );
		$this->assertCount( 2, $blockingDeps, 'Should have blocking dependencies for both packs' );
		$this->assertArrayHasKey( 'Pack1', $blockingDeps );
		$this->assertArrayHasKey( 'Pack2', $blockingDeps );
		$this->assertContains( 'Dependent1', $blockingDeps['Pack1'] );
		$this->assertContains( 'Dependent2', $blockingDeps['Pack2'] );
	}

	public function testValidatePacksInstalled_AllInstalled_ReturnsEmpty(): void {
		$refId = $this->createTestRef();

		// Install packs
		$this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$this->packRegistry->registerPack( $refId, 'Pack2', '1.0.0', 1 );

		$notInstalled = $this->packManager->validatePacksInstalled( $refId, [ 'Pack1', 'Pack2' ] );

		$this->assertEmpty( $notInstalled, 'Should return empty when all packs are installed' );
	}

	public function testValidatePacksInstalled_SomeNotInstalled_ReturnsNotInstalled(): void {
		$refId = $this->createTestRef();

		// Install only Pack1
		$this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );

		$notInstalled = $this->packManager->validatePacksInstalled( $refId, [ 'Pack1', 'Pack2', 'Pack3' ] );

		$this->assertCount( 2, $notInstalled );
		$this->assertContains( 'Pack2', $notInstalled );
		$this->assertContains( 'Pack3', $notInstalled );
		$this->assertNotContains( 'Pack1', $notInstalled );
	}

	public function testValidatePacksInstalled_NoneInstalled_ReturnsAll(): void {
		$refId = $this->createTestRef();

		// Don't install any packs
		$notInstalled = $this->packManager->validatePacksInstalled( $refId, [ 'Pack1', 'Pack2' ] );

		$this->assertCount( 2, $notInstalled );
		$this->assertContains( 'Pack1', $notInstalled );
		$this->assertContains( 'Pack2', $notInstalled );
	}

	public function testValidatePackVersions_MinorUpdate_ReturnsEmpty(): void {
		$refId = $this->createTestRef();

		// Create manifest with updated version
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.5.0', 'depends_on' => [], 'pages' => [] ] ],
			[]
		);

		// Install pack with older version
		$this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$errors = $this->packManager->validatePackVersions( $refId, [
			[ 'name' => 'TestPack', 'target_version' => '1.5.0' ],
		] );

		$this->assertEmpty( $errors, 'Minor version update should be allowed' );
	}

	public function testValidatePackVersions_PatchUpdate_ReturnsEmpty(): void {
		$refId = $this->createTestRef();

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '1.0.5', 'depends_on' => [], 'pages' => [] ] ],
			[]
		);

		$this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );

		$errors = $this->packManager->validatePackVersions( $refId, [
			[ 'name' => 'TestPack', 'target_version' => '1.0.5' ],
		] );

		$this->assertEmpty( $errors, 'Patch version update should be allowed' );
	}

	public function testValidatePackVersions_MajorVersionChange_ReturnsError(): void {
		$refId = $this->createTestRef();

		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack', 'version' => '2.0.0', 'depends_on' => [], 'pages' => [] ] ],
			[]
		);

		$this->packRegistry->registerPack( $refId, 'TestPack', '1.5.0', 1 );

		$errors = $this->packManager->validatePackVersions( $refId, [
			[ 'name' => 'TestPack', 'target_version' => '2.0.0' ],
		] );

		$this->assertNotEmpty( $errors );
		$this->assertArrayHasKey( 'TestPack', $errors );
		$this->assertStringContainsString( 'Major version cannot change', $errors['TestPack'] );
	}

	public function testValidatePackVersions_MultiplePacksMixedResults_ReturnsOnlyErrors(): void {
		$refId = $this->createTestRef();

		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'version' => '1.5.0', 'depends_on' => [], 'pages' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'version' => '3.0.0', 'depends_on' => [], 'pages' => [] ],
			],
			[]
		);

		$this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$this->packRegistry->registerPack( $refId, 'Pack2', '2.5.0', 1 );

		$errors = $this->packManager->validatePackVersions( $refId, [
			[ 'name' => 'Pack1', 'target_version' => '1.5.0' ], // OK: 1.x → 1.x
			[ 'name' => 'Pack2', 'target_version' => '3.0.0' ], // ERROR: 2.x → 3.x
		] );

		$this->assertCount( 1, $errors, 'Should only return errors for Pack2' );
		$this->assertArrayHasKey( 'Pack2', $errors );
		$this->assertArrayNotHasKey( 'Pack1', $errors );
	}

	public function testValidatePackUpdateDependencies_NoDependents_ReturnsEmpty(): void {
		$refId = $this->createTestRef();

		// Install pack without any dependents
		$this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );

		$errors = $this->packManager->validatePackUpdateDependencies( $refId, [ 'Pack1' ] );

		$this->assertEmpty( $errors, 'Should return empty when pack has no dependents' );
	}

	public function testValidatePackUpdateDependencies_DependentNotBeingUpdated_ReturnsError(): void {
		$refId = $this->createTestRef();

		// Install base pack and dependent pack
		$basePackId = $this->packRegistry->registerPack( $refId, 'BasePackage', '1.0.0', 1 );
		$depPackId = $this->packRegistry->registerPack( $refId, 'DependentPackage', '1.0.0', 1 );

		// Store dependency
		$this->packRegistry->storeDependencies( $depPackId, [ $basePackId ] );

		// Try to update BasePackage alone
		$errors = $this->packManager->validatePackUpdateDependencies( $refId, [ 'BasePackage' ] );

		$this->assertNotEmpty( $errors );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'BasePackage', $errors[0] );
		$this->assertStringContainsString( 'DependentPackage', $errors[0] );
	}

	public function testValidatePackUpdateDependencies_BothBeingUpdated_ReturnsEmpty(): void {
		$refId = $this->createTestRef();

		$basePackId = $this->packRegistry->registerPack( $refId, 'BasePackage', '1.0.0', 1 );
		$depPackId = $this->packRegistry->registerPack( $refId, 'DependentPackage', '1.0.0', 1 );

		$this->packRegistry->storeDependencies( $depPackId, [ $basePackId ] );

		// Update both together
		$errors = $this->packManager->validatePackUpdateDependencies( $refId, [ 'BasePackage', 'DependentPackage' ] );

		$this->assertEmpty( $errors, 'Should allow update when both base and dependent are being updated' );
	}

	public function testValidatePackUpdateDependencies_ChainedDependencies_ValidatesAll(): void {
		$refId = $this->createTestRef();

		// Create chain: Pack1 ← Pack2 ← Pack3
		$pack1Id = $this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );
		$pack2Id = $this->packRegistry->registerPack( $refId, 'Pack2', '1.0.0', 1 );
		$pack3Id = $this->packRegistry->registerPack( $refId, 'Pack3', '1.0.0', 1 );

		$this->packRegistry->storeDependencies( $pack2Id, [ $pack1Id ] );
		$this->packRegistry->storeDependencies( $pack3Id, [ $pack2Id ] );

		// Try to update Pack1 alone (Pack2 depends on it)
		$errors = $this->packManager->validatePackUpdateDependencies( $refId, [ 'Pack1' ] );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Pack1', $errors[0] );
		$this->assertStringContainsString( 'Pack2', $errors[0] );
	}

	public function testValidatePackUpdateDependencies_MultipleDependents_ReturnsAllErrors(): void {
		$refId = $this->createTestRef();

		// Create: CorePackage ← Dependent1, Dependent2, Dependent3
		$corePackId = $this->packRegistry->registerPack( $refId, 'CorePackage', '1.0.0', 1 );
		$dep1Id = $this->packRegistry->registerPack( $refId, 'Dependent1', '1.0.0', 1 );
		$dep2Id = $this->packRegistry->registerPack( $refId, 'Dependent2', '1.0.0', 1 );
		$dep3Id = $this->packRegistry->registerPack( $refId, 'Dependent3', '1.0.0', 1 );

		$this->packRegistry->storeDependencies( $dep1Id, [ $corePackId ] );
		$this->packRegistry->storeDependencies( $dep2Id, [ $corePackId ] );
		$this->packRegistry->storeDependencies( $dep3Id, [ $corePackId ] );

		// Try to update CorePackage alone
		$errors = $this->packManager->validatePackUpdateDependencies( $refId, [ 'CorePackage' ] );

		$this->assertCount( 3, $errors, 'Should return error for each dependent' );
		foreach ( $errors as $error ) {
			$this->assertStringContainsString( 'CorePackage', $error );
		}
	}
}
