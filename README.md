LabkiPackManager
================

MediaWiki extension to import Labki content packs stored as `.wiki` page files from a Git repository.

- MediaWiki: 1.44+
- Content format: `.wiki` files (not XML)
- Deployment: Developed in this repo, cloned into your Docker-based MediaWiki platform

Installation
------------

1. Clone into MediaWiki `extensions/` (in Docker build or bind mount):

```bash
cd /var/www/html/extensions
git clone https://github.com/Aharoni-Lab/LabkiPackManager.git LabkiPackManager
```

2. Enable in `LocalSettings.php`:

```php
wfLoadExtension( 'LabkiPackManager' );
```

Quick setup/reset (Docker + SQLite)
-----------------------------------

If you are developing locally with MediaWiki-Docker, use the bundled script to install or fully reset a working test wiki in one shot (clone MW if needed, mount this extension, install Mermaid, enable both, run updater):

```bash
# from your host shell (WSL/macOS/Linux), not inside the container
cd ~/dev/LabkiPackManager
chmod +x reset_mw_test.sh
./reset_mw_test.sh
```

Then:

- Open `http://localhost:8080/w` to use the wiki
- Run unit tests:
  ```bash
  docker compose exec mediawiki bash -lc 'composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/unit'
  ```
- Run integration tests:
  ```bash
  docker compose exec mediawiki bash -lc 'composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration'
  ```

Notes:
- Do not run the script with sudo. If you see permission errors after running as root, fix ownership: `sudo chown -R $USER:$USER ~/dev/mediawiki`.
- The script mirrors the CI flow (SQLite), and is idempotent — safe to re-run when things get out of sync.

3. Configure content sources (raw file hosts):

```php
$wgLabkiContentSources = [
    'Lab Packs (Default)' => 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs/main/manifest.yml',
    // Add more sources as needed
    'Custom Packs Repo' => 'https://raw.githubusercontent.com/YourOrg/custom-packs/main/manifest.yml',
];
```

4. Ensure your admin role (`sysop`) has the `labkipackmanager-manage` right (default provided by the extension).

5. Install PHP dependencies (YAML parser):

```bash
cd extensions/LabkiPackManager
composer install --no-dev --prefer-dist --no-progress --no-interaction
```

Usage
-----

- Visit `Special:LabkiPackManager` as an admin
- The extension will fetch a YAML `manifest.yml` from the selected source, parse available packs, and list them for selection
- Each selected pack corresponds to a folder under `packs/<id>/` with its own `manifest.yml` and a `pages/` directory containing `.wiki` files whose names are the page titles

Mermaid graph requirement
-------------------------

This extension renders a small live dependency graph (Mermaid). To enable it:

1) Install and enable the MediaWiki Mermaid extension (recommended):

```php
wfLoadExtension( 'Mermaid' );
```

2) Alternatively (dev-only), the UI will lazy-load Mermaid from a CDN for the graph panel. For production wikis, prefer installing the Mermaid extension to avoid external requests and to align with CSP.

Configuration
-------------

Add or override options in LocalSettings.php:

```php
// Content sources (label => manifest URL)
$wgLabkiContentSources = [
    'Lab Packs (Default)' => 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs/main/manifest.yml',
    // ...
];

// Default branch/tag hint for sources that support branches
$wgLabkiDefaultBranch = 'main';

// Cache TTL (seconds) for fetched manifests
$wgLabkiCacheTTL = 300;

// Manifest schema index (for validation) and its cache TTL
$wgLabkiSchemaIndexUrl = 'https://raw.githubusercontent.com/Aharoni-Lab/labki-packs-tools/main/schema/index.json';
$wgLabkiSchemaCacheTTL = 300;

// Optional global prefix for collision avoidance during plan/rename
// If set, pages that would otherwise collide are renamed using this prefix
// Namespaced pages keep their namespace and get "Prefix/Subpage"; Main namespace uses
// a real namespace if the prefix matches one, otherwise "Prefix:Title"
$wgLabkiGlobalPrefix = '';
```

Notes:
- Namespaced content (Template:, Form:, Module:, etc.) keeps its namespace when applying global prefix (e.g., Template:PackX/Page).
- If you want all colliding pages moved into a dedicated namespace, create/register that namespace and set `$wgLabkiGlobalPrefix` to its canonical name.

Demo without MediaWiki
----------------------

You can preview the rendered list UI without running MediaWiki by using the demo script. It reads `tests/fixtures/manifest.yml`, parses it, and generates a static HTML file.

PowerShell (Windows):
```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 php scripts/demo-render-packs.php > demo.html
Start-Process demo.html
```

Bash (macOS/Linux):
```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 php scripts/demo-render-packs.php > demo.html
xdg-open demo.html || open demo.html
```

This uses `includes/Special/PackListRenderer.php` to render the same list layout the special page uses. The HTML is self-contained and safe to view locally.

Development
-----------

- Namespace: `LabkiPackManager\`
- Special page: `Special:LabkiPackManager`
- Strings and aliases: `i18n/`

Run PHPUnit and PHPCS via MediaWiki’s composer setup in the MediaWiki root.

Unit tests (no MediaWiki required)
----------------------------------

Local Composer:
```bash
cd extensions/LabkiPackManager
composer install --no-dev --prefer-dist --no-progress --no-interaction
composer install --dev --no-progress --no-interaction
composer test
```

Docker (Composer image):
```powershell
# Install deps (including dev)
docker run --rm -v "C:\Users\dbaha\Documents\Projects\LabkiPackManager:/app" -w /app composer:2 install --prefer-dist --no-progress --no-interaction

# Run tests
docker run --rm -v "C:\Users\dbaha\Documents\Projects\LabkiPackManager:/app" -w /app composer:2 vendor/bin/phpunit -c phpunit.xml.dist
```

Notes:
- In production (inside the MediaWiki image), install without dev deps: `composer install --no-dev ...`
- Dev-only packages (like phpunit) are only installed when you include `--dev` or omit `--no-dev`.

This runs PHPUnit against the pure PHP parser located at `includes/Parser/ManifestParser.php`.

Labki content repo expectations
-------------------------------

- Root `manifest.yml` lists packs with `id`, `path`, `version`, `description`
- Each pack has `packs/<id>/manifest.yml` with `name`, `id`, `version`, `description`, `dependencies`, and `contents`
- Pages live under `packs/<id>/pages/` as `.wiki` files (e.g., `Template:Publication.wiki`, `Form:Publication.wiki`)

License
-------

 EUPL-1.2

