# Implementation Notes: ApiLabkiPacksAction Handlers

## Completed Work

### ✅ All 8 Handlers Created

1. **InitHandler** (`includes/Handlers/Packs/InitHandler.php`)
   - Loads manifest and installed packs
   - Creates initial session state
   - Marks already-installed packs as selected

2. **SelectPackHandler** (`includes/Handlers/Packs/SelectPackHandler.php`)
   - Selects a pack
   - Auto-resolves dependencies
   - Detects page conflicts
   - Returns warnings for conflicts

3. **DeselectPackHandler** (`includes/Handlers/Packs/DeselectPackHandler.php`)
   - Deselects a pack
   - Checks for dependent packs
   - Supports cascade mode (automatically deselects dependents)
   - Re-resolves dependencies after deselection

4. **RenamePageHandler** (`includes/Handlers/Packs/RenamePageHandler.php`)
   - Renamed from `setPageTitle` command
   - Updates final_title for a specific page
   - Detects new conflicts with updated title
   - Supports arbitrary page title customization

5. **SetPackPrefixHandler** (`includes/Handlers/Packs/SetPackPrefixHandler.php`)
   - Updates pack prefix
   - Automatically recomputes all page titles
   - Detects conflicts with new titles
   - Simpler interface than individual page rename

6. **RefreshHandler** (`includes/Handlers/Packs/RefreshHandler.php`)
   - Refreshes session state
   - Re-resolves all dependencies
   - Detects page conflicts
   - Useful after manifest updates

7. **ClearHandler** (`includes/Handlers/Packs/ClearHandler.php`)
   - Clears session state
   - Deletes state from storage
   - Returns empty state
   - Marks as `save: false` (already cleared)

8. **ApplyHandler** (`includes/Handlers/Packs/ApplyHandler.php`)
   - Applies operations
   - Builds operations array from state
   - Creates operation record
   - Queues background job
   - Clears state after applying
   - Returns `operation_info` in special format

### ✅ ApiLabkiPacksAction Improvements

1. **Error Handling** (`includes/API/Packs/ApiLabkiPacksAction.php`)
   - Added try-catch for handler execution
   - Catches `\InvalidArgumentException` → `invalid_argument` error
   - Catches `\RuntimeException` → `handler_error`
   - Catches generic `\Exception` → `internal_error`
   - Debug logging for unexpected errors

2. **Operation Info Support**
   - Handlers can return `operation_info` key
   - ApiLabkiPacksAction includes it in response as `operation` field
   - ApplyHandler uses this for operation metadata

3. **Uniform Response Format**
   - All commands return: `ok`, `diff`, `warnings`, `state_hash`, `meta`
   - Optional: `operation` (from ApplyHandler)
   - Consistent schema makes frontend integration easier

## Architecture Highlights

### Handler Inheritance

```
PackCommandHandler (interface)
        ↑
        |
  BasePackHandler (abstract)
        ↑
        |────────────────────────────────────────────────
        |               |        |        |       |  |     |
   InitHandler    SelectPack  Deselect Rename  Set  Refresh Clear Apply
                  Handler     Handler  Page    Pack Handler Handler Handler
                              Handler  Handler Prefix
                                       Handler
```

### Base Handler Provides

- `resolveDependencies()` - Resolves dependency tree and auto-selects
- `detectPageConflicts()` - Checks if pages exist in wiki
- `result()` - Convenience method for building result arrays

### Dependency Injection

Handlers accept optional DI:

```php
public function __construct(
    ?DependencyResolver $resolver = null,
    ?PackStateStore $stateStore = null
)
```

This allows for:
- Easy mocking in tests
- Custom implementations if needed
- Default implementations if not provided

## Important Implementation Details

### ApplyHandler Special Handling

ApplyHandler has unique characteristics:

1. **Returns `operation_info`** instead of regular response:
```php
[
    'state' => $newState,
    'warnings' => [],
    'save' => false,          // Don't persist empty state
    'operation_info' => [     // Special field for apply
        'operation_id' => '...',
        'status' => 'queued',
        'summary' => [...]
    ]
]
```

