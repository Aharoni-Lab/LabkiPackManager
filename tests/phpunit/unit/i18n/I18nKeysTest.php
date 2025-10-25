<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class I18nKeysTest extends TestCase {
    private array $i18nData;

    protected function setUp(): void {
        $file = __DIR__ . '/../../../../i18n/en.json';
        $this->assertFileExists( $file, 'i18n/en.json file must exist' );
        $this->i18nData = json_decode( file_get_contents( $file ), true );
        $this->assertIsArray( $this->i18nData, 'i18n/en.json must contain valid JSON' );
    }

    /**
     * Ensures critical i18n keys referenced in code exist in en.json.
     */
    public function testRequiredMessageKeysExist(): void {
        $requiredKeys = [
            // Core extension metadata
            'labkipackmanager-name',
            'labkipackmanager-desc',
            
            // Special pages
            'labkipackmanager-special-title',
            'labkipackmanager-dbviewer-title',
            
            // Permissions
            'right-labkipackmanager-manage',
            
            // Error messages (used in Status objects and API responses)
            'labkipackmanager-error-fetch',
            'labkipackmanager-error-parse',
            'labkipackmanager-error-schema',
            'labkipackmanager-error-schema-version',
            'labkipackmanager-error-unknown',
            'labkipackmanager-error-permission',
            'labkipackmanager-error-no-sources',
            'labkipackmanager-error-repo-not-found',
            'labkipackmanager-error-pack-not-found',
            'labkipackmanager-error-page-not-found',
            'labkipackmanager-error-manifest-missing',
            'labkipackmanager-error-manifest-empty',
            'labkipackmanager-error-manifest-unreadable',
            'labkipackmanager-error-manifest-read',
            'labkipackmanager-error-invalid-manifest',
            
            // UI labels
            'labkipackmanager-list-title',
            'labkipackmanager-button-refresh',
            'labkipackmanager-button-load',
            
            // Status messages
            'labkipackmanager-status-using-cache',
            'labkipackmanager-status-fetched',
            'labkipackmanager-status-missing',
            
            // Action messages
            'labkipackmanager-action-install',
            'labkipackmanager-action-remove',
            'labkipackmanager-action-update',
            'labkipackmanager-action-complete',
        ];

        foreach ( $requiredKeys as $key ) {
            $this->assertArrayHasKey( $key, $this->i18nData, "Required i18n key '$key' is missing from en.json" );
            $this->assertNotEmpty( $this->i18nData[$key], "i18n key '$key' must not be empty" );
        }
    }

    /**
     * Ensures all keys follow the labkipackmanager- prefix convention.
     */
    public function testAllKeysFollowNamingConvention(): void {
        $exceptions = [
            '@metadata',
            'specialpages-group-labki',
            'right-labkipackmanager-manage', // MediaWiki permission key
        ];
        
        // Allow MediaWiki API help message keys (apihelp-* pattern)
        $allowedPrefixes = [
            'labkipackmanager-',
            'apihelp-', // MediaWiki API documentation keys
        ];
        
        foreach ( array_keys( $this->i18nData ) as $key ) {
            if ( in_array( $key, $exceptions, true ) ) {
                continue;
            }
            
            // Check if key starts with any allowed prefix
            $hasValidPrefix = false;
            foreach ( $allowedPrefixes as $prefix ) {
                if ( str_starts_with( $key, $prefix ) ) {
                    $hasValidPrefix = true;
                    break;
                }
            }
            
            $this->assertTrue(
                $hasValidPrefix,
                "i18n key '$key' must start with one of: " . implode( ', ', $allowedPrefixes ) . " (or be in exceptions list)"
            );
        }
    }

    /**
     * Ensures error keys follow a consistent pattern.
     */
    public function testErrorKeysFollowPattern(): void {
        $errorKeys = array_filter(
            array_keys( $this->i18nData ),
            fn( $key ) => str_starts_with( $key, 'labkipackmanager-error-' )
        );

        $this->assertNotEmpty( $errorKeys, 'Must have at least one error message key' );

        foreach ( $errorKeys as $key ) {
            // Error messages should be descriptive sentences
            $message = $this->i18nData[$key];
            $this->assertNotEmpty( $message, "Error key '$key' must have a non-empty message" );
            $this->assertGreaterThan( 10, strlen( $message ), "Error message for '$key' should be descriptive (>10 chars)" );
        }
    }

    /**
     * Ensures action keys follow a consistent pattern.
     */
    public function testActionKeysFollowPattern(): void {
        $actionKeys = array_filter(
            array_keys( $this->i18nData ),
            fn( $key ) => str_starts_with( $key, 'labkipackmanager-action-' )
        );

        $this->assertNotEmpty( $actionKeys, 'Must have at least one action message key' );

        foreach ( $actionKeys as $key ) {
            $message = $this->i18nData[$key];
            $this->assertNotEmpty( $message, "Action key '$key' must have a non-empty message" );
        }
    }

    /**
     * Ensures metadata is properly formatted.
     */
    public function testMetadataIsValid(): void {
        $this->assertArrayHasKey( '@metadata', $this->i18nData, 'Must have @metadata section' );
        $this->assertIsArray( $this->i18nData['@metadata'], '@metadata must be an array' );
        $this->assertArrayHasKey( 'authors', $this->i18nData['@metadata'], '@metadata must have authors field' );
        $this->assertIsArray( $this->i18nData['@metadata']['authors'], 'authors must be an array' );
        $this->assertNotEmpty( $this->i18nData['@metadata']['authors'], 'authors must not be empty' );
    }

    /**
     * Ensures no duplicate or redundant keys exist.
     */
    public function testNoDuplicateKeys(): void {
        $keys = array_keys( $this->i18nData );
        $uniqueKeys = array_unique( $keys );
        
        $this->assertCount(
            count( $keys ),
            $uniqueKeys,
            'i18n file must not contain duplicate keys'
        );
    }
}


