<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit\Parser;

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

    /**
     * @covers ::parse
     */
    public function testParsePagesSection(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
pages:
  MainPage:
    file: "pages/MainPage.wiki"
    last_updated: "2025-01-15T10:30:00Z"
  SubPage:
    file: "pages/SubPage.wiki"
  InvalidPage:
    last_updated: "2025-01-15"
packs:
  test-pack:
    version: "1.0.0"
    pages: ["MainPage"]
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        
        $this->assertArrayHasKey('pages', $parsed);
        $pages = $parsed['pages'];
        
        // MainPage with all fields
        $this->assertArrayHasKey('MainPage', $pages);
        $this->assertSame('MainPage', $pages['MainPage']['name']);
        $this->assertSame('pages/MainPage.wiki', $pages['MainPage']['file']);
        $this->assertSame('2025-01-15T10:30:00Z', $pages['MainPage']['last_updated']);
        
        // SubPage with missing last_updated
        $this->assertArrayHasKey('SubPage', $pages);
        $this->assertSame('pages/SubPage.wiki', $pages['SubPage']['file']);
        $this->assertSame('', $pages['SubPage']['last_updated']);
        
        // InvalidPage missing file field should be skipped
        $this->assertArrayNotHasKey('InvalidPage', $pages);
    }

    /**
     * @covers ::parse
     */
    public function testParseMetadataFields(): void {
        $yaml = <<<YAML
schema_version: "2.0.0"
last_updated: "2025-01-15T12:00:00Z"
name: "Test Manifest"
description: "A test manifest for validation"
author: "Test Author"
packs:
  sample:
    version: "1.0.0"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        
        $this->assertSame('2.0.0', $parsed['schema_version']);
        $this->assertSame('2025-01-15T12:00:00Z', $parsed['last_updated']);
        $this->assertSame('Test Manifest', $parsed['name']);
        $this->assertSame('A test manifest for validation', $parsed['description']);
        $this->assertSame('Test Author', $parsed['author']);
    }

    /**
     * @covers ::parse
     */
    public function testParseMissingMetadataFieldsDefaultsToEmpty(): void {
        $yaml = <<<YAML
packs:
  only-pack:
    version: "1.0.0"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        
        $this->assertSame('', $parsed['schema_version']);
        $this->assertSame('', $parsed['last_updated']);
        $this->assertSame('', $parsed['name']);
        $this->assertSame('', $parsed['description']);
        $this->assertSame('', $parsed['author']);
    }

    /**
     * @covers ::parse
     */
    public function testParseHandlesUtf8Bom(): void {
        // YAML with UTF-8 BOM (common in Windows-saved files)
        $yaml = "\xEF\xBB\xBF" . <<<YAML
schema_version: "1.0.0"
packs:
  test:
    version: "1.0.0"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        
        $this->assertSame('1.0.0', $parsed['schema_version']);
        $this->assertArrayHasKey('test', $parsed['packs']);
    }

    /**
     * @covers ::parse
     */
    public function testParseThrowsWhenAllPacksAreInvalid(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
packs:
  invalid1: "not-an-array"
  invalid2: null
  123: { version: "1.0.0" }
YAML;

        $parser = new ManifestParser();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no valid entries');
        $parser->parse($yaml);
    }

    /**
     * @covers ::parse
     */
    public function testParseIgnoresInvalidPageEntries(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
pages:
  123:
    file: "invalid-numeric-key.wiki"
  ValidPage:
    file: "valid.wiki"
  NoFile:
    last_updated: "2025-01-15"
  StringValue: "not-an-array"
packs:
  test:
    version: "1.0.0"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        
        $pages = $parsed['pages'];
        
        // Only ValidPage should be parsed
        $this->assertCount(1, $pages);
        $this->assertArrayHasKey('ValidPage', $pages);
        $this->assertSame('valid.wiki', $pages['ValidPage']['file']);
        
        // Others should be ignored
        $this->assertArrayNotHasKey('123', $pages);
        $this->assertArrayNotHasKey('NoFile', $pages);
        $this->assertArrayNotHasKey('StringValue', $pages);
    }

    /**
     * @covers ::parse
     */
    public function testParseWithEmptyPagesSection(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
pages: {}
packs:
  test:
    version: "1.0.0"
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        
        $this->assertArrayHasKey('pages', $parsed);
        $this->assertIsArray($parsed['pages']);
        $this->assertEmpty($parsed['pages']);
    }

    /**
     * @covers ::parse
     */
    public function testParseWithNoPrefix(): void {
        $yaml = <<<YAML
schema_version: "1.0.0"
packs:
  test:
    version: "1.0.0"
    pages: ["Page1", "Page2"]
YAML;

        $parser = new ManifestParser();
        $parsed = $parser->parse($yaml);
        $pack = $parsed['packs']['test'];
        
        // Verify pack structure includes all expected fields
        $this->assertArrayHasKey('id', $pack);
        $this->assertArrayHasKey('version', $pack);
        $this->assertArrayHasKey('description', $pack);
        $this->assertArrayHasKey('pages', $pack);
        $this->assertArrayHasKey('page_count', $pack);
        $this->assertArrayHasKey('depends_on', $pack);
        $this->assertArrayHasKey('tags', $pack);
    }
}
