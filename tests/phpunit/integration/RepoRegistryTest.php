<?php

declare(strict_types=1);

use LabkiPackManager\Services\LabkiRepoRegistry;

/**
 * @group Database
 * @covers \LabkiPackManager\Services\LabkiRepoRegistry
 */
final class RepoRegistryTest extends \MediaWikiIntegrationTestCase {
    protected static $tablesUsed = [ 'labki_content_repo' ];

    public function testAddEnsureGetUpdateListDelete(): void {
        $svc = new LabkiRepoRegistry();
        $url = 'https://example.com/repoA/manifest.yml';

        $id = $svc->addRepo( $url, 'main' );
        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );

        $same = $svc->ensureRepo( $url );
        $this->assertSame( $id, $same );

        $found = $svc->getRepoIdByUrl( $url );
        $this->assertSame( $id, $found );

        $info = $svc->getRepoById( $id );
        $this->assertNotNull( $info );
        $this->assertSame( $url, $info['repo_url'] );
        $this->assertSame( 'main', $info['default_ref'] );

        $svc->updateRepo( $id, [ 'default_ref' => 'develop' ] );
        $info2 = $svc->getRepoById( $id );
        $this->assertSame( 'develop', $info2['default_ref'] );

        $all = $svc->listRepos();
        $this->assertNotEmpty( $all );

        $svc->deleteRepo( $id );
        $info3 = $svc->getRepoById( $id );
        $this->assertNull( $info3 );
    }
}


