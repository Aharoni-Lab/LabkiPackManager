<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit;

use LabkiPackManager\Services\ManifestValidator;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \LabkiPackManager\Services\ManifestValidator
 */
class ManifestValidatorTest extends TestCase {
    private function newHttpFactory( array $responsesByUrl ) {
        return new class( $responsesByUrl ) {
            private array $map;
            public function __construct( array $map ) { $this->map = $map; }
            public function create( string $url, array $opts ) {
                $status = 200;
                $ok = true;
                $body = $this->map[$url] ?? '';
                if ( $body === '' ) { $ok = false; $status = 404; }
                return new class( $status, $body, $ok ) {
                    private int $status; private string $body; private bool $ok;
                    public function __construct( int $s, string $b, bool $o ){ $this->status=$s; $this->body=$b; $this->ok=$o; }
                    public function execute(){ return new class( $this->ok ){ private bool $ok; public function __construct( bool $o ){ $this->ok=$o; } public function isOK(){ return $this->ok; } }; }
                    public function getStatus(){ return $this->status; }
                    public function getContent(){ return $this->body; }
                };
            }
        };
    }

    /**
     * @covers ::validate
     */
    public function testValidateSuccess(): void {
        $indexUrl = 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs-tools/main/schema/index.json';
        $schemaUrl = 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs-tools/main/schema/v1_0_0/manifest.schema.json';
        $http = $this->newHttpFactory( [
            $indexUrl => json_encode( [ 'manifest' => [ '1.0.0' => 'v1_0_0/manifest.schema.json', 'latest' => 'v1_0_0/manifest.schema.json' ] ] ),
            $schemaUrl => json_encode( [ 'required' => [ 'schema_version', 'packs' ], 'properties' => [ 'schema_version' => [ 'type' => 'string' ], 'packs' => [ 'type' => 'object' ] ] ] ),
        ] );

        $yaml = <<<YAML
schema_version: 1.0.0
packs:
  pub:
    version: 1.0.0
YAML;
        $validator = new ManifestValidator( $http );
        $status = $validator->validate( $yaml );
        $this->assertTrue( $status->isOK() );
        $decoded = $status->getValue();
        $this->assertIsArray( $decoded );
        $this->assertArrayHasKey( 'packs', $decoded );
    }

    /**
     * @covers ::validate
     */
    public function testValidateMissingSchemaVersionFails(): void {
        $validator = new ManifestValidator( $this->newHttpFactory( [] ) );
        $yaml = "packs: {}";
        $status = $validator->validate( $yaml );
        $this->assertFalse( $status->isOK() );
    }

    /**
     * @covers ::validate
     */
    public function testValidateInvalidSchemaVersionFails(): void {
        $validator = new ManifestValidator( $this->newHttpFactory( [] ) );
        $yaml = "schema_version: x.y\npacks: {}";
        $status = $validator->validate( $yaml );
        $this->assertFalse( $status->isOK() );
    }

    /**
     * @covers ::validate
     */
    public function testValidatePacksWrongTypeFails(): void {
        $validator = new ManifestValidator( $this->newHttpFactory( [] ) );
        $yaml = "schema_version: 1.0.0\npacks: []";
        $status = $validator->validate( $yaml );
        $this->assertFalse( $status->isOK() );
    }

    /**
     * @covers ::validate
     */
    public function testValidateEmptyPacksPass(): void {
        $validator = new ManifestValidator( $this->newHttpFactory( [] ) );
        $yaml = "schema_version: 1.0.0\npacks: {}\npages: {}";
        $status = $validator->validate( $yaml );
        $this->assertTrue( $status->isOK() );
    }
}


