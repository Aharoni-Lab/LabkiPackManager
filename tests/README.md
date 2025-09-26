## LabkiPackManager Testing Guide

### Overview

This project runs PHPUnit tests inside a real MediaWiki environment using MediaWiki’s test runner. Unit tests that touch MediaWiki services are implemented as integration tests.

### What you can test now

- Unit tests for pure PHP classes (e.g., YAML parsing, fetch logic)
- Behavior that depends on minimal MediaWiki services (config, HTTP, cache, status) using our built-in stubs

Future work can add real MediaWiki integration tests that run inside a MediaWiki checkout (documented below).

## Directory structure

- `tests/phpunit/`:
  - `unit/`: Pure PHP unit tests (no MW services)
  - `integration/`: MediaWiki integration tests (extend `MediaWikiIntegrationTestCase`)
- `tests/phpunit/unit/ManifestParserTest.php`: Parser unit tests
- (Optional) `tests/fixtures/`: Sample YAML or JSON files for tests

## Integration tests and services

Integration tests can inject fakes or mocks through constructors or by overriding services via MediaWiki’s testing utilities. Prefer constructor injection for HTTP clients.

## PHPUnit configuration

- MediaWiki provides the bootstrap; use MediaWiki’s entrypoint to run tests.

## Running tests

### Running tests inside MediaWiki

From the MediaWiki root, with this extension under `extensions/LabkiPackManager`:
```bash
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/
```

### Running a single test
```bash
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration/ManifestFetcherTest.php
```

## Writing tests

### Unit tests

- Place pure PHP tests under `tests/phpunit/unit/`
- Extend `PHPUnit\Framework\TestCase`

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

## MediaWiki integration tests

Extend `MediaWikiIntegrationTestCase` and run via the entrypoint above.

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