2. **ApiLabkiPacksAction handles this**:
```php
if ( $operationInfo !== null && is_array( $operationInfo ) ) {
    $responseData['operation'] = $operationInfo;
}
```

3. **Frontend receives**:
```json
{
  "ok": true,
  "diff": {},
  "operation": {
    "operation_id": "...",
    "status": "queued",
    "summary": {...}
  }
}
```

### Error Validation Pattern

All handlers follow this pattern:

```php
// 1. Extract parameters
$packName = $data['pack_name'] ?? null;

// 2. Validate each parameter individually
if ( !$packName || !is_string( $packName ) ) {
    throw new \InvalidArgumentException( 'invalid or missing pack_name' );
}

// 3. Validate pack exists in state
if ( !$state->hasPack( $packName ) ) {
    throw new \InvalidArgumentException( "pack '{$packName}' not found" );
}

// 4. Business logic
$state->selectPack( $packName );

// 5. Return result
return $this->result( $state, $warnings );
```

### Exception Types

- **`\InvalidArgumentException`** - For parameter validation errors
  - Missing parameters
  - Wrong type
  - Invalid values
  - Entity not found

- **`\RuntimeException`** - For execution errors
  - Service not available
  - State corruption
  - Job queue errors

## Testing Considerations

### Unit Test Template

```php
namespace LabkiPackManager\Tests\Handlers;

use PHPUnit\Framework\TestCase;
use LabkiPackManager\Handlers\Packs\SelectPackHandler;
use LabkiPackManager\Domain\PackSessionState;
use LabkiPackManager\Domain\RepositoryId;
use LabkiPackManager\Domain\ContentRefId;

class SelectPackHandlerTest extends TestCase {
    public function testSelectPackSuccessfully() {
        $handler = new SelectPackHandler();
        
        $manifest = [
            'packs' => [
                'pack1' => [ 'depends_on' => [] ],
            ]
        ];
        
        $state = new PackSessionState(
            new ContentRefId( 1 ),
            1,  // user_id
            [
                'pack1' => [
                    'name' => 'pack1',
                    'selected' => false,
                    'pages' => []
                ]
            ]
        );
        
        $result = $handler->handle( $state, $manifest, 
            [ 'pack_name' => 'pack1' ],
            [ 'user_id' => 1 ]
        );
        
        $this->assertArrayHasKey( 'state', $result );
        $this->assertTrue( $result['state']->getPack( 'pack1' )['selected'] );
    }
    
    public function testRejectsInvalidPackName() {
        $handler = new SelectPackHandler();
        
        // ... setup ...
        
        $this->expectException( \InvalidArgumentException::class );
        $handler->handle( $state, $manifest, [], [ 'user_id' => 1 ] );
    }
}
```

## Performance Notes

### Strengths

1. **Lazy loading**: Handlers only load what they need
2. **Diff computation**: Only changed fields sent to frontend
3. **No redundant queries**: Services called once per handler
4. **Async processing**: Apply command uses background jobs

### Optimization Opportunities

1. **Cache manifest** in session to avoid repeated lookups
2. **Cache resolved dependencies** to avoid repeated calculations
3. **Batch operations** in background job handler
4. **Index database queries** on (user_id, ref_id) for state store

## Troubleshooting

### Handler throws but error message doesn't appear

Check that ApiLabkiPacksAction has error handling:
```php
try {
    $result = $handler->handle( ... );
} catch ( \InvalidArgumentException $e ) {
    $this->dieWithError( $e->getMessage(), 'invalid_argument' );
}
```

### Diff appears empty when it shouldn't

Ensure ApplyHandler is not returning `'save' => false` for other handlers.
Most handlers should return `'save' => true` (default).

### State not persisting

Check that handler returns `'save' => true` (or omits the key).

### Operation info not in response

Verify ApplyHandler returns `'operation_info'` key and ApiLabkiPacksAction includes it.

## Code Quality

All handlers:
- ✅ Use `declare(strict_types=1)`
- ✅ Have comprehensive docblocks
- ✅ Validate all inputs
- ✅ Throw appropriate exceptions
- ✅ Extend BasePackHandler
- ✅ Return consistent result format
- ✅ Follow PSR-12 coding standards
