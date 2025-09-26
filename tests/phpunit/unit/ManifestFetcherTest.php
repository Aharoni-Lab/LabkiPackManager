<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit;

use LabkiPackManager\Services\ManifestFetcher;
use PHPUnit\Framework\TestCase;

// This file is now covered by integration tests. Keep a trivial assertion to avoid discovery.
class ManifestFetcherTest extends TestCase {
    public function testPlaceholder(): void {
        $this->assertTrue( true );
    }
    private function setUpSuccessResponse( string $body, int $code = 200 ) : ManifestFetcher {
        $factory = $this->newFactory( $code, $body, true );
        $sources = [ 'Default' => [ 'manifestUrl' => 'http://example.test/manifest.yml' ] ];
        return new ManifestFetcher( $factory, $sources );
    }

    private function newFactory( int $code, string $body, bool $ok ) {
        return new class( $code, $body, $ok ) {
            private int $code; private string $body; private bool $ok;
            public function __construct( int $code, string $body, bool $ok ) { $this->code=$code; $this->body=$body; $this->ok=$ok; }
            public function create( string $url, array $opts ) {
                $code=$this->code; $body=$this->body; $ok=$this->ok;
                return new class( $code, $body, $ok ) {
                    private int $code; private string $body; private bool $ok;
                    public function __construct( int $code, string $body, bool $ok ) { $this->code=$code; $this->body=$body; $this->ok=$ok; }
                    public function execute() { return new class( $this->ok ) { private bool $ok; public function __construct( bool $ok ){ $this->ok=$ok; } public function isOK():bool { return $this->ok; } }; }
                    public function getStatus(): int { return $this->code; }
                    public function getContent(): string { return $this->body; }
                };
            }
        };
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
        $fetcher = $this->setUpSuccessResponse( $yaml );
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
        $fetcher = $this->setUpSuccessResponse( 'not: [ yaml: ' );
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-parse', $status->getMessage()->getKey() );
	}

	/**
	 * HTTP 200 and YAML parses, but required 'packs' key missing → schema error.
	 */
	public function testFetchRootManifest_MissingPacks_ReturnsSchemaError(): void {
		$yaml = "key: value";
        $fetcher = $this->setUpSuccessResponse( $yaml );
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-schema', $status->getMessage()->getKey() );
	}

	/**
	 * Simulate HTTP client execution failure (network, etc.) → fetch error.
	 */
	public function testFetchRootManifest_HttpExecuteError_ReturnsFetchError(): void {
        $fetcher = new ManifestFetcher( $this->newFactory( 0, '', false ), [ 'Default' => [ 'manifestUrl' => 'http://example.test/manifest.yml' ] ] );
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-fetch', $status->getMessage()->getKey() );
	}

	/**
	 * Non-200 status (e.g., 500) or empty body → fetch error.
	 */
	public function testFetchRootManifest_Non200_ReturnsFetchError(): void {
        $fetcher = $this->setUpSuccessResponse( '', 500 );
		$status = $fetcher->fetchRootManifest();
		$this->assertFalse( $status->isOK() );
		$this->assertSame( 'labkipackmanager-error-fetch', $status->getMessage()->getKey() );
	}

	/**
	 * HTTP 200 but empty body → fetch error (treated as missing content).
	 */
	public function testFetchRootManifest_EmptyBody_ReturnsFetchError(): void {
        $fetcher = $this->setUpSuccessResponse( '', 200 );
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
        $fetcher = $this->setUpSuccessResponse( $yaml );
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


