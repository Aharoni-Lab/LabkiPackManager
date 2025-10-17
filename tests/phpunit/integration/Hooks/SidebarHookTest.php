<?php
declare(strict_types=1);

namespace LabkiPackManager\Tests\Hooks;

use LabkiPackManager\Hooks\SidebarHook;
use MediaWikiIntegrationTestCase;

/**
 * @coversDefaultClass \LabkiPackManager\Hooks\SidebarHook
 */
final class SidebarHookTest extends MediaWikiIntegrationTestCase {

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testAddsLabkiSection(): void {
        $skin = $this->createMock(\Skin::class);
        $sidebar = [];

        $result = SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $this->assertTrue($result);
        $this->assertArrayHasKey('Labki', $sidebar);
        $this->assertCount(1, $sidebar['Labki']);

        $item = $sidebar['Labki'][0];
        $this->assertSame('Pack Manager', $item['text']);
        $this->assertStringContainsString('Special:LabkiPackManager', $item['href']);
        $this->assertSame('n-labki-pack-manager', $item['id']);
        $this->assertFalse($item['active']);
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testAppendsIfSectionExists(): void {
        $skin = $this->createMock(\Skin::class);
        $sidebar = [
            'Labki' => [
                [ 'text' => 'Existing', 'href' => '/wiki/Existing' ],
            ],
        ];

        $result = SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $this->assertTrue($result);
        $this->assertCount(2, $sidebar['Labki']);
        $this->assertSame('Existing', $sidebar['Labki'][0]['text']);
        $this->assertSame('Pack Manager', $sidebar['Labki'][1]['text']);
        $this->assertStringContainsString('Special:LabkiPackManager', $sidebar['Labki'][1]['href']);
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testCreatesSectionWhenSidebarEmpty(): void {
        $skin = $this->createMock(\Skin::class);
        $sidebar = [];

        $result = SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $this->assertTrue($result);
        $this->assertArrayHasKey('Labki', $sidebar);
        $this->assertSame('Pack Manager', $sidebar['Labki'][0]['text']);
        $this->assertStringContainsString('Special:LabkiPackManager', $sidebar['Labki'][0]['href']);
        $this->assertSame('n-labki-pack-manager', $sidebar['Labki'][0]['id']);
    }
}
