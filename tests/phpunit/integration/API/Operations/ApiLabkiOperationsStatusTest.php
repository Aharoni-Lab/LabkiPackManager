<?php

declare(strict_types=1);

namespace LabkiPackManager\Tests\Integration\API\Operations;

use ApiTestCase;
use LabkiPackManager\Services\LabkiOperationRegistry;
use LabkiPackManager\Domain\OperationId;
use MediaWiki\User\User;

/**
 * Integration tests for ApiLabkiOperationsStatus.
 *
 * Verifies:
 * - Single operation mode (existing, non-existing, permission denied)
 * - List operations mode (own operations, manager visibility)
 * - Proper response structure and metadata fields
 * - Correct parsing of result_data (valid and invalid JSON)
 * - Parameter validation (limit bounds and invalid input)
 * - API properties (internal, read-only, GET-only)
 *
 * These tests simulate realistic scenarios for both normal users
 * and users with 'labkipackmanager-manage' permissions.
 *
 * @covers \LabkiPackManager\API\Operations\ApiLabkiOperationsStatus
 * @group LabkiPackManager
 * @group Database
 * @group API
 */
final class ApiLabkiOperationsStatusTest extends ApiTestCase {

	private LabkiOperationRegistry $operationRegistry;

	/** @var string[] Database tables used by these tests */
	protected $tablesUsed = [
		'labki_operations',
	];

	/** Setup test environment and initialize registry */
	protected function setUp(): void {
		parent::setUp();
		$this->operationRegistry = new LabkiOperationRegistry();
		// Note: no global permission grants here; permissions are set per test
	}

	/**
	 * Helper method to create a test operation entry.
	 *
	 * @param string $type Operation type (e.g. 'repo_add')
	 * @param string $status Current operation status
	 * @param int $progress Progress percentage (0–100)
	 * @param string $message Optional message
	 * @param int $userId Owner user ID
	 * @param string|null $resultData Optional result JSON or string
	 * @return OperationId
	 */
	private function createTestOperation(
		string $type = 'repo_add',
		string $status = 'running',
		int $progress = 50,
		string $message = 'Processing...',
		int $userId = 1,
		?string $resultData = null
	): OperationId {
		$opId = new OperationId('test_' . uniqid());
		$this->operationRegistry->createOperation($opId, $type, $userId, $status, $message);
		$this->operationRegistry->updateOperation($opId, $status, $message, $progress, $resultData);
		return $opId;
	}

