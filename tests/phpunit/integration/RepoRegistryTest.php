<?php

declare(strict_types=1);

use LabkiPackManager\Services\LabkiRepoRegistry;
use LabkiPackManager\Domain\ContentRepoId;
use LabkiPackManager\Domain\ContentRepo;

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
        $this->assertInstanceOf( ContentRepoId::class, $id );
        $this->assertGreaterThan( 0, $id->toInt() );

        $same = $svc->ensureRepo( $url );
        $this->assertInstanceOf( ContentRepoId::class, $same );
        $this->assertTrue( $id->equals( $same ) );

        $found = $svc->getRepoIdByUrl( $url );
        $this->assertInstanceOf( ContentRepoId::class, $found );
        $this->assertTrue( $id->equals( $found ) );

        $info = $svc->getRepoById( $id );
        $this->assertInstanceOf( ContentRepo::class, $info );
        $this->assertSame( $url, $info->url() );
        $this->assertSame( 'main', $info->defaultRef() );

        $svc->updateRepo( $id, [ 'default_ref' => 'develop' ] );
        $info2 = $svc->getRepoById( $id );
        $this->assertInstanceOf( ContentRepo::class, $info2 );
        $this->assertSame( 'develop', $info2->defaultRef() );

        $all = $svc->listRepos();
        $this->assertNotEmpty( $all );
        $this->assertInstanceOf( ContentRepo::class, $all[0] );

        $svc->deleteRepo( $id );
        $info3 = $svc->getRepoById( $id );
        $this->assertNull( $info3 );
    }
}


