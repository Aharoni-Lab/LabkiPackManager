<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Session;

use LabkiPackManager\Session\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Session\PackSessionState
 */
class PackSessionStateTest extends TestCase {

	public function testConstructorAndGetters(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;
		$packs = [
			'test-pack' => [
				'action' => 'install',
				'auto_selected_reason' => null,
				'current_version' => null,
				'target_version' => '1.0.0',
				'prefix' => 'TestPack',
				'installed' => false,
				'pages' => [],
			],
		];

		$state = new PackSessionState( $refId, $userId, $packs );

		$this->assertEquals( $refId, $state->refId() );
		$this->assertEquals( $userId, $state->userId() );
		$this->assertEquals( $packs, $state->packs() );
		$this->assertIsString( $state->hash() );
		$this->assertIsInt( $state->timestamp() );
	}

	public function testGetPack(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test-pack' => [
				'action' => 'install',
				'prefix' => 'TestPack',
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );

		$pack = $state->getPack( 'test-pack' );
		$this->assertNotNull( $pack );
		$this->assertEquals( 'install', $pack['action'] );

		$nonExistent = $state->getPack( 'non-existent' );
		$this->assertNull( $nonExistent );
	}

	public function testSetPackAndRemovePack(): void {
		$refId = new ContentRefId( 5 );
		$state = new PackSessionState( $refId, 123, [] );

		$packState = [
			'action' => 'install',
			'prefix' => 'NewPack',
		];

		$state->setPack( 'new-pack', $packState );
		$this->assertEquals( $packState, $state->getPack( 'new-pack' ) );

		$state->removePack( 'new-pack' );
		$this->assertNull( $state->getPack( 'new-pack' ) );
	}

	public function testHasPack(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test-pack' => [ 'action' => 'install' ],
		];

		$state = new PackSessionState( $refId, 123, $packs );

		$this->assertTrue( $state->hasPack( 'test-pack' ) );
		$this->assertFalse( $state->hasPack( 'non-existent' ) );
	}

	public function testSetPackAction(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test-pack' => [
				'action' => 'unchanged',
				'auto_selected_reason' => null,
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$oldHash = $state->hash();

		// Manual action (no auto reason)
		$state->setPackAction( 'test-pack', 'install', null );

		$pack = $state->getPack( 'test-pack' );
		$this->assertEquals( 'install', $pack['action'] );
		$this->assertNull( $pack['auto_selected_reason'] );
		$this->assertNotEquals( $oldHash, $state->hash() );

		// Auto action (with reason)
		$state->setPackAction( 'test-pack', 'update', 'Required by another pack' );

		$pack = $state->getPack( 'test-pack' );
		$this->assertEquals( 'update', $pack['action'] );
		$this->assertEquals( 'Required by another pack', $pack['auto_selected_reason'] );
	}

	public function testGetPacksWithActions(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'pack1' => [ 'action' => 'install' ],
			'pack2' => [ 'action' => 'update' ],
			'pack3' => [ 'action' => 'unchanged' ],
			'pack4' => [ 'action' => 'remove' ],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$packsWithActions = $state->getPacksWithActions();

		$this->assertContains( 'pack1', $packsWithActions );
		$this->assertContains( 'pack2', $packsWithActions );
		$this->assertNotContains( 'pack3', $packsWithActions );
		$this->assertContains( 'pack4', $packsWithActions );
	}

	public function testGetPacksForInstallOrUpdate(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'pack1' => [ 'action' => 'install' ],
			'pack2' => [ 'action' => 'update' ],
			'pack3' => [ 'action' => 'unchanged' ],
			'pack4' => [ 'action' => 'remove' ],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$packs = $state->getPacksForInstallOrUpdate();

		$this->assertContains( 'pack1', $packs );
		$this->assertContains( 'pack2', $packs );
		$this->assertNotContains( 'pack3', $packs );
		$this->assertNotContains( 'pack4', $packs );
	}

	public function testGetManuallyActionedPackNames(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'pack1' => [ 'action' => 'install', 'auto_selected_reason' => null ],
			'pack2' => [ 'action' => 'update', 'auto_selected_reason' => 'Dependency' ],
			'pack3' => [ 'action' => 'unchanged', 'auto_selected_reason' => null ],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$manualPacks = $state->getManuallyActionedPackNames();

		$this->assertContains( 'pack1', $manualPacks );
		$this->assertNotContains( 'pack2', $manualPacks ); // Auto-actioned
		$this->assertNotContains( 'pack3', $manualPacks ); // Unchanged
	}

	public function testGetAutoActionedPackNames(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'pack1' => [ 'action' => 'install', 'auto_selected_reason' => null ],
			'pack2' => [ 'action' => 'update', 'auto_selected_reason' => 'Dependency' ],
			'pack3' => [ 'action' => 'install', 'auto_selected_reason' => 'Required by pack1' ],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$autoPacks = $state->getAutoActionedPackNames();

		$this->assertNotContains( 'pack1', $autoPacks );
		$this->assertContains( 'pack2', $autoPacks );
		$this->assertContains( 'pack3', $autoPacks );
	}

	public function testSetPageFinalTitle(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test-pack' => [
				'pages' => [
					'page1' => [
						'name' => 'page1',
						'final_title' => 'TestPack/page1',
					],
				],
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$state->setPageFinalTitle( 'test-pack', 'page1', 'Custom/NewTitle' );

		$pack = $state->getPack( 'test-pack' );
		$this->assertEquals( 'Custom/NewTitle', $pack['pages']['page1']['final_title'] );
	}

	public function testSetPackPrefix(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test-pack' => [
				'prefix' => 'OldPrefix',
				'pages' => [
					'page1' => [
						'name' => 'page1',
						'default_title' => 'OldPrefix/page1',
						'final_title' => 'OldPrefix/page1',
					],
					'page2' => [
						'name' => 'page2',
						'default_title' => 'OldPrefix/page2',
						'final_title' => 'Custom/page2', // Custom title
					],
				],
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$state->setPackPrefix( 'test-pack', 'NewPrefix' );

		$pack = $state->getPack( 'test-pack' );
		$this->assertEquals( 'NewPrefix', $pack['prefix'] );
		
		// Default title updated
		$this->assertEquals( 'NewPrefix/page1', $pack['pages']['page1']['default_title'] );
		
		// Final title updated because it matched old default
		$this->assertEquals( 'NewPrefix/page1', $pack['pages']['page1']['final_title'] );
		
		// Final title NOT updated because it was custom
		$this->assertEquals( 'Custom/page2', $pack['pages']['page2']['final_title'] );
	}

	public function testToArrayAndFromArray(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;
		$packs = [
			'test-pack' => [
				'action' => 'install',
				'auto_selected_reason' => null,
				'current_version' => null,
				'target_version' => '1.0.0',
				'prefix' => 'TestPack',
				'installed' => false,
				'pages' => [
					'page1' => [
						'name' => 'page1',
						'final_title' => 'TestPack/page1',
					],
				],
			],
		];

		$state = new PackSessionState( $refId, $userId, $packs );
		$array = $state->toArray();

		$this->assertEquals( 5, $array['ref_id'] );
		$this->assertEquals( 123, $array['user_id'] );
		$this->assertEquals( $packs, $array['packs'] );
		$this->assertArrayHasKey( 'hash', $array );
		$this->assertArrayHasKey( 'timestamp', $array );

		// Test round-trip
		$restoredState = PackSessionState::fromArray( $array );
		$this->assertEquals( $state->refId()->toInt(), $restoredState->refId()->toInt() );
		$this->assertEquals( $state->userId(), $restoredState->userId() );
		$this->assertEquals( $state->packs(), $restoredState->packs() );
		$this->assertEquals( $state->hash(), $restoredState->hash() );
	}

	public function testCreatePackStateForNewPack(): void {
		$packDef = [
			'version' => '1.0.0',
			'prefix' => 'MyPrefix',
			'pages' => [ 'page1', 'page2' ],
		];

		$packState = PackSessionState::createPackState( 'test-pack', $packDef, null );

		// Pack-level assertions
		$this->assertEquals( 'unchanged', $packState['action'] );
		$this->assertNull( $packState['auto_selected_reason'] );
		$this->assertNull( $packState['current_version'] );
		$this->assertEquals( '1.0.0', $packState['target_version'] );
		$this->assertEquals( 'MyPrefix', $packState['prefix'] );
		$this->assertFalse( $packState['installed'] );

		// Pages assertions
		$this->assertCount( 2, $packState['pages'] );
		$this->assertEquals( 'MyPrefix/page1', $packState['pages']['page1']['default_title'] );
		$this->assertEquals( 'MyPrefix/page1', $packState['pages']['page1']['final_title'] );
		$this->assertFalse( $packState['pages']['page1']['installed'] );
	}

	public function testCreatePackStateForInstalledPack(): void {
		$packDef = [
			'version' => '2.0.0',
			'prefix' => 'MyPrefix',
			'pages' => [ 'page1', 'page2' ],
		];

		$installedPages = [
			'page1' => 'CustomPrefix/page1',
			'page2' => 'CustomPrefix/page2',
		];

		$packState = PackSessionState::createPackState(
			'test-pack',
			$packDef,
			'1.0.0',
			$installedPages
		);

		// Pack-level assertions
		$this->assertEquals( 'unchanged', $packState['action'] );
		$this->assertEquals( '1.0.0', $packState['current_version'] );
		$this->assertEquals( '2.0.0', $packState['target_version'] );
		$this->assertEquals( 'CustomPrefix', $packState['prefix'] ); // Extracted from installed pages
		$this->assertTrue( $packState['installed'] );

		// Pages assertions
		$this->assertEquals( 'CustomPrefix/page1', $packState['pages']['page1']['final_title'] );
		$this->assertTrue( $packState['pages']['page1']['installed'] );
		$this->assertTrue( $packState['pages']['page2']['installed'] );
	}

	public function testCreatePackStateWithoutPrefix(): void {
		$packDef = [
			'version' => '1.0.0',
			'pages' => [ 'page1' ],
		];

		$packState = PackSessionState::createPackState( 'test-pack', $packDef, null );

		$this->assertEquals( '', $packState['prefix'] );
		$this->assertEquals( 'page1', $packState['pages']['page1']['default_title'] );
		$this->assertEquals( 'page1', $packState['pages']['page1']['final_title'] );
	}

	public function testHashChangesWithModifications(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test-pack' => [
				'action' => 'unchanged',
				'pages' => [
					'page1' => [ 'final_title' => 'Old/Title' ],
				],
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$hash1 = $state->hash();

		// Change action
		$state->setPackAction( 'test-pack', 'install' );
		$hash2 = $state->hash();
		$this->assertNotEquals( $hash1, $hash2 );

		// Change page title
		$state->setPageFinalTitle( 'test-pack', 'page1', 'New/Title' );
		$hash3 = $state->hash();
		$this->assertNotEquals( $hash2, $hash3 );

		// Change prefix
		$state->setPackPrefix( 'test-pack', 'NewPrefix' );
		$hash4 = $state->hash();
		$this->assertNotEquals( $hash3, $hash4 );
	}

	public function testComputeHash(): void {
		$refId = new ContentRefId( 5 );
		$packs = [ 'test-pack' => [ 'action' => 'install' ] ];

		$state = new PackSessionState( $refId, 123, $packs );
		
		$hash1 = $state->hash();
		$hash2 = $state->computeHash();
		
		$this->assertEquals( $hash1, $hash2 );
		$this->assertEquals( 12, strlen( $hash1 ) ); // Hash is 12 chars
	}

	public function testPackAndPageFieldConstants(): void {
		$expectedPackFields = [
			'action',
			'auto_selected_reason',
			'current_version',
			'target_version',
			'prefix',
			'installed',
		];

		$expectedPageFields = [
			'name',
			'default_title',
			'final_title',
			'has_conflict',
			'conflict_type',
			'installed',
		];

		$this->assertEquals( $expectedPackFields, PackSessionState::PACK_FIELDS );
		$this->assertEquals( $expectedPageFields, PackSessionState::PAGE_FIELDS );
	}
}

