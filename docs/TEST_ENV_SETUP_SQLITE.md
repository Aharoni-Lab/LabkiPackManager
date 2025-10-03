## MediaWiki Test Environment Setup (SQLite) — Clean and Repeatable

This guide sets up a reliable MediaWiki test environment for running unit and integration tests of `LabkiPackManager` using SQLite and the MediaWiki Docker dev stack. It is based on the existing Windows/WSL2 guide but focuses solely on the MediaWiki test environment and testing flow.

Works on: Windows (WSL2 + Docker Desktop), Linux, macOS.

### 0) Start from scratch (safe reset)

If your environment got out of sync (missing tables, DB errors), reset cleanly.

From your MediaWiki root on the host (e.g., `~/dev/mediawiki`):

```bash
docker compose down
docker compose up -d
docker compose ps
# If you see no mediawiki service listed, ensure you're in the MediaWiki repo directory
# that contains docker-compose.yml (e.g., ~/dev/mediawiki).
```

Then open a shell in the MediaWiki container and reset DB files/LocalSettings as needed:

```bash
docker compose exec mediawiki bash

# inside container at /var/www/html/w
cd /var/www/html/w
git config --global --add safe.directory /var/www/html/w

# Move LocalSettings aside so install can run fresh
mv LocalSettings.php LocalSettings.php.bak 2>/dev/null || true

# Reset SQLite DB files/dir (dev-only)
rm -rf cache/sqlite
mkdir -p cache/sqlite && chmod -R o+rwx cache/sqlite

# Reinstall MAIN wiki (my_wiki) via run.php
php maintenance/run.php install \
  --dbtype sqlite --dbpath $(pwd)/cache/sqlite --dbname my_wiki \
  --server "http://localhost:8080" --scriptpath "/w" \
  --lang en --pass dockerpass "My Wiki" Admin

# Verify DB file exists and a core table is present
ls -l cache/sqlite/my_wiki.sqlite
php maintenance/run.php sql --wiki my_wiki --query="SELECT name FROM sqlite_master WHERE type='table' AND name='site_stats';"
# Expected:
# - The ls command shows a file like: -rw-r--r-- ... cache/sqlite/my_wiki.sqlite (size > 0)
# - The SQL query outputs a row containing 'site_stats'. If it prints nothing or
#   says "0 row(s) affected", the install did not complete — repeat the install step.

# Ensure the extension is enabled
grep -q "wfLoadExtension( 'LabkiPackManager' )" LocalSettings.php || \
  echo "wfLoadExtension( 'LabkiPackManager' );" >> LocalSettings.php

# Create core + extension tables for MAIN wiki
php maintenance/run.php update --quick --wiki my_wiki

# Verify the extension schema is present
php maintenance/run.php sql --wiki my_wiki --query="SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'labki_%';"
# Expected: rows for labki_pack_registry, labki_pack_pages, labki_page_mapping
# You can also confirm via the browser: http://localhost:8080/wiki/Special:Version
# Expected: 'LabkiPackManager' appears in the installed extensions list.
```

You now have a clean, working main wiki at `http://localhost:8080`.

### 1) Mount the extension in the container

Keep your extension repo outside core, e.g., `~/dev/LabkiPackManager`.

In your MediaWiki root (host), create `docker-compose.override.yml` to mount the extension:

```yaml
services:
  mediawiki:
    volumes:
      - /home/<linux-user>/dev/LabkiPackManager:/var/www/html/w/extensions/LabkiPackManager:cached
```

Apply it:

```bash
docker compose down
docker compose up -d
docker compose ps
```

Confirm the extension directory is visible inside the container at `/var/www/html/w/extensions/LabkiPackManager`.

### 2) Ensure LocalSettings loads the extension

Inside the container (`docker compose exec mediawiki bash`):

```bash
cd /var/www/html/w
grep -q "wfLoadExtension( 'LabkiPackManager' )" LocalSettings.php || \
  echo "wfLoadExtension( 'LabkiPackManager' );" >> LocalSettings.php
```

Run the updater for the MAIN wiki (creates extension tables):

```bash
php maintenance/run.php update --quick --wiki my_wiki
```

