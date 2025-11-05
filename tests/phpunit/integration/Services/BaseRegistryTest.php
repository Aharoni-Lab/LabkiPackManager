<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\Services;

use LabkiPackManager\Services\BaseRegistry;
use MediaWikiIntegrationTestCase;

/**
 * Integration tests for BaseRegistry.
 *
 * Tests the shared functionality provided by the base registry class,
 * which is inherited by all concrete registry implementations.
 *
 * @covers \LabkiPackManager\Services\BaseRegistry
 * @group LabkiPackManager
 * @group Database
 */
final class BaseRegistryTest extends MediaWikiIntegrationTestCase {

	/** @var string[] Tables used by this test */
	protected $tablesUsed = [];

	/**
	 * Test that now() returns a valid timestamp string.
	 */
	public function testNow_ReturnsValidTimestamp(): void {
		$registry = new BaseRegistry();
		
		$timestamp = $registry->now();
		
		$this->assertIsString( $timestamp );
		$this->assertNotEmpty( $timestamp );
		
		// Verify it's in MediaWiki timestamp format (TS_MW: YYYYMMDDHHmmss)
		$this->assertMatchesRegularExpression(
			'/^\d{14}$/',
			$timestamp,
			'Timestamp should be 14 digits in YYYYMMDDHHmmss format'
		);
	}

	/**
	 * Test that now() accepts a custom database connection.
	 */
	public function testNow_WithCustomDatabase_UsesProvidedConnection(): void {
		$registry = new BaseRegistry();
		$dbw = $this->getDb();
		
		$timestamp = $registry->now( $dbw );
		
		$this->assertIsString( $timestamp );
		$this->assertNotEmpty( $timestamp );
		$this->assertMatchesRegularExpression( '/^\d{14}$/', $timestamp );
	}

	/**
	 * Test that now() returns current time (within reasonable margin).
	 */
	public function testNow_ReturnsCurrentTime(): void {
		$registry = new BaseRegistry();
		
		$before = wfTimestamp( TS_MW );
		$timestamp = $registry->now();
		$after = wfTimestamp( TS_MW );
		
		// Timestamp should be between before and after
		$this->assertGreaterThanOrEqual( $before, $timestamp );
		$this->assertLessThanOrEqual( $after, $timestamp );
	}

	/**
	 * Test that multiple calls to now() return increasing timestamps.
	 */
	public function testNow_MultipleCallsReturnIncreasingTimestamps(): void {
		$registry = new BaseRegistry();
		
		$timestamp1 = $registry->now();
		
		// Small delay to ensure time progresses
		usleep( 1000 ); // 1ms
		
		$timestamp2 = $registry->now();
		
		$this->assertGreaterThanOrEqual( $timestamp1, $timestamp2 );
	}
}

