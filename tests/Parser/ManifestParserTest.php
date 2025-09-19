<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Parser;

use LabkiPackManager\Parser\ManifestParser;
use PHPUnit\Framework\TestCase;

class ManifestParserTest extends TestCase {
    public function testParseRootSuccess(): void {
        $yaml = <<<YAML
packs:
  - id: publication
    path: packs/publication
    version: 1.0.0
    description: Templates and forms for managing publications
  - id: onboarding
    path: packs/onboarding
    version: 1.1.0
    description: Standardized onboarding checklists
YAML;

        $parser = new ManifestParser();
        $packs = $parser->parseRoot( $yaml );

        $this->assertCount( 2, $packs );
        $this->assertSame( 'publication', $packs[0]['id'] );
        $this->assertSame( 'packs/publication', $packs[0]['path'] );
        $this->assertSame( '1.0.0', $packs[0]['version'] );
        $this->assertSame( 'Templates and forms for managing publications', $packs[0]['description'] );
    }

    public function testParseRootInvalidYaml(): void {
        $this->expectException( \InvalidArgumentException::class );
        $parser = new ManifestParser();
        $parser->parseRoot( "::: not yaml :::" );
    }

    public function testParseRootMissingPacks(): void {
        $this->expectException( \InvalidArgumentException::class );
        $parser = new ManifestParser();
        $parser->parseRoot( "key: value" );
    }
}


