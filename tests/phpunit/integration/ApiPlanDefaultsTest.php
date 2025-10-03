<?php

declare(strict_types=1);

/**
 * @group API
 * @group Database
 */
final class ApiPlanDefaultsTest extends \MediaWikiIntegrationTestCase {
    /** @covers \LabkiPackManager\API\ApiLabkiPacks::execute */
    public function testDefaultsGlobalPrefixPresent(): void {
        $extPath = ( defined('MW_INSTALL_PATH') ? MW_INSTALL_PATH : dirname(__DIR__, 3) ) . '/extensions/LabkiPackManager';
        $manifest = $extPath . '/tests/fixtures/manifest-empty.yml';
        $this->setMwGlobals( [
            'wgLabkiGlobalPrefix' => 'ABC',
            'wgLabkiContentSources' => [ 'Lab Packs (Default)' => $manifest, 'Local' => $manifest ]
        ] );
        $req = new \MediaWiki\Request\FauxRequest( [ 'action' => 'labkipacks', 'format' => 'json', 'repo' => 'Local' ] );
        $api = new \ApiMain( $req );
        $api->execute();
        $data = $api->getResult()->getResultData( null );
        $payload = $data['labkipacks'] ?? $data;
        $this->assertIsArray( $payload );
        $this->assertSame( 'ABC', $payload['defaults']['globalPrefix'] ?? null );
    }
}
