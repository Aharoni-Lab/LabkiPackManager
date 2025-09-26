<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration;

use LabkiPackManager\Services\ManifestFetcher;

/**
 * @coversDefaultClass \LabkiPackManager\Services\ManifestFetcher
 */
class ManifestFetcherTest extends \MediaWikiIntegrationTestCase {
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

    private function makeFetcherWithSources( string $body, int $code = 200 ) : ManifestFetcher {
        $factory = $this->newFactory( $code, $body, $code === 200 );
        $sources = [ 'Default' => 'http://example.test/manifest.yml' ];
        return new ManifestFetcher( $factory, $sources );
    }

    /**
     * @covers ::fetchManifest
     */
    public function testFetchRootManifest_Success(): void {
        $yaml = <<<YAML
schema_version: 1.0.0
packs:
  publication:
    version: 1.0.0
    description: Templates and forms for managing publications
YAML;
        $fetcher = $this->makeFetcherWithSources( $yaml );
        $status = $fetcher->fetchManifest();

        $this->assertTrue( $status->isOK() );
        $packs = $status->getValue();
        $this->assertIsArray( $packs );
        $this->assertCount( 1, $packs );
        $this->assertSame( 'publication', $packs[0]['id'] );
        $this->assertArrayHasKey( 'version', $packs[0] );
    }

    /**
     * @covers ::fetchManifest
     */
    public function testFetchRootManifest_InvalidYaml_ReturnsParseError(): void {
        $fetcher = $this->makeFetcherWithSources( 'not: [ yaml: ' );
        $status = $fetcher->fetchManifest();
        $this->assertFalse( $status->isOK() );
        if ( method_exists( $status, 'getMessage' ) && is_object( $status->getMessage() ) && method_exists( $status->getMessage(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-parse', $status->getMessage()->getKey() );
        } elseif ( method_exists( $status, 'getMessageValue' ) && is_object( $status->getMessageValue() ) && method_exists( $status->getMessageValue(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-parse', $status->getMessageValue()->getKey() );
        }
    }

    /**
     * @covers ::fetchManifest
     */
    public function testFetchRootManifest_MissingPacks_ReturnsSchemaError(): void {
        $yaml = "key: value";
        $fetcher = $this->makeFetcherWithSources( $yaml );
        $status = $fetcher->fetchManifest();
        $this->assertFalse( $status->isOK() );
        if ( method_exists( $status, 'getMessage' ) && is_object( $status->getMessage() ) && method_exists( $status->getMessage(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-schema', $status->getMessage()->getKey() );
        } elseif ( method_exists( $status, 'getMessageValue' ) && is_object( $status->getMessageValue() ) && method_exists( $status->getMessageValue(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-schema', $status->getMessageValue()->getKey() );
        }
    }

    /**
     * @covers ::fetchManifest
     */
    public function testFetchRootManifest_HttpExecuteError_ReturnsFetchError(): void {
        $factory = $this->newFactory( 0, '', false );
        $fetcher = new ManifestFetcher( $factory, [ 'Default' => 'http://example.test/manifest.yml' ] );
        $status = $fetcher->fetchManifest();
        $this->assertFalse( $status->isOK() );
        if ( method_exists( $status, 'getMessage' ) && is_object( $status->getMessage() ) && method_exists( $status->getMessage(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-fetch', $status->getMessage()->getKey() );
        } elseif ( method_exists( $status, 'getMessageValue' ) && is_object( $status->getMessageValue() ) && method_exists( $status->getMessageValue(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-fetch', $status->getMessageValue()->getKey() );
        }
    }

    /**
     * @covers ::fetchManifest
     */
    public function testFetchRootManifest_Non200_ReturnsFetchError(): void {
        $fetcher = $this->makeFetcherWithSources( '', 500 );
        $status = $fetcher->fetchManifest();
        $this->assertFalse( $status->isOK() );
        if ( method_exists( $status, 'getMessage' ) && is_object( $status->getMessage() ) && method_exists( $status->getMessage(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-fetch', $status->getMessage()->getKey() );
        } elseif ( method_exists( $status, 'getMessageValue' ) && is_object( $status->getMessageValue() ) && method_exists( $status->getMessageValue(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-fetch', $status->getMessageValue()->getKey() );
        }
    }

    /**
     * @covers ::fetchManifest
     */
    public function testFetchRootManifest_EmptyBody_ReturnsFetchError(): void {
        $fetcher = $this->makeFetcherWithSources( '', 200 );
        $status = $fetcher->fetchManifest();
        $this->assertFalse( $status->isOK() );
        if ( method_exists( $status, 'getMessage' ) && is_object( $status->getMessage() ) && method_exists( $status->getMessage(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-fetch', $status->getMessage()->getKey() );
        } elseif ( method_exists( $status, 'getMessageValue' ) && is_object( $status->getMessageValue() ) && method_exists( $status->getMessageValue(), 'getKey' ) ) {
            $this->assertSame( 'labkipackmanager-error-fetch', $status->getMessageValue()->getKey() );
        }
    }

    /**
     * @covers ::fetchManifest
     */
    public function testFetchRootManifest_FromFixtureFile(): void {
        $fixturePath = __DIR__ . '/../../fixtures/manifest.yml';
        $this->assertFileExists( $fixturePath );
        $yaml = file_get_contents( $fixturePath );
        $fetcher = $this->makeFetcherWithSources( $yaml );
        $status = $fetcher->fetchManifest();
        $this->assertTrue( $status->isOK() );
        $packs = $status->getValue();
        $this->assertIsArray( $packs );
        $this->assertCount( 6, $packs );
        $ids = array_map( static function ( $p ) { return $p['id'] ?? ''; }, $packs );
        $this->assertContains( 'publication', $ids );
        $this->assertContains( 'meeting_notes', $ids );
        $this->assertContains( 'onboarding', $ids );
        $this->assertContains( 'shared_base', $ids );
        $this->assertContains( 'meta_pack', $ids );
        $this->assertContains( 'app', $ids );
    }
}


