<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Validates critical i18n message keys and naming conventions.
 * 
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
	 * Update this list when adding new code that depends on specific message keys.
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
			
			// Critical error messages (used in Status objects and API responses)
			'labkipackmanager-error-fetch',
			'labkipackmanager-error-parse',
			'labkipackmanager-error-schema',
			'labkipackmanager-error-unknown',
			'labkipackmanager-error-permission',
			'labkipackmanager-error-no-sources',
			'labkipackmanager-error-repo-not-found',
			'labkipackmanager-error-pack-not-found',
			'labkipackmanager-error-manifest-missing',
			'labkipackmanager-error-invalid-manifest',
		];

		foreach ( $requiredKeys as $key ) {
			$this->assertArrayHasKey( $key, $this->i18nData, "Required i18n key '$key' is missing from en.json" );
			$this->assertNotEmpty( $this->i18nData[$key], "i18n key '$key' must not be empty" );
		}
	}

	/**
	 * Ensures all keys follow the labkipackmanager- prefix convention.
	 * This prevents namespace pollution in MediaWiki's global message system.
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
	 * Ensures metadata is properly formatted.
	 * MediaWiki requires this structure for translation management.
	 */
	public function testMetadataIsValid(): void {
		$this->assertArrayHasKey( '@metadata', $this->i18nData, 'Must have @metadata section' );
		$this->assertIsArray( $this->i18nData['@metadata'], '@metadata must be an array' );
		$this->assertArrayHasKey( 'authors', $this->i18nData['@metadata'], '@metadata must have authors field' );
		$this->assertIsArray( $this->i18nData['@metadata']['authors'], 'authors must be an array' );
		$this->assertNotEmpty( $this->i18nData['@metadata']['authors'], 'authors must not be empty' );
	}
}

