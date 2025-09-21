## Scripts

### demo-render-packs.php

Render a static HTML page that mimics the `Special:LabkiPackManager` list without running MediaWiki.

- Input: `tests/fixtures/manifest.yml`
- Output: HTML to stdout (redirect to a file, e.g., `demo.html`)
- Uses: `includes/Special/PackListRenderer.php` and `includes/Parser/ManifestParser.php`

Run with Docker (recommended for consistent PHP/composer):

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

Run locally (requires PHP ≥ 8.1 and `composer install` done):
```bash
php scripts/demo-render-packs.php > demo.html
open demo.html  # or xdg-open on Linux, Start-Process on PowerShell
```

Notes:
- The script is read-only and does not depend on MediaWiki.
- To test different content, edit `tests/fixtures/manifest.yml`.


