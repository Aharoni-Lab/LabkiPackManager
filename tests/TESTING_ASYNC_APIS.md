# Testing Async Job-Based APIs

This guide explains how to test MediaWiki API endpoints that use background jobs for async operations.

## Architecture Overview

Our async APIs follow this pattern:

```
User Request → API Endpoint → Job Queue → Background Job → Database Updates
                     ↓
                Operation Record (tracking)
```

### Components

1. **API Endpoint** (e.g., `ApiLabkiReposAdd`)
   - Validates parameters
   - Checks permissions
   - Creates operation record in `LabkiOperationRegistry`
   - Queues background job
   - Returns immediate response with `operation_id`

2. **Background Job** (e.g., `LabkiRepoAddJob`)
   - Executes the actual work (Git operations, DB updates)
   - Updates operation status (`queued` → `running` → `success`/`failed`)
   - Reports progress

3. **Operation Registry** (`LabkiOperationRegistry`)
   - Tracks async operations
   - Stores status, progress, messages, and results

## Testing Strategy

### Two-Layer Testing Approach

We test async APIs in **two separate layers**:

#### 1. API Layer Tests
Test that the API endpoint correctly:
- Validates input parameters
- Checks permissions
- Creates operation records
- Queues jobs with correct parameters
- Returns proper responses

**What NOT to test here:**
- Actual Git operations
- Background job execution
- File system changes

**Example:** `ApiLabkiReposAddTest.php`

#### 2. Job Layer Tests
Test that the background job correctly:
- Executes the intended operations
- Updates operation status
- Handles errors gracefully
- Produces expected results

**What NOT to test here:**
- API parameter validation
- Permission checking

**Example:** `LabkiRepoAddJobTest.php` (see below)

## Writing API Layer Tests

### Base Setup

```php
use ApiTestCase;
use LabkiPackManager\Services\LabkiOperationRegistry;
use MediaWiki\MediaWikiServices;

class ApiLabkiReposAddTest extends ApiTestCase {
    protected $tablesUsed = [
        'labki_content_repo',
        'labki_content_ref',
        'labki_operations',
        'job',  // Important for job queue tests
    ];

    protected function setUp(): void {
        parent::setUp();
        
        // Grant permissions for testing
        // IMPORTANT: Use setGroupPermissions() for immediate effect
        $this->setGroupPermissions( 'user', 'labkipack-manage', true );
    }
}
```

**Important Permission Note**: 
- Use `setGroupPermissions( 'user', 'permission', true )` in `setUp()`
- This takes immediate effect, unlike `mergeMwGlobalArrayValue`
- `'user'` group = logged-in users (what `getTestUser()` returns)
- `'*'` group = anonymous users (not logged in)

### Test Structure

#### 1. Success Cases
```php
public function testAddRepo_WithAllParams_QueuesJobAndReturnsOperationId(): void {
    $result = $this->doApiRequest( [
        'action' => 'labkiReposAdd',
        'url' => 'https://github.com/test/repo',
        'refs' => 'main|develop',
    ], null, false, $this->getTestUser()->getUser() );

    $data = $result[0];
    
    // Check response structure
    $this->assertTrue( $data['success'] );
    $this->assertStringStartsWith( 'repo_add_', $data['operation_id'] );
    $this->assertSame( 'queued', $data['status'] );
    
    // Verify operation was created
    $operationRegistry = new LabkiOperationRegistry();
    $this->assertTrue( $operationRegistry->operationExists( $data['operation_id'] ) );
    
    // Verify job was queued
    $jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
    $this->assertGreaterThan( 0, $jobQueue->get( 'labkiRepoAdd' )->getSize() );
}
```

#### 2. Validation Errors
```php
public function testAddRepo_WithMissingUrl_ReturnsError(): void {
    $this->expectException( \ApiUsageException::class );
    
    $this->doApiRequest( [
        'action' => 'labkiReposAdd',
    ], null, false, $this->getTestUser()->getUser() );
}
```

#### 3. Permission Checks
```php
public function testAddRepo_RequiresManagePermission(): void {
    // Remove permission from logged-in users
    $this->setGroupPermissions( 'user', 'labkipack-manage', false );
    
    $this->expectException( \ApiUsageException::class );
    
    $this->doApiRequest( [
        'action' => 'labkiReposAdd',
        'url' => 'https://github.com/test/repo',
    ], null, false, $this->getTestUser()->getUser() );
}
```

**Note**: Use `setGroupPermissions()` to modify permissions in tests. This is the MediaWiki-recommended method for testing permission checks.

#### 4. Edge Cases
- URL normalization (`.git` suffix removal)
- Multiple refs handling
- Default parameter values
- Existing repository checks

## Writing Job Layer Tests

### Base Setup

