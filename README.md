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
$wgLabkiContentManifestURL = 'https://raw.githubusercontent.com/YourOrg/labki-content/main/manifest.json';
$wgLabkiContentBaseURL = 'https://raw.githubusercontent.com/YourOrg/labki-content/main/';
```

4. Ensure your admin role (`sysop`) has the `labki-import` right (default provided by the extension).

Usage
-----

- Visit `Special:LabkiPackManager` as an admin
- View available packs from the manifest and import selected packs (implementation in progress)

Development
-----------

- Namespace: `LabkiPackManager\\`
- Special page: `Special:LabkiPackManager`
- Strings and aliases: `i18n/`

Run PHPUnit and PHPCS via MediaWiki’s composer setup in the MediaWiki root.

License
-------

GPL-2.0-or-later

