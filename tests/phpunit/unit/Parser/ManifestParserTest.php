<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Parser;

use LabkiPackManager\Parser\ManifestParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \LabkiPackManager\Parser\ManifestParser
 */
final class ManifestParserTest extends TestCase {

    /**
     * @covers ::parse
     */
    public function testParseBasicManifest(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
packs:
  publication:
    version: "1.0.0"
    description: "Templates and forms for managing publications"
    pages: [ "MainPage", "SubPage" ]
    depends_on: [ "core" ]
    tags: [ "standard", "forms" ]
  onboarding:
    version: "1.1.0"
    description: "Standardized onboarding checklists"
    pages:
      - "Intro"
      - "Checklist"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);

        // Top-level checks
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('schema_version', $parsed);
        $this->assertArrayHasKey('packs', $parsed);
        $this->assertSame('1.0.0', $parsed['schema_version']);

        $packs = $parsed['packs'];
        $this->assertCount(2, $packs);
        $this->assertArrayHasKey('publication', $packs);
        $this->assertArrayHasKey('onboarding', $packs);

        // publication pack
        $pub = $packs['publication'];
        $this->assertSame('publication', $pub['id']);
        $this->assertSame('1.0.0', $pub['version']);
        $this->assertSame('Templates and forms for managing publications', $pub['description']);
        $this->assertSame(['MainPage', 'SubPage'], $pub['pages']);
        $this->assertSame(2, $pub['page_count']);
        $this->assertSame(['core'], $pub['depends_on']);
        $this->assertSame(['standard', 'forms'], $pub['tags']);

        // onboarding pack
        $onboard = $packs['onboarding'];
        $this->assertSame('onboarding', $onboard['id']);
        $this->assertSame('1.1.0', $onboard['version']);
        $this->assertSame(['Intro', 'Checklist'], $onboard['pages']);
        $this->assertSame(2, $onboard['page_count']);
    }

    /**
     * @covers ::parse
     */
    public function testParseHandlesEmptyFields(): void {
        $yaml = <<<YAML
schema_version: "1.2.3"
packs:
  empty-pack: {}
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        $pack = $parsed['packs']['empty-pack'];

        $this->assertSame('empty-pack', $pack['id']);
        $this->assertSame('', $pack['version']);
        $this->assertSame('', $pack['description']);
        $this->assertSame([], $pack['pages']);
        $this->assertSame(0, $pack['page_count']);
        $this->assertSame([], $pack['depends_on']);
        $this->assertSame([], $pack['tags']);
    }

    /**
     * @covers ::parse
     */
    public function testParseInvalidYamlThrows(): void {
        $parser = new ManifestParser();
        $this->expectException(InvalidArgumentException::class);
        $parser->parse("::: not yaml :::");
    }

    /**
     * @covers ::parse
     */
    public function testParseMissingPacksThrows(): void {
        $parser = new ManifestParser();
        $this->expectException(InvalidArgumentException::class);
        $parser->parse("schema_version: '1.0.0'");
    }

    /**
     * @covers ::parse
     */
    public function testParseEmptyYamlThrows(): void {
        $parser = new ManifestParser();
        $this->expectException(InvalidArgumentException::class);
        $parser->parse("");
    }

    /**
     * @covers ::parse
     */
    public function testParseIgnoresNonArrayPacks(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
packs:
  invalid-pack: "string-value"
  good-pack:
    version: "0.1.0"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        $packs = $parsed['packs'];

        $this->assertCount(1, $packs);
        $this->assertArrayHasKey('good-pack', $packs);
        $this->assertSame('good-pack', $packs['good-pack']['id']);
        $this->assertSame('0.1.0', $packs['good-pack']['version']);
    }

    /**
     * @covers ::parse
     */
    public function testParseNormalizesStringArrays(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
packs:
  test:
    pages: [ "A", "", 123, "  B " ]
    depends_on: [ "X", null, "Y" ]
    tags: "not-an-array"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        $pack = $parsed['packs']['test'];

        $this->assertSame(['A', 'B'], $pack['pages']);
        $this->assertSame(['X', 'Y'], $pack['depends_on']);
        $this->assertSame([], $pack['tags']);
        $this->assertSame(2, $pack['page_count']);
    }

    /**
     * @covers ::parse
     */
    public function testParseAllowsMissingSchemaVersion(): void {
        $yaml = <<<YAML
packs:
  only-pack:
    version: "0.0.1"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);

        $this->assertArrayHasKey('schema_version', $parsed);
        $this->assertSame('', $parsed['schema_version']);
        $this->assertCount(1, $parsed['packs']);
        $this->assertArrayHasKey('only-pack', $parsed['packs']);
        $this->assertSame('only-pack', $parsed['packs']['only-pack']['id']);
    }

    /**
     * @covers ::parse
     */
    public function testParseRejectsScalarRoot(): void {
        $parser = new ManifestParser();
        $this->expectException(InvalidArgumentException::class);
        $parser->parse("just-a-string");
    }

    /**
     * @covers ::parse
     */
    public function testParseIgnoresNonStringPackIds(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
packs:
  123:
    version: "0.1.0"
  valid:
    version: "0.2.0"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        $packs = $parsed['packs'];

        $this->assertCount(1, $packs);
        $this->assertArrayHasKey('valid', $packs);
        $this->assertSame('valid', $packs['valid']['id']);
        $this->assertSame('0.2.0', $packs['valid']['version']);
    }
}
