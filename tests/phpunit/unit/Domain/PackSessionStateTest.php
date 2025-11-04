<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Domain;

use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Domain\ContentRefId;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LabkiPackManager\Domain\PackSessionState
 */
class PackSessionStateTest extends TestCase {

	public function testConstructorAndGetters(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;
		$packs = [
			'test pack' => [
				'selected' => true,
				'auto_selected' => false,
				'action' => 'install',
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

	public function testSelectPack(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test pack' => [
				'selected' => false,
				'auto_selected' => false,
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$oldHash = $state->hash();

		$state->selectPack( 'test pack' );

		$pack = $state->getPack( 'test pack' );
		$this->assertTrue( $pack['selected'] );
		$this->assertFalse( $pack['auto_selected'] );
		$this->assertNotEquals( $oldHash, $state->hash() );
	}

	public function testDeselectPack(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test pack' => [
				'selected' => true,
				'auto_selected' => false,
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$state->deselectPack( 'test pack' );

		$pack = $state->getPack( 'test pack' );
		$this->assertFalse( $pack['selected'] );
		$this->assertFalse( $pack['auto_selected'] );
	}

	public function testAutoSelectPack(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'dep pack' => [
				'selected' => false,
				'auto_selected' => false,
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$state->autoSelectPack( 'dep pack', 'Required by test pack' );

		$pack = $state->getPack( 'dep pack' );
		$this->assertTrue( $pack['auto_selected'] );
		$this->assertEquals( 'Required by test pack', $pack['auto_selected_reason'] );
	}

	public function testSetPageFinalTitle(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test pack' => [
				'pages' => [
					'test page' => [
						'name' => 'test page',
						'final_title' => 'TestPack/test page',
					],
				],
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$state->setPageFinalTitle( 'test pack', 'test page', 'Custom/NewTitle' );

		$pack = $state->getPack( 'test pack' );
		$this->assertEquals( 'Custom/NewTitle', $pack['pages']['test page']['final_title'] );
	}

	public function testSetPackPrefix(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test pack' => [
				'prefix' => 'TestPack',
				'pages' => [
					'page1' => [
						'name' => 'page1',
						'default_title' => 'TestPack/page1',
						'final_title' => 'TestPack/page1',
					],
					'page2' => [
						'name' => 'page2',
						'default_title' => 'TestPack/page2',
						'final_title' => 'TestPack/page2',
					],
				],
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$state->setPackPrefix( 'test pack', 'NewPrefix' );

		$pack = $state->getPack( 'test pack' );
		$this->assertEquals( 'NewPrefix', $pack['prefix'] );
		$this->assertEquals( 'NewPrefix/page1', $pack['pages']['page1']['default_title'] );
		$this->assertEquals( 'NewPrefix/page1', $pack['pages']['page1']['final_title'] );
		$this->assertEquals( 'NewPrefix/page2', $pack['pages']['page2']['default_title'] );
	}

	public function testGetSelectedPackNames(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'pack1' => [ 'selected' => true, 'auto_selected' => false ],
			'pack2' => [ 'selected' => false, 'auto_selected' => true ],
			'pack3' => [ 'selected' => false, 'auto_selected' => false ],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$selected = $state->getSelectedPackNames();

		$this->assertContains( 'pack1', $selected );
		$this->assertContains( 'pack2', $selected );
		$this->assertNotContains( 'pack3', $selected );
	}

	public function testGetUserSelectedPackNames(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'pack1' => [ 'selected' => true, 'auto_selected' => false ],
			'pack2' => [ 'selected' => false, 'auto_selected' => true ],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$selected = $state->getUserSelectedPackNames();

		$this->assertContains( 'pack1', $selected );
		$this->assertNotContains( 'pack2', $selected );
	}

	public function testGetAutoSelectedPackNames(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'pack1' => [ 'selected' => true, 'auto_selected' => false ],
			'pack2' => [ 'selected' => false, 'auto_selected' => true ],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$autoSelected = $state->getAutoSelectedPackNames();

		$this->assertNotContains( 'pack1', $autoSelected );
		$this->assertContains( 'pack2', $autoSelected );
	}

	public function testToArrayAndFromArray(): void {
		$refId = new ContentRefId( 5 );
		$userId = 123;
		$packs = [
			'test pack' => [
				'selected' => true,
				'auto_selected' => false,
				'prefix' => 'TestPack',
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

	public function testCreatePackState(): void {
		$packDef = [
			'version' => '1.0.0',
			'prefix' => 'MyPrefix',
			'pages' => [ 'page1', 'page2' ],
		];

		// Test for new pack (install)
		$packState = PackSessionState::createPackState( 'test pack', $packDef, null );

		$this->assertFalse( $packState['selected'] );
		$this->assertFalse( $packState['auto_selected'] );
		$this->assertEquals( 'install', $packState['action'] );
		$this->assertNull( $packState['current_version'] );
		$this->assertEquals( '1.0.0', $packState['target_version'] );
		$this->assertEquals( 'MyPrefix', $packState['prefix'] );
		$this->assertCount( 2, $packState['pages'] );
		$this->assertEquals( 'MyPrefix/page1', $packState['pages']['page1']['default_title'] );

		// Test for installed pack (update)
		$packState = PackSessionState::createPackState( 'test pack', $packDef, '0.9.0' );

		$this->assertTrue( $packState['selected'] ); // Pre-selected if installed
		$this->assertEquals( 'update', $packState['action'] );
		$this->assertEquals( '0.9.0', $packState['current_version'] );

		// Test for up-to-date pack (unchanged)
		$packState = PackSessionState::createPackState( 'test pack', $packDef, '1.0.0' );

		$this->assertEquals( 'unchanged', $packState['action'] );
	}

	public function testHashChangesWithModifications(): void {
		$refId = new ContentRefId( 5 );
		$packs = [
			'test pack' => [
				'selected' => false,
				'pages' => [
					'page1' => [ 'final_title' => 'Old/Title' ],
				],
			],
		];

		$state = new PackSessionState( $refId, 123, $packs );
		$hash1 = $state->hash();

		$state->selectPack( 'test pack' );
		$hash2 = $state->hash();
		$this->assertNotEquals( $hash1, $hash2 );

		$state->setPageFinalTitle( 'test pack', 'page1', 'New/Title' );
		$hash3 = $state->hash();
		$this->assertNotEquals( $hash2, $hash3 );
	}
}

