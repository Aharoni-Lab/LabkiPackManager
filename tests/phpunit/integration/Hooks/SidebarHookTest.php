<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Hooks;

use LabkiPackManager\Hooks\SidebarHook;
use MediaWikiIntegrationTestCase;
use Skin;

/**
 * Tests for SidebarHook
 *
 * SidebarHook adds a "Pack Manager" navigation item to the MediaWiki sidebar.
 * These tests verify that the sidebar item is correctly added in various scenarios.
 *
 * @coversDefaultClass \LabkiPackManager\Hooks\SidebarHook
 */
final class SidebarHookTest extends MediaWikiIntegrationTestCase {

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testOnSkinBuildSidebar_WhenSidebarEmpty_CreatesLabkiSection(): void {
        $skin = $this->createMock(Skin::class);
        $sidebar = [];

        $result = SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $this->assertTrue($result, 'Hook should return true');
        $this->assertArrayHasKey('Labki', $sidebar, 'Sidebar should have Labki section');
        $this->assertCount(1, $sidebar['Labki'], 'Labki section should have one item');
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testOnSkinBuildSidebar_WhenSidebarEmpty_AddsPackManagerItem(): void {
        $skin = $this->createMock(Skin::class);
        $sidebar = [];

        SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $item = $sidebar['Labki'][0];
        $this->assertSame('Pack Manager', $item['text'], 'Item text should be "Pack Manager"');
        $this->assertStringContainsString('Special:LabkiPacksManager', $item['href'], 'Item should link to Special:LabkiPacksManager');
        $this->assertSame('n-labki-pack-manager', $item['id'], 'Item should have correct HTML ID');
        $this->assertFalse($item['active'], 'Item should not be marked as active');
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testOnSkinBuildSidebar_WhenLabkiSectionExists_AppendsToSection(): void {
        $skin = $this->createMock(Skin::class);
        $sidebar = [
            'Labki' => [
                ['text' => 'Existing Item', 'href' => '/wiki/Existing', 'id' => 'n-existing', 'active' => false],
            ],
        ];

        $result = SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $this->assertTrue($result, 'Hook should return true');
        $this->assertCount(2, $sidebar['Labki'], 'Labki section should have two items');
        $this->assertSame('Existing Item', $sidebar['Labki'][0]['text'], 'First item should be the existing item');
        $this->assertSame('Pack Manager', $sidebar['Labki'][1]['text'], 'Second item should be Pack Manager');
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testOnSkinBuildSidebar_WhenOtherSectionsExist_PreservesThem(): void {
        $skin = $this->createMock(Skin::class);
        $sidebar = [
            'navigation' => [
                ['text' => 'Main page', 'href' => '/wiki/Main_Page', 'id' => 'n-mainpage', 'active' => false],
            ],
            'TOOLBOX' => [
                ['text' => 'Special pages', 'href' => '/wiki/Special:SpecialPages', 'id' => 't-specialpages', 'active' => false],
            ],
        ];

        SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $this->assertArrayHasKey('navigation', $sidebar, 'navigation section should be preserved');
        $this->assertArrayHasKey('TOOLBOX', $sidebar, 'TOOLBOX section should be preserved');
        $this->assertArrayHasKey('Labki', $sidebar, 'Labki section should be added');
        $this->assertCount(3, $sidebar, 'Sidebar should have three sections');
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testOnSkinBuildSidebar_WhenLabkiSectionHasMultipleItems_AppendsCorrectly(): void {
        $skin = $this->createMock(Skin::class);
        $sidebar = [
            'Labki' => [
                ['text' => 'Item 1', 'href' => '/wiki/Item1', 'id' => 'n-item1', 'active' => false],
                ['text' => 'Item 2', 'href' => '/wiki/Item2', 'id' => 'n-item2', 'active' => false],
            ],
        ];

        SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $this->assertCount(3, $sidebar['Labki'], 'Labki section should have three items');
        $this->assertSame('Item 1', $sidebar['Labki'][0]['text']);
        $this->assertSame('Item 2', $sidebar['Labki'][1]['text']);
        $this->assertSame('Pack Manager', $sidebar['Labki'][2]['text']);
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testOnSkinBuildSidebar_ItemStructure_HasAllRequiredKeys(): void {
        $skin = $this->createMock(Skin::class);
        $sidebar = [];

        SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $item = $sidebar['Labki'][0];
        $this->assertArrayHasKey('text', $item, 'Item should have text key');
        $this->assertArrayHasKey('href', $item, 'Item should have href key');
        $this->assertArrayHasKey('id', $item, 'Item should have id key');
        $this->assertArrayHasKey('active', $item, 'Item should have active key');
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testOnSkinBuildSidebar_ItemStructure_HasCorrectTypes(): void {
        $skin = $this->createMock(Skin::class);
        $sidebar = [];

        SidebarHook::onSkinBuildSidebar($skin, $sidebar);

        $item = $sidebar['Labki'][0];
        $this->assertIsString($item['text'], 'text should be a string');
        $this->assertIsString($item['href'], 'href should be a string');
        $this->assertIsString($item['id'], 'id should be a string');
        $this->assertIsBool($item['active'], 'active should be a boolean');
    }

    /**
     * @covers ::onSkinBuildSidebar
     */
    public function testOnSkinBuildSidebar_AlwaysReturnsTrue(): void {
        $skin = $this->createMock(Skin::class);
        
        // Test with empty sidebar
        $sidebar1 = [];
        $result1 = SidebarHook::onSkinBuildSidebar($skin, $sidebar1);
        $this->assertTrue($result1, 'Should return true with empty sidebar');

        // Test with existing Labki section
        $sidebar2 = ['Labki' => [['text' => 'Existing', 'href' => '/wiki/Existing', 'id' => 'n-existing', 'active' => false]]];
        $result2 = SidebarHook::onSkinBuildSidebar($skin, $sidebar2);
        $this->assertTrue($result2, 'Should return true with existing Labki section');

        // Test with other sections
        $sidebar3 = ['navigation' => [['text' => 'Main', 'href' => '/wiki/Main', 'id' => 'n-main', 'active' => false]]];
        $result3 = SidebarHook::onSkinBuildSidebar($skin, $sidebar3);
        $this->assertTrue($result3, 'Should return true with other sections');
    }
}
