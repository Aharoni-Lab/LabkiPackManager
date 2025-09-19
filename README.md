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

3. Configure the content repository URLs (raw file host):

```php
$wgLabkiContentManifestURL = 'https://raw.githubusercontent.com/YourOrg/labki-content/main/manifest.yml';
$wgLabkiContentBaseURL = 'https://raw.githubusercontent.com/YourOrg/labki-content/main/';
```

4. Ensure your admin role (`sysop`) has the `labkipackmanager-manage` right (default provided by the extension).

Usage
-----

- Visit `Special:LabkiPackManager` as an admin
- The extension will fetch a YAML `manifest.yml` at the repo root, parse available packs, and list them for selection (implementation in progress)
- Each selected pack corresponds to a folder under `packs/<id>/` with its own `manifest.yml` and a `pages/` directory containing `.wiki` files whose names are the page titles

Development
-----------

- Namespace: `LabkiPackManager\\`
- Special page: `Special:LabkiPackManager`
- Strings and aliases: `i18n/`

Run PHPUnit and PHPCS via MediaWiki’s composer setup in the MediaWiki root.

Labki content repo expectations
-------------------------------

- Root `manifest.yml` lists packs with `id`, `path`, `version`, `description`
- Each pack has `packs/<id>/manifest.yml` with `name`, `id`, `version`, `description`, `dependencies`, and `contents`
- Pages live under `packs/<id>/pages/` as `.wiki` files (e.g., `Template:Publication.wiki`, `Form:Publication.wiki`)

License
-------

GPL-2.0-or-later