```php
use MediaWikiIntegrationTestCase;
use LabkiPackManager\Jobs\LabkiRepoAddJob;
use LabkiPackManager\Services\LabkiOperationRegistry;

class LabkiRepoAddJobTest extends MediaWikiIntegrationTestCase {
    protected $tablesUsed = [
        'labki_content_repo',
        'labki_content_ref',
        'labki_operations',
    ];

    protected function setUp(): void {
        parent::setUp();
        
        // Mock Git operations to avoid actual network/filesystem calls
        // (implementation depends on your architecture)
    }
}
```

### Test Structure

#### 1. Successful Execution
```php
public function testRun_WithValidParams_CreatesRepoAndRefs(): void {
    $operationId = 'test_op_' . uniqid();
    $operationRegistry = new LabkiOperationRegistry();
    $operationRegistry->createOperation(
        $operationId,
        LabkiOperationRegistry::TYPE_REPO_ADD
    );
    
    $job = new LabkiRepoAddJob( \Title::newMainPage(), [
        'url' => 'https://github.com/test/repo',
        'refs' => ['main', 'develop'],
        'default_ref' => 'main',
        'operation_id' => $operationId,
        'user_id' => 1,
    ] );
    
    // Run the job
    $result = $job->run();
    
    $this->assertTrue( $result );
    
    // Verify operation was marked as success
    $operation = $operationRegistry->getOperation( $operationId );
    $this->assertSame( LabkiOperationRegistry::STATUS_SUCCESS, $operation['status'] );
    $this->assertSame( 100, (int)$operation['progress'] );
    
    // Verify repository was created
    // Verify refs were created
    // etc.
}
```

#### 2. Error Handling
```php
public function testRun_WithInvalidUrl_FailsGracefully(): void {
    $operationId = 'test_op_' . uniqid();
    $operationRegistry = new LabkiOperationRegistry();
    $operationRegistry->createOperation(
        $operationId,
        LabkiOperationRegistry::TYPE_REPO_ADD
    );
    
    $job = new LabkiRepoAddJob( \Title::newMainPage(), [
        'url' => 'invalid-url',
        'refs' => ['main'],
        'operation_id' => $operationId,
        'user_id' => 1,
    ] );
    
    $result = $job->run();
    
    $this->assertFalse( $result );
    
    // Verify operation was marked as failed
    $operation = $operationRegistry->getOperation( $operationId );
    $this->assertSame( LabkiOperationRegistry::STATUS_FAILED, $operation['status'] );
}
```

#### 3. Progress Tracking
```php
public function testRun_UpdatesProgressDuringExecution(): void {
    // Create operation and job
    // Run job
    // Verify progress was updated at various stages
}
```

## Running Tests

### Run API Tests Only
```bash
docker compose exec mediawiki bash -lc 'composer phpunit:entrypoint -- \
  extensions/LabkiPackManager/tests/phpunit/integration/API/Repos/ApiLabkiReposAddTest.php'
```

### Run All Repo API Tests
```bash
docker compose exec mediawiki bash -lc 'composer phpunit:entrypoint -- \
  extensions/LabkiPackManager/tests/phpunit/integration/API/Repos/'
```

### Run Job Tests
```bash
docker compose exec mediawiki bash -lc 'composer phpunit:entrypoint -- \
  extensions/LabkiPackManager/tests/phpunit/integration/Jobs/'
```

### Run All Integration Tests
```bash
docker compose exec -e PHPUNIT_WIKI=wiki mediawiki bash -lc 'composer phpunit:entrypoint -- \
  extensions/LabkiPackManager/tests/phpunit/integration'
```

## Best Practices

### 1. Mock External Dependencies
- **DO**: Mock Git operations, network calls, file system operations
- **DON'T**: Make actual network requests or write to real filesystems in tests

### 2. Test Isolation
- Each test should be independent
- Use `$tablesUsed` to ensure database tables are reset between tests
- Create fresh test data in each test method

### 3. Clear Test Names
Use descriptive test method names that explain:
- What is being tested
- What conditions are being checked
- What the expected outcome is

**Good:**
```php
testAddRepo_WithAllParams_QueuesJobAndReturnsOperationId()
testAddRepo_WithMissingUrl_ReturnsError()
testAddRepo_WhenRepoExists_ReturnsSuccessImmediately()
```

**Bad:**
```php
testAddRepo1()
testAddRepo2()
testError()
```

### 4. Comprehensive Coverage
Test the following categories:
- ✅ Success cases (with various parameter combinations)
- ✅ Validation errors (missing/invalid parameters)
- ✅ Permission checks
- ✅ Edge cases (URL normalization, empty arrays, etc.)
- ✅ Operation tracking
- ✅ Response structure
- ✅ Metadata inclusion

