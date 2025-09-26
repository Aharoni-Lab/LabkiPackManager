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

GPL-2.0-or-later

