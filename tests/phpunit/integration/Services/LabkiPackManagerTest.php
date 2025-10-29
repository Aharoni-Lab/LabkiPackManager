<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Services;

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
 * @coversDefaultClass \LabkiPackManager\Services\LabkiPackManager
 * @group Database
 * @group LabkiPackManager
 */
final class LabkiPackManagerTest extends MediaWikiIntegrationTestCase {

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

	/**
	 * @covers ::validatePackDependencies
	 */
	public function testValidatePackDependencies_NoDependencies_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [
				'name' => 'PackA',
				'version' => '1.0.0',
				'depends_on' => [],
			],
		] );

		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackA' ] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Should return empty array when no dependencies' );
	}

	/**
	 * @covers ::validatePackDependencies
	 */
	public function testValidatePackDependencies_MissingDependency_ReturnsMissing(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [
				'name' => 'PackA',
				'depends_on' => [],
			],
			'PackB' => [
				'name' => 'PackB',
				'depends_on' => [ 'PackA' ],
			],
		] );

		// Try to install PackB without PackA
		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackB' ] );

		$this->assertIsArray( $result );
		$this->assertContains( 'PackA', $result, 'Should identify PackA as missing dependency' );
	}

	/**
	 * @covers ::validatePackDependencies
	 */
	public function testValidatePackDependencies_DependencyInRequest_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [
				'name' => 'PackA',
				'depends_on' => [],
			],
			'PackB' => [
				'name' => 'PackB',
				'depends_on' => [ 'PackA' ],
			],
		] );

		// Install both PackA and PackB together
		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackA', 'PackB' ] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Should return empty when dependency is in request' );
	}

	/**
	 * @covers ::validatePackDependencies
	 */
	public function testValidatePackDependencies_DependencyAlreadyInstalled_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [
				'name' => 'PackA',
				'depends_on' => [],
			],
			'PackB' => [
				'name' => 'PackB',
				'depends_on' => [ 'PackA' ],
			],
		] );

		// Install PackA first
		$this->packRegistry->registerPack( $refId, 'PackA', '1.0.0', 1 );

		// Now try to install PackB
		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackB' ] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Should return empty when dependency is already installed' );
	}

	/**
	 * @covers ::validatePackDependencies
	 */
	public function testValidatePackDependencies_MultipleMissingDependencies_ReturnsAll(): void {
		$refId = $this->createTestRef();
		
		$this->createTestManifest( [
			'PackA' => [ 'depends_on' => [] ],
			'PackB' => [ 'depends_on' => [] ],
			'PackC' => [ 'depends_on' => [ 'PackA', 'PackB' ] ],
		] );

		// Try to install PackC without dependencies
		$result = $this->packManager->validatePackDependencies( $refId, [ 'PackC' ] );

		$this->assertIsArray( $result );
		$this->assertContains( 'PackA', $result );
		$this->assertContains( 'PackB', $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * @covers ::installPacks
	 */
	public function testInstallPacks_SinglePack_Success(): void {
		$refId = $this->createTestRef();
		
		// Create manifest
		$this->createTestManifest(
			[
				'TestPack' => [
					'name' => 'TestPack',
					'version' => '1.0.0',
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
					'finalTitle' => 'TestPack/TestPage',
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

	/**
	 * @covers ::installPacks
	 */
	public function testInstallPacks_MultiplePacks_Success(): void {
		$refId = $this->createTestRef();
		
		// Create manifest
		$this->createTestManifest(
			[
				'PackA' => [ 'name' => 'PackA' ],
				'PackB' => [ 'name' => 'PackB' ],
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
				'pages' => [
					[ 'name' => 'PageA', 'original' => 'PageA', 'finalTitle' => 'PackA/PageA' ],
				],
			],
			[
				'name' => 'PackB',
				'pages' => [
					[ 'name' => 'PageB', 'original' => 'PageB', 'finalTitle' => 'PackB/PageB' ],
				],
			],
		];

		$result = $this->packManager->installPacks( $refId, $packs, 1 );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['installed'] );
		$this->assertEmpty( $result['failed'] );
	}

	/**
	 * @covers ::installPacks
	 */
	public function testInstallPacks_MissingPageFile_ReportsError(): void {
		$refId = $this->createTestRef();
		
		// Create manifest but no page file
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack' ] ],
			[ 'MissingPage' => [ 'file' => 'pages/Missing.wiki' ] ]
		);

		// Try to install pack
		$packDef = [
			'name' => 'TestPack',
			'pages' => [
				[ 'name' => 'MissingPage', 'original' => 'MissingPage', 'finalTitle' => 'Test/Missing' ],
			],
		];

		$result = $this->packManager->installPacks( $refId, [ $packDef ], 1 );

		$this->assertFalse( $result['success'], 'Should fail when page file is missing' );
		$this->assertCount( 1, $result['failed'] );
		$this->assertEmpty( $result['installed'] );
	}

	/**
	 * @covers ::installPacks
	 */
	public function testInstallPacks_EmptyPackName_ReportsError(): void {
		$refId = $this->createTestRef();
		$this->createTestManifest();

		// Try to install pack with empty name
		$packDef = [
			'name' => '',
			'pages' => [],
		];

		$result = $this->packManager->installPacks( $refId, [ $packDef ], 1 );

		$this->assertFalse( $result['success'] );
		$this->assertCount( 1, $result['failed'] );
		$this->assertEquals( '(unnamed)', $result['failed'][0]['pack'] );
	}

	/**
	 * @covers ::installPacks
	 */
	public function testInstallPacks_InvalidRef_ReturnsError(): void {
		$invalidRefId = new ContentRefId( 99999 );

		$result = $this->packManager->installPacks( $invalidRefId, [], 1 );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Ref not found', $result['errors'][0] );
	}

	/**
	 * @covers ::removePack
	 */
	public function testRemovePack_WithoutDeletingPages_Success(): void {
		$refId = $this->createTestRef();
		
		// Create and install a pack
		$packId = $this->packRegistry->registerPack( $refId, 'TestPack', '1.0.0', 1 );
		$this->pageRegistry->addPage( $packId, [
			'name' => 'Page1',
			'final_title' => 'TestPack/Page1',
			'page_namespace' => 0,
		] );

		// Remove pack without deleting pages
		$result = $this->packManager->removePack( $packId, false, 1 );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'TestPack', $result['pack'] );
		$this->assertEquals( 1, $result['pages_total'] );
		$this->assertEquals( 0, $result['pages_deleted'] );
		$this->assertEquals( 1, $result['pages_removed_from_registry'] );

		// Verify pack is removed from registry
		$pack = $this->packRegistry->getPack( $packId );
		$this->assertNull( $pack );
	}

	/**
	 * @covers ::removePack
	 */
	public function testRemovePack_InvalidPackId_ReturnsError(): void {
		$invalidPackId = new PackId( 99999 );

		$result = $this->packManager->removePack( $invalidPackId, false, 1 );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Pack not found', $result['error'] );
	}

	/**
	 * @covers ::updatePack
	 */
	public function testUpdatePack_UpdatesVersionAndPages(): void {
		$refId = $this->createTestRef();
		
		// Create manifest
		$this->createTestManifest(
			[ 'TestPack' => [ 'name' => 'TestPack' ] ],
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
				[ 'name' => 'UpdatedPage', 'original' => 'UpdatedPage', 'finalTitle' => 'TestPack/UpdatedPage' ],
			],
		];

		$result = $this->packManager->updatePack( $packId, $refId, $packDef, 1 );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'TestPack', $result['pack'] );
		$this->assertEquals( '1.0.0', $result['old_version'] );
		$this->assertEquals( '2.0.0', $result['new_version'] );
		$this->assertEquals( 1, $result['pages_updated'] );
	}

	/**
	 * @covers ::updatePack
	 */
	public function testUpdatePack_InvalidPackId_ReturnsError(): void {
		$refId = $this->createTestRef();
		$invalidPackId = new PackId( 99999 );

		$result = $this->packManager->updatePack( $invalidPackId, $refId, [], 1 );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_NoDependencies_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with independent packs
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [] ],
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

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_WithBlockingDependency_ReturnsDependents(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with dependencies
		$this->createTestManifest(
			[
				'BasePackage' => [ 'name' => 'BasePackage', 'depends_on' => [] ],
				'DependentPackage' => [ 'name' => 'DependentPackage', 'depends_on' => [ 'BasePackage' ] ],
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

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_RemovingBothDependentAndDependency_ReturnsEmpty(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with dependencies
		$this->createTestManifest(
			[
				'BasePackage' => [ 'name' => 'BasePackage', 'depends_on' => [] ],
				'DependentPackage' => [ 'name' => 'DependentPackage', 'depends_on' => [ 'BasePackage' ] ],
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

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_WithMultipleDependents_ReturnsAllDependents(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with multiple dependents
		$this->createTestManifest(
			[
				'CorePackage' => [ 'name' => 'CorePackage', 'depends_on' => [] ],
				'Dependent1' => [ 'name' => 'Dependent1', 'depends_on' => [ 'CorePackage' ] ],
				'Dependent2' => [ 'name' => 'Dependent2', 'depends_on' => [ 'CorePackage' ] ],
				'Dependent3' => [ 'name' => 'Dependent3', 'depends_on' => [ 'CorePackage' ] ],
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

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_WithChainedDependencies_ReturnsCorrectDependents(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with chained dependencies: Pack3 -> Pack2 -> Pack1
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [ 'Pack1' ] ],
				'Pack3' => [ 'name' => 'Pack3', 'depends_on' => [ 'Pack2' ] ],
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

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_OnlyChecksInstalledPacks_IgnoresUninstalled(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with dependencies
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [ 'Pack1' ] ],
			],
			[]
		);

		// Install only Pack1 (Pack2 is in manifest but not installed)
		$this->packRegistry->registerPack( $refId, 'Pack1', '1.0.0', 1 );

		// Try to remove Pack1 (Pack2 is not installed, so no blocking dependency)
		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'Pack1' ] );

		$this->assertEmpty( $blockingDeps, 'Should ignore uninstalled packs' );
	}

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_WithInvalidRef_ReturnsEmpty(): void {
		$invalidRefId = new ContentRefId( 99999 );

		$blockingDeps = $this->packManager->validatePackRemoval( $invalidRefId, [ 'SomePack' ] );

		$this->assertEmpty( $blockingDeps, 'Should return empty for invalid ref' );
	}

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_WithMissingWorktree_ReturnsEmpty(): void {
		// Create ref without worktree
		$repoId = $this->repoRegistry->ensureRepoEntry( 'https://example.com/test-repo' );
		$refId = $this->refRegistry->ensureRefEntry( $repoId, 'main', [
			'worktree_path' => '/nonexistent/path',
		] );

		$blockingDeps = $this->packManager->validatePackRemoval( $refId, [ 'SomePack' ] );

		$this->assertEmpty( $blockingDeps, 'Should return empty when worktree is missing' );
	}

	/**
	 * @covers ::validatePackRemoval
	 */
	public function testValidatePackRemoval_WithMultiplePacksToRemove_ChecksAll(): void {
		$refId = $this->createTestRef();
		
		// Create manifest with complex dependencies
		$this->createTestManifest(
			[
				'Pack1' => [ 'name' => 'Pack1', 'depends_on' => [] ],
				'Pack2' => [ 'name' => 'Pack2', 'depends_on' => [] ],
				'Dependent1' => [ 'name' => 'Dependent1', 'depends_on' => [ 'Pack1' ] ],
				'Dependent2' => [ 'name' => 'Dependent2', 'depends_on' => [ 'Pack2' ] ],
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
}

