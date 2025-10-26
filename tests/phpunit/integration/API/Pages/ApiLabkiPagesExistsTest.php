<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Pages;

use ApiTestCase;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;
use MediaWiki\CommentStore\CommentStoreComment;
use WikitextContent;

/**
 * Integration tests for ApiLabkiPagesExists.
 *
 * Covers:
 * - Single and multiple title queries
 * - Existing and non-existing pages
 * - Missing titles validation
 * - Invalid titles return error info
 * - Response meta presence
 * - Read-only and public properties
 *
 * @covers \LabkiPackManager\API\Pages\ApiLabkiPagesExists
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
final class ApiLabkiPagesExistsTest extends ApiTestCase {

	/** @var string[] */
	protected $tablesUsed = [ 'page' ];

	/**
	 * Create a page using WikiPageFactory + PageUpdater (MW 1.44 compatible).
	 */
	private function createTestPage(string $titleText, string $contentText = 'Sample content'): Title {
		$title = Title::newFromText($titleText);
		$this->assertNotNull($title, 'Title must be valid');

		$services = MediaWikiServices::getInstance();
		$page = $services->getWikiPageFactory()->newFromTitle($title);

		$user = $this->getTestUser()->getUser();
		$updater = $page->newPageUpdater($user);
		$updater->setContent('main', new WikitextContent($contentText));
		$updater->saveRevision(CommentStoreComment::newUnsavedComment('Creating test page'));

		return $title;
	}

	public function testSingleExistingPage_ReturnsExistsTrue(): void {
		$title = $this->createTestPage('TestExistingPage');

		$result = $this->doApiRequest([
			'action' => 'labkiPagesExists',
			'titles' => $title->getPrefixedText(),
		]);

		$data = $result[0];
		$this->assertArrayHasKey('results', $data);
		$this->assertArrayHasKey($title->getPrefixedText(), $data['results']);

		$pageData = $data['results'][$title->getPrefixedText()];
		$this->assertTrue($pageData['exists']);
		$this->assertArrayHasKey('page_id', $pageData);
		$this->assertArrayHasKey('namespace', $pageData);
		$this->assertSame(NS_MAIN, $pageData['namespace']);

		$this->assertArrayHasKey('meta', $data);
		$this->assertSame(1, $data['meta']['schemaVersion']);
		$this->assertArrayHasKey('timestamp', $data['meta']);
	}

	public function testMultipleTitles_MixedExistence(): void {
		$title1 = $this->createTestPage('ExistingPage1');
		$nonExistent = 'NonExistentPage2';

		$result = $this->doApiRequest([
			'action' => 'labkiPagesExists',
			'titles' => $title1->getPrefixedText() . '|' . $nonExistent,
		]);

		$data = $result[0];
		$results = $data['results'];

		$this->assertTrue($results[$title1->getPrefixedText()]['exists']);
		$this->assertArrayHasKey('page_id', $results[$title1->getPrefixedText()]);
		$this->assertArrayHasKey('namespace', $results[$title1->getPrefixedText()]);

		$this->assertFalse($results[$nonExistent]['exists']);
		$this->assertArrayNotHasKey('page_id', $results[$nonExistent]);
	}

	public function testMissingTitlesParam_ReturnsError(): void {
		$this->expectException(\ApiUsageException::class);
		$this->doApiRequest([ 'action' => 'labkiPagesExists' ]);
	}

	public function testInvalidTitleNames_ReturnError(): void {
		$valid = $this->createTestPage('ValidPage');
		$invalid = 'Invalid[Title]'; // definitely invalid in MW

		$result = $this->doApiRequest([
			'action' => 'labkiPagesExists',
			'titles' => $valid->getPrefixedText() . '|' . $invalid,
		]);

		$data = $result[0];
		$results = $data['results'];

		// Valid page should work normally
		$this->assertArrayHasKey($valid->getPrefixedText(), $results);
		$this->assertTrue($results[$valid->getPrefixedText()]['exists']);

		// Invalid title should be returned with error info
		$this->assertArrayHasKey($invalid, $results);
		$invalidResult = $results[$invalid];
		$this->assertFalse($invalidResult['exists']);
		$this->assertFalse($invalidResult['valid']);
		$this->assertArrayHasKey('error', $invalidResult);
		$this->assertSame('Invalid title format', $invalidResult['error']);
	}

	public function testResponse_IncludesMeta(): void {
		$this->createTestPage('MetaCheckPage');

		$result = $this->doApiRequest([
			'action' => 'labkiPagesExists',
			'titles' => 'MetaCheckPage',
		]);

		$data = $result[0];
		$this->assertArrayHasKey('meta', $data);
		$this->assertArrayHasKey('schemaVersion', $data['meta']);
		$this->assertArrayHasKey('timestamp', $data['meta']);
	}

	public function testApiProperties_ReadOnlyAndPublic(): void {
		$api = $this->getApiModule();
		$this->assertFalse($api->isWriteMode());
		$this->assertFalse($api->isInternal());
	}

	/**
	 * Instantiate the API module for property checks.
	 *
	 * @return \LabkiPackManager\API\Pages\ApiLabkiPagesExists
	 */
	private function getApiModule() {
		global $wgRequest;
		return new \LabkiPackManager\API\Pages\ApiLabkiPagesExists(
			new \ApiMain($wgRequest),
			'labkiPagesExists'
		);
	}
}
