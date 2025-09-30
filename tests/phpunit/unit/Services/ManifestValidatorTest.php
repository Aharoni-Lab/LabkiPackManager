<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Services;

use LabkiPackManager\Schema\SchemaResolver;
use LabkiPackManager\Services\ManifestValidator;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \LabkiPackManager\Services\ManifestValidator
 * @covers \LabkiPackManager\Schema\SchemaResolver
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
    public function testValidateWithRemoteSchema(): void {
        $indexUrl = 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs-tools/main/schema/index.json';
        $schemaUrl = 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs-tools/main/schema/v1_0_0/manifest.schema.json';
        $http = $this->newHttpFactory( [
            $indexUrl => json_encode( [ 'manifest' => [ '1.0.0' => 'v1_0_0/manifest.schema.json', 'latest' => 'v1_0_0/manifest.schema.json' ] ] ),
            $schemaUrl => json_encode( [ 'required' => [ 'schema_version', 'packs' ], 'properties' => [ 'schema_version' => [ 'type' => 'string' ], 'packs' => [ 'type' => 'object' ] ] ] ),
        ] );
        $resolver = new SchemaResolver( $http );

        $yaml = <<<YAML
schema_version: 1.0.0
packs: {}
YAML;
        $validator = new ManifestValidator( $http, $resolver );
        $status = $validator->validate( $yaml );
        $this->assertTrue( $status->isOK() );
    }
}


