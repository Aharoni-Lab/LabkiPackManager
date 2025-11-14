<?php

/**
 * Service wiring for LabkiPackManager extension
 *
 * This file defines how services are instantiated in MediaWiki's service container.
 * Services registered here can be retrieved via MediaWikiServices::getInstance()->getService()
 *
 * @see https://www.mediawiki.org/wiki/Manual:Extension.json/Schema#ServiceWiringFiles
 */

use LabkiPackManager\Services\LabkiPackRegistry;
use LabkiPackManager\Services\LabkiPageRegistry;
use LabkiPackManager\Services\LabkiRefRegistry;
use LabkiPackManager\Services\LabkiRepoRegistry;
use MediaWiki\MediaWikiServices;

return [
	'LabkiRepoRegistry' => static function ( MediaWikiServices $services ): LabkiRepoRegistry {
		return new LabkiRepoRegistry();
	},

	'LabkiRefRegistry' => static function ( MediaWikiServices $services ): LabkiRefRegistry {
		// LabkiRefRegistry needs LabkiRepoRegistry for resolveRepoId
		return new LabkiRefRegistry(
			$services->getService( 'LabkiRepoRegistry' )
		);
	},

	'LabkiPackRegistry' => static function ( MediaWikiServices $services ): LabkiPackRegistry {
		return new LabkiPackRegistry();
	},

	'LabkiPageRegistry' => static function ( MediaWikiServices $services ): LabkiPageRegistry {
		return new LabkiPageRegistry();
	},
];