### 3) Choose your integration test DB

Option A (simplest): Use the MAIN wiki for integration tests.

```bash
# Inside container
PHPUNIT_WIKI=my_wiki composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration
```

Option B: Create a separate TEST wiki DB named `wiki` (clone of MAIN), then point tests to it.

```bash
# Inside container
cd /var/www/html/w
cp -f cache/sqlite/my_wiki.sqlite cache/sqlite/wiki.sqlite
php maintenance/run.php update --quick --wiki wiki

# Verify Labki tables exist in TEST DB
php maintenance/run.php sql --wiki wiki --query="SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'labki_%';"
# expect: labki_pack_registry, labki_pack_pages, labki_page_mapping

# Run integration tests against TEST wiki
PHPUNIT_WIKI=wiki composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration
```

Notes:
- Always run maintenance scripts via `maintenance/run.php` on MediaWiki ≥ 1.40.
- Ensure `cache/sqlite` exists and is writable inside the container.

### 4) Run tests

From inside the container (`/var/www/html/w`):

Unit tests:

```bash
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/unit
```

Integration tests (pick one of the two, matching step 3):

```bash
PHPUNIT_WIKI=my_wiki composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration
# or
PHPUNIT_WIKI=wiki composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration
```

Single test file:

```bash
PHPUNIT_WIKI=my_wiki composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration/PackImporterTest.php
```

### 5) Web sanity check

Open `http://localhost:8080/wiki/Special:Version` to confirm `LabkiPackManager` is enabled.
Open `http://localhost:8080/wiki/Special:LabkiPackManager` to exercise the UI.

If you see “No database connection (localhost)”, you’re configured for MySQL in `LocalSettings.php` but have no DB server. For SQLite, ensure:

```php
$wgDBtype = 'sqlite';
$wgDBname = 'my_wiki';
// $wgSQLiteDataDir should point to a writable path; usually set by install.
```

### 6) Troubleshooting

- “Empty list of tables to clone” when running integration tests:
  - Ensure `PHPUNIT_WIKI` points to a real SQLite DB file that has the core tables.
  - If using `wiki`, make sure you copied `my_wiki.sqlite` to `wiki.sqlite` and ran the updater for `--wiki wiki`.

- “no such table: labki_pack_registry” / “no such column: pack_uid”:
  - Run `php maintenance/run.php update --quick --wiki <your_wiki>` after enabling the extension.
  - Verify tables with:
    ```bash
    php maintenance/run.php sql --wiki <your_wiki> --query="SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'labki_%';"
    ```

- SQL command hangs:
  - Use the exact quoting shown above; otherwise it waits for input.

- Web error “No database connection (localhost)”:
  - Your `LocalSettings.php` is pointing to MySQL on `localhost` inside the container.
  - Either switch to SQLite (as above) or configure a DB service and point `$wgDBserver` to that service name.

- Permissions for SQLite:
  - Inside container: `mkdir -p cache/sqlite && chmod -R o+rwx cache/sqlite`.

- Git “dubious ownership” errors:
  - Inside container: `git config --global --add safe.directory /var/www/html/w`.

### 7) Quick commands (cheat sheet)

```bash
# Enter the container
docker compose exec mediawiki bash

# Reset DBs (dev-only) and reinstall main wiki
rm -f cache/sqlite/my_wiki.sqlite cache/sqlite/wiki.sqlite
mkdir -p cache/sqlite && chmod -R o+rwx cache/sqlite
php maintenance/run.php install \
  --dbtype sqlite --dbpath $(pwd)/cache/sqlite --dbname my_wiki \
  --server "http://localhost:8080" --scriptpath "/w" \
  --lang en --pass dockerpass "My Wiki" Admin
echo "wfLoadExtension( 'LabkiPackManager' );" >> LocalSettings.php
php maintenance/run.php update --quick --wiki my_wiki

# Make a separate TEST DB (optional)
cp -f cache/sqlite/my_wiki.sqlite cache/sqlite/wiki.sqlite
php maintenance/run.php update --quick --wiki wiki

# Run tests
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/unit
PHPUNIT_WIKI=my_wiki composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration
```


