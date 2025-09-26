<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration;

use LabkiPackManager\Services\ManifestValidator;

/**
 * @coversDefaultClass \LabkiPackManager\Services\ManifestValidator
 */
class ManifestValidationIntegrationTest extends \MediaWikiIntegrationTestCase {
    /**
     * @covers ::validate
     */
    public function testValidateFixture(): void {
        $fixture = __DIR__ . '/../../fixtures/manifest.yml';
        $this->assertFileExists( $fixture );
        $yaml = file_get_contents( $fixture );
        $validator = new ManifestValidator();
        $status = $validator->validate( $yaml );
        $this->assertTrue( $status->isOK(), 'Fixture manifest should validate' );
        $decoded = $status->getValue();
        $this->assertIsArray( $decoded );
        $this->assertArrayHasKey( 'packs', $decoded );
    }
}


