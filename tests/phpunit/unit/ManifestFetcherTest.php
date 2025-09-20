<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit;

use LabkiPackManager\Services\ManifestFetcher;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\TestCase;

class ManifestFetcherTest extends TestCase {
	private function setUpSuccessResponse( string $body, int $code = 200 ) : void {
		MediaWikiServices::resetForTests();
		$services = MediaWikiServices::getInstance();
		$services->setConfigForTests( [
			'LabkiContentManifestURL' => 'http://example.test/manifest.yml',
		] );
		$services->getHttpRequestFactory()->setNextResponse( $code, $body, true );
	}

	/**
	 * Happy path: HTTP 200 with valid YAML should return OK status
	 * and a normalized packs array with expected values.
	 */
	public function testFetchRootManifest_Success(): void {
		$yaml = <<<YAML
packs:
  - id: publication
    path: packs/publication
    version: 1.0.0
    description: Templates and forms for managing publications
YAML;
		$this->setUpSuccessResponse( $yaml );

		$fetcher = new ManifestFetcher();
		$status = $fetcher->fetchRootManifest();

		$this->assertTrue( $status->isOK() );
		$packs = $status->getValue();
		$this->assertIsArray( $packs );
		$this->assertCount( 1, $packs );
		$this->assertSame( 'publication', $packs[0]['id'] );
		$this->assertSame( 'packs/publication', $packs[0]['path'] );
	}

	/**
	 * HTTP 200 but body is invalid YAML → parse error message.
	 */
	public function testFetchRootManifest_InvalidYaml_ReturnsParseError(): void {
		$this->setUpSuccessResponse( 'not: [ yaml: ' );
		$fetcher = new ManifestFetcher();
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-parse', $status->getMessage()->getKey() );
	}

	/**
	 * HTTP 200 and YAML parses, but required 'packs' key missing → schema error.
	 */
	public function testFetchRootManifest_MissingPacks_ReturnsSchemaError(): void {
		$yaml = "key: value";
		$this->setUpSuccessResponse( $yaml );
		$fetcher = new ManifestFetcher();
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-schema', $status->getMessage()->getKey() );
	}

	/**
	 * Simulate HTTP client execution failure (network, etc.) → fetch error.
	 */
	public function testFetchRootManifest_HttpExecuteError_ReturnsFetchError(): void {
		MediaWikiServices::resetForTests();
		$services = MediaWikiServices::getInstance();
		$services->setConfigForTests( [ 'LabkiContentManifestURL' => 'http://example.test/manifest.yml' ] );
		$services->getHttpRequestFactory()->setNextResponse( 0, '', false );

		$fetcher = new ManifestFetcher();
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-fetch', $status->getMessage()->getKey() );
	}

	/**
	 * Non-200 status (e.g., 500) or empty body → fetch error.
	 */
	public function testFetchRootManifest_Non200_ReturnsFetchError(): void {
		$this->setUpSuccessResponse( '', 500 );
		$fetcher = new ManifestFetcher();
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-fetch', $status->getMessage()->getKey() );
	}

	/**
	 * HTTP 200 but empty body → fetch error (treated as missing content).
	 */
	public function testFetchRootManifest_EmptyBody_ReturnsFetchError(): void {
		$this->setUpSuccessResponse( '', 200 );
		$fetcher = new ManifestFetcher();
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-fetch', $status->getMessage()->getKey() );
	}

	/**
	 * Use a real YAML fixture file to ensure multi-pack manifests
	 * parse correctly and preserve order of entries.
	 */
	public function testFetchRootManifest_FromFixtureFile(): void {
		$fixturePath = __DIR__ . '/../../fixtures/manifest.yml';
		$this->assertFileExists( $fixturePath );
		$yaml = file_get_contents( $fixturePath );
		$this->setUpSuccessResponse( $yaml );

		$fetcher = new ManifestFetcher();
		$status = $fetcher->fetchRootManifest();
		$this->assertTrue( $status->isOK() );
		$packs = $status->getValue();
		$this->assertIsArray( $packs );
		$this->assertCount( 3, $packs );
		$this->assertSame( 'publication', $packs[0]['id'] );
		$this->assertSame( 'onboarding', $packs[1]['id'] );
		$this->assertSame( 'meeting_notes', $packs[2]['id'] );
	}
}


