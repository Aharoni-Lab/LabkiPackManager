## LabkiPackManager Testing Guide

### Overview

This project uses PHPUnit to test the extension code without requiring a full MediaWiki environment. The tests run against lightweight MediaWiki "stubs" included in this repo so you can develop and run unit tests locally or in a container.

### What you can test now

- Unit tests for pure PHP classes (e.g., YAML parsing, fetch logic)
- Behavior that depends on minimal MediaWiki services (config, HTTP, cache, status) using our built-in stubs

Future work can add real MediaWiki integration tests that run inside a MediaWiki checkout (documented below).

## Directory structure

- `tests/bootstrap.php`: Test bootstrap. Loads Composer autoloader and registers a PSR-4 autoloader for our MediaWiki stubs.
- `tests/phpunit/`:
  - `unit/`: Unit tests (no real MW environment required)
  - Example: `tests/phpunit/unit/ManifestFetcherTest.php`
- `tests/phpunit/unit/ManifestParserTest.php`: Parser unit tests
- `tests/Support/MediaWiki/`: Lightweight MediaWiki service stubs used by unit tests
  - `MediaWikiServices.php`: A minimal service locator with config, HTTP, and cache
  - `Services/HttpRequestFactory.php`: Fake HTTP client returning controlled responses
  - `Status/StatusValue.php`: Minimal `StatusValue` with error message key support
  - `Cache/WANObjectCache.php`: Ephemeral in-memory cache
- (Optional) `tests/fixtures/`: Place sample YAML or JSON files for tests you write later

## How the stubs work

- `MediaWiki\MediaWikiServices::getInstance()` returns a test services singleton with:
  - `getMainConfig()`: Exposes `get( $key )` for reading config values you set via `setConfigForTests([ key => value ])`
  - `getHttpRequestFactory()`: Returns a stub HTTP factory. Use `setNextResponse($code, $body, $ok)` to control the next request
  - `getMainWANObjectCache()`: Returns an in-memory cache with `get/set/delete`
- `MediaWiki\Status\StatusValue` mimics the OK/fatal result pattern used by MediaWiki. In tests you can assert on `isOK()`, `getValue()`, and `getMessage()->getKey()`

These stubs are sufficient for testing classes like `LabkiPackManager\Services\ManifestFetcher` and anything that depends on simple config, HTTP, or cache behavior.

## PHPUnit configuration

- `phpunit.xml.dist` points bootstrap to `tests/bootstrap.php`
- The entire `tests/` folder is included as the testsuite

## Running tests

### Using Docker (recommended, consistent environment)

PowerShell (Windows):
```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install --prefer-dist --no-progress --no-interaction
docker run --rm -v "${PWD}:/app" -w /app composer:2 vendor/bin/phpunit -c phpunit.xml.dist
```

Bash (macOS/Linux):
```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 install --prefer-dist --no-progress --no-interaction
docker run --rm -v "$PWD:/app" -w /app composer:2 vendor/bin/phpunit -c phpunit.xml.dist
```

Notes:
- The first command installs Composer dependencies into `vendor/`
- The second command runs PHPUnit using the vendor binary

### Running locally (no Docker)

Make sure you have PHP (≥ 8.1) and Composer installed locally, then:
```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist
```

## Writing tests

### Unit tests

- Place tests under `tests/phpunit/unit/`
- Extend `PHPUnit\Framework\TestCase`
- If your code needs config or HTTP, prepare the stubs:
```php
use MediaWiki\MediaWikiServices;

MediaWikiServices::resetForTests();
$services = MediaWikiServices::getInstance();
$services->setConfigForTests([
    'LabkiContentSources' => [
        'Default' => [
            'manifestUrl' => 'http://example.test/manifest.yml'
        ]
    ],
]);
$services->getHttpRequestFactory()->setNextResponse(200, $yamlBody, true);
```
- Create the subject-under-test and assert on the results

Example assertions with `StatusValue`:
```php
$this->assertTrue($status->isOK());
$this->assertFalse($status->isOK());
$this->assertSame('labkipackmanager-error-parse', $status->getMessage()->getKey());
```

### Parser tests

- See `tests/Parser/ManifestParserTest.php` for patterns testing YAML parsing directly

### Using fixtures (optional)

- Create files under `tests/fixtures/`, e.g., `tests/fixtures/manifest.yml`
- Load them in tests:
```php
$yaml = file_get_contents(__DIR__ . '/../../fixtures/manifest.yml');
```

## Running a single test or test method

By file path:
```bash
vendor/bin/phpunit -c phpunit.xml.dist tests/phpunit/unit/ManifestFetcherTest.php
```

Using `--filter` (class or method name):
```bash
vendor/bin/phpunit -c phpunit.xml.dist --filter ManifestFetcherTest
vendor/bin/phpunit -c phpunit.xml.dist --filter testFetchRootManifest_Success
```

## Debugging tips

- Add temporary `var_dump()` or `fwrite(STDERR, ...)` in tests or classes while debugging
- If Docker output is garbled in PowerShell, run without piping to `cat`
- Ensure `composer install` ran successfully and `vendor/` exists
- If classes are not found, confirm `tests/bootstrap.php` is being used (phpunit.xml.dist) and paths are correct

## MediaWiki integration tests (future)

When you want to test against a real MediaWiki environment:
- Install this extension into a MediaWiki checkout under `extensions/LabkiPackManager`
- Use MediaWiki’s PHPUnit entrypoint from the MediaWiki root:
```bash
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/
```

In those tests, you can extend `MediaWiki\IntegrationTestCase` classes and use real services and DBs provided by MW’s test runner. Keep integration tests separate from the unit tests above.

## Updating dependencies and autoloaders

- Update dependencies: `composer update` (or `composer install` to respect lock file)
- Regenerate autoload: `composer dump-autoload -o`

## Common scenarios covered by current tests

- `ManifestParser`:
  - Parses valid YAML and normalizes pack entries
  - Throws exceptions on empty/invalid YAML or schema issues
- `ManifestFetcher` (via stubs):
  - Successful HTTP fetch + parse returns `StatusValue::newGood($packs)`
  - HTTP failure, non-200 status, empty body → `labkipackmanager-error-fetch`
  - YAML parse failure → `labkipackmanager-error-parse`
  - Schema failure (e.g., missing `packs`) → `labkipackmanager-error-schema`

## FAQ

- Why not require a running MediaWiki? 
  - Unit tests are faster and simpler. The stubs simulate only what we need.
- Can I still add integration tests? 
  - Yes, add them later under `tests/phpunit/integration` and run via MediaWiki’s test entrypoint.
- Windows path tips?
  - In PowerShell, prefer `${PWD}` for bind mounts, and avoid `| cat` pipes when using Docker.