### 5. Response Validation
Always verify:
- Response structure matches documentation
- Metadata is included (`_meta` field)
- Operation ID format is correct
- Status values are from defined constants
- Messages are meaningful

### 6. Operation Lifecycle
For job tests, verify the complete operation lifecycle:
```
queued → running → success/failed
  0%       50%         100%
```

## Mocking Strategies

### Strategy 1: Constructor Injection (Preferred)
```php
// In your class
public function __construct( GitContentManager $gitManager = null ) {
    $this->gitManager = $gitManager ?? new GitContentManager();
}

// In tests
$mockGitManager = $this->createMock( GitContentManager::class );
$job = new LabkiRepoAddJob( $title, $params, $mockGitManager );
```

### Strategy 2: Service Override
```php
// Use MediaWiki's service container to override services
$this->setService( 'GitContentManager', $mockGitManager );
```

### Strategy 3: Protected Method Override
```php
// Create a test subclass that overrides protected methods
class TestableLabkiRepoAddJob extends LabkiRepoAddJob {
    protected function executeGitClone( string $url ): void {
        // Mock implementation
    }
}
```

## Common Pitfalls

### ❌ Using Wrong Permission Method
**Bad:**
```php
// mergeMwGlobalArrayValue doesn't take immediate effect for permissions!
$this->mergeMwGlobalArrayValue( 'wgGroupPermissions', [
    'user' => [ 'labkipack-manage' => true ],
] );
```

**Good:**
```php
// setGroupPermissions takes immediate effect
$this->setGroupPermissions( 'user', 'labkipack-manage', true );
```

**Why**: `setGroupPermissions()` is the proper MediaWiki testing API for permissions and takes immediate effect. `mergeMwGlobalArrayValue` requires additional steps to apply.

### ❌ Testing Too Much in API Tests
**Bad:**
```php
public function testAddRepo_ExecutesGitOperations(): void {
    // Don't test Git operations in API tests!
}
```

**Good:**
```php
public function testAddRepo_QueuesJob(): void {
    // Only test that the job was queued
}
```

### ❌ Not Isolating Tests
**Bad:**
```php
// Tests that depend on execution order
private static $repoId;

public function testA() {
    self::$repoId = $this->createRepo();
}

public function testB() {
    // Uses self::$repoId from testA
}
```

**Good:**
```php
// Each test creates its own data
public function testA() {
    $repoId = $this->createRepo();
    // Use $repoId
}

public function testB() {
    $repoId = $this->createRepo();
    // Use $repoId
}
```

### ❌ Assuming Test Execution Order
PHPUnit does not guarantee test execution order. Each test must be independent.

### ❌ Not Cleaning Up
Use `$tablesUsed` to ensure tables are reset:
```php
protected $tablesUsed = [
    'labki_content_repo',
    'labki_content_ref',
    'labki_operations',
];
```

## Testing Checklist

### API Endpoint Tests
- [ ] Success case with all parameters
- [ ] Success case with minimal parameters
- [ ] Missing required parameters
- [ ] Invalid parameter values
- [ ] Empty parameter values
- [ ] Permission checks
- [ ] URL normalization
- [ ] Edge cases (existing repo, etc.)
- [ ] Response structure validation
- [ ] Metadata inclusion
- [ ] Operation creation
- [ ] Job queuing

### Job Tests
- [ ] Successful execution
- [ ] Failure handling
- [ ] Progress tracking
- [ ] Operation status updates
- [ ] Result data storage
- [ ] Error data storage
- [ ] Partial success scenarios
- [ ] Missing parameters
- [ ] Invalid parameters

## Example Test Coverage Report

```
API Layer (ApiLabkiReposAddTest):
✅ 15/15 tests passing
- Parameter validation: 5 tests
- Permission checks: 2 tests
- Edge cases: 4 tests
- Response validation: 4 tests

Job Layer (LabkiRepoAddJobTest):
✅ 12/12 tests passing
- Success scenarios: 4 tests
- Error handling: 4 tests
- Progress tracking: 2 tests
- Edge cases: 2 tests

Total Coverage: 27/27 tests (100%)
```

## Additional Resources

- MediaWiki API Testing: https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions
- PHPUnit Documentation: https://phpunit.de/documentation.html
- MediaWiki Job Queue: https://www.mediawiki.org/wiki/Manual:Job_queue

## Summary

Testing async job-based APIs requires a **two-layer approach**:
1. **API Layer**: Test request handling, validation, and job queuing
2. **Job Layer**: Test actual execution and operation updates

This separation ensures:
- Fast API tests (no actual operations)
- Thorough job tests (full execution coverage)
- Clear separation of concerns
- Easy debugging when things go wrong

Remember: **API tests verify the interface, Job tests verify the implementation.**

