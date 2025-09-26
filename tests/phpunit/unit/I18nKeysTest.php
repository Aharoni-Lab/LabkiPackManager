<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class I18nKeysTest extends TestCase {
    /**
     * Ensures critical i18n keys referenced in code exist in en.json.
     */
    public function testRequiredMessageKeysExist(): void {
        $file = __DIR__ . '/../../../i18n/en.json';
        $this->assertFileExists( $file );
        $data = json_decode( file_get_contents( $file ), true );
        $this->assertIsArray( $data );

        $requiredKeys = [
            'labkipackmanager-error-fetch',
            'labkipackmanager-error-parse',
            'labkipackmanager-error-schema',
            'labkipackmanager-list-title',
            'labkipackmanager-button-refresh',
            'labkipackmanager-status-using-cache',
            'labkipackmanager-status-fetched',
            'labkipackmanager-status-missing',
        ];

        foreach ( $requiredKeys as $key ) {
            $this->assertArrayHasKey( $key, $data );
        }
    }
}


