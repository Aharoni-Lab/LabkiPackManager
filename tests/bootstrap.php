<?php

// Load Composer autoloader first
require __DIR__ . '/../vendor/autoload.php';

// Simple PSR-4 autoloader for MediaWiki test stubs if not provided by Composer
if ( !class_exists( 'MediaWiki\\MediaWikiServices' ) ) {
	spl_autoload_register( static function ( string $class ) : void {
		if ( str_starts_with( $class, 'MediaWiki\\' ) ) {
			$path = __DIR__ . '/Support/' . str_replace( '\\', '/', $class ) . '.php';
			if ( file_exists( $path ) ) {
				require $path;
			}
		}
	} );
}