	/** Test that fetching an existing operation returns expected structure and fields */
	public function testGetSingleOperation_ReturnsExpectedFields(): void {
		$opId = $this->createTestOperation('repo_add', 'running', 60, 'Initializing...', 1);

		$result = $this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'operation_id' => $opId->toString(),
		]);

		$data = $result[0];
		$this->assertSame($opId->toString(), $data['operation_id']);
		$this->assertSame('repo_add', $data['operation_type']);
		$this->assertSame('running', $data['status']);
		$this->assertSame(60, $data['progress']);
		$this->assertArrayHasKey('meta', $data);
		$this->assertSame(1, $data['meta']['schemaVersion']);
	}

	/** Test that querying a nonexistent operation throws an ApiUsageException */
	public function testGetSingleOperation_WhenNotFound_ReturnsError(): void {
		$this->expectException(\ApiUsageException::class);
		$this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'operation_id' => 'nonexistent_123',
		]);
	}

	/** Test that users only see their own operations by default */
	public function testListOperations_ForCurrentUser_ReturnsOnlyOwn(): void {
		// Ensure no manage permission is granted
		$this->setGroupPermissions('user', 'labkipackmanager-manage', false);
		
		// Get a test user (will be user ID 1)
		$user = $this->getTestUser()->getUser();
		
		// Create operations for two different users
		$this->createTestOperation('repo_add', 'queued', 0, 'Queued...', $user->getId());
		$this->createTestOperation('repo_add', 'running', 30, 'In progress...', 2);

		$result = $this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'limit' => 10,
		], null, false, $user);

		$data = $result[0];
		$this->assertArrayHasKey('operations', $data);
		$this->assertSame(1, $data['count'], 'Non-manager should only see own operations');
		foreach ($data['operations'] as $op) {
			$this->assertSame($user->getId(), $op['user_id'], 'Non-manager should only see own operations');
			$this->assertArrayHasKey('status', $op);
			$this->assertArrayHasKey('operation_id', $op);
		}
	}

	/** Test that list mode includes schemaVersion and timestamp metadata */
	public function testListOperations_IncludesMeta(): void {
		$this->createTestOperation();

		$result = $this->doApiRequest(['action' => 'labkiOperationsStatus']);
		$data = $result[0];
		$this->assertArrayHasKey('meta', $data);
		$this->assertArrayHasKey('schemaVersion', $data['meta']);
		$this->assertArrayHasKey('timestamp', $data['meta']);
	}

	/** Test that limit parameter is respected */
	public function testListOperations_RespectsLimit(): void {
		for ($i = 0; $i < 5; $i++) {
			$this->createTestOperation('repo_add', 'running', 10 * $i);
		}

		$result = $this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'limit' => 2,
		]);

		$data = $result[0];
		$this->assertArrayHasKey('operations', $data);
		$this->assertLessThanOrEqual(2, $data['count']);
	}

	/** Test that invalid limit (below min) triggers validation error */
	public function testListOperations_WithInvalidLimit_ReturnsError(): void {
		$this->expectException(\ApiUsageException::class);
		$this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'limit' => 0,
		]);
	}

	/** Test that JSON result_data is parsed correctly into an array */
	public function testGetSingleOperation_WithJsonResultData_ParsesJson(): void {
		$json = json_encode(['files' => 3, 'success' => true]);
		$opId = $this->createTestOperation('repo_sync', 'success', 100, 'Done', 1, $json);

		$result = $this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'operation_id' => $opId->toString(),
		]);

		$data = $result[0];
		$this->assertIsArray($data['result_data']);
		$this->assertSame(3, $data['result_data']['files']);
		$this->assertTrue($data['result_data']['success']);
	}

	/** Test that invalid JSON in result_data is returned as empty array */
	public function testGetSingleOperation_WithInvalidJson_ReturnsEmptyArray(): void {
		$opId = $this->createTestOperation('repo_sync', 'failed', 0, 'Error', 1, '{invalid_json');

		$result = $this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'operation_id' => $opId->toString(),
		]);

		$data = $result[0];
		$this->assertIsArray($data['result_data']);
		$this->assertEmpty($data['result_data']);
	}

	/** Test that non-managers cannot view another user's operation */
	public function testGetSingleOperation_PermissionDenied_WhenNotManager(): void {
		// Ensure no manage permission is granted
		$this->setGroupPermissions('user', 'labkipackmanager-manage', false);
		
		// Get a test user (will be user ID 1)
		$user = $this->getTestUser()->getUser();
		
		$this->expectException(\ApiUsageException::class);
		$opId = $this->createTestOperation('repo_add', 'running', 40, 'Working...', 2);

		$this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'operation_id' => $opId->toString(),
		], null, false, $user);
	}

	/** Test that users with manage permission can view all operations */
	public function testGetSingleOperation_ManagerCanViewOthers(): void {
		// Grant manage permission to logged-in users
		$this->setGroupPermissions('user', 'labkipackmanager-manage', true);
		
		// Create a user with 'labkipackmanager-manage' permission
		$user = $this->getTestUser(['labkipackmanager-manage'])->getUser();

		// Create an operation owned by a different user
		$opId = $this->createTestOperation('repo_add', 'running', 40, 'Working...', 2);

		// Execute as manager user
		$result = $this->doApiRequest([
			'action' => 'labkiOperationsStatus',
			'operation_id' => $opId->toString(),
		], null, false, $user);

		$data = $result[0];
		$this->assertSame($opId->toString(), $data['operation_id']);
		$this->assertSame(2, $data['user_id']);
	}

	/** Test that API properties reflect its read-only, internal nature */
	public function testApiProperties_ReadOnlyAndInternal(): void {
		$this->assertFalse($this->getApiModule()->mustBePosted());
		$this->assertFalse($this->getApiModule()->isWriteMode());
		$this->assertTrue($this->getApiModule()->isInternal());
	}

	/**
	 * Helper to instantiate the API module directly for property inspection.
	 *
	 * @return \LabkiPackManager\API\Operations\ApiLabkiOperationsStatus
	 */
	private function getApiModule() {
		global $wgRequest;
		return new \LabkiPackManager\API\Operations\ApiLabkiOperationsStatus(
			new \ApiMain($wgRequest),
			'labkiOperationsStatus'
		);
	}
}
