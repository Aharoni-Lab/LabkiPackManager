## MediaWiki Extension Testing on Windows with WSL2, Ubuntu, and Docker

This guide shows an end-to-end setup to run PHPUnit tests for a MediaWiki extension on Windows using WSL2, Ubuntu, and Docker. All tests run inside a real MediaWiki environment. Keep your repositories inside the Linux filesystem in WSL (e.g., under `~/dev`) for best performance.

### A. Verify and install the base tools

1) Check WSL and Ubuntu (PowerShell):

```powershell
wsl --list --verbose
```

2) If Ubuntu is missing, install and set WSL2 (PowerShell):

```powershell
wsl --install -d Ubuntu-22.04
wsl --set-default-version 2
wsl --set-default Ubuntu-22.04
```

Launch the Ubuntu app once and create your Linux user.

3) Install Docker Desktop for Windows, then enable WSL2 integration:
- Open Docker Desktop → Settings → General → enable “Use the WSL 2 based engine”.
- Settings → Resources → WSL Integration → enable Ubuntu.

4) Open the Ubuntu app and install basic tools:

```bash
sudo apt update
sudo apt install -y git curl unzip vim
git config --global user.name "Your Name"
git config --global user.email "you@example.com"
```

5) Work inside the WSL path, not the Windows (C:) filesystem (performance):

```bash
mkdir -p ~/dev && cd ~/dev
```

### B. Quick path: use the reset script (recommended)

For a one-command setup/reset that mirrors CI (SQLite), run from your host shell (WSL):

```bash
cd ~/dev/LabkiPackManager
chmod +x reset_mw_test.sh
./reset_mw_test.sh
```

This will: clone/update MediaWiki, start containers, install MW (SQLite), mount this extension, install Mermaid, enable both, and run the updater. After it completes, open `http://localhost:8080/w`.

Notes:
- Do not run with sudo. If permissions get messed up, fix with `sudo chown -R $USER:$USER ~/dev/mediawiki`.
- If you prefer manual setup or need to debug, continue with the detailed steps below.

### C. Get MediaWiki core with the built-in Docker dev environment

1) Clone MediaWiki core inside WSL:

```bash
cd ~/dev
git clone https://gerrit.wikimedia.org/r/mediawiki/core.git mediawiki
cd mediawiki
# Optional: pick a stable branch
git checkout REL1_44
```

MediaWiki 1.44 runs on PHP 8.1 or newer.

2) Create `.env` in the `mediawiki/` root with these lines:

```env
MW_SCRIPT_PATH=/w
MW_SERVER=http://localhost:8080
MW_DOCKER_PORT=8080
MEDIAWIKI_USER=Admin
MEDIAWIKI_PASSWORD=dockerpass
XDEBUG_CONFIG=
XDEBUG_ENABLE=true
XHPROF_ENABLE=true
MW_DOCKER_UID=
MW_DOCKER_GID=
```

On Windows, leave UID and GID blank.

This can be done using (ctrl + O then enter to save. ctrl + x to exti)

```bash
nano .env
```

3) Start the environment and install MediaWiki (from PowerShell or Ubuntu, in the `mediawiki/` directory):

```bash
docker compose up -d
docker compose exec mediawiki composer update
docker compose exec mediawiki /bin/bash /docker/install.sh
docker compose exec mediawiki chmod -R o+rwx cache/sqlite    # Windows fix for SQLite
```

If Docker fails to start or connect, open Docker Desktop → Settings → Resources → WSL Integration, and enable your Ubuntu distro. Ensure WSL is version 2. You do not need to run Ubuntu as Windows admin.

Open `http://localhost:8080` to confirm the wiki is live.

### D. Attach your extension without polluting core

We will want our extension's cloned repo to live in WSL just like the MW instance. We can clone into something like ~/dev/LabkiPackManager. We will need the WSL plugin in VS Code.

Keep your extension in its own repo outside core, for example:

```
~/dev/YourExtension
```
You can use `git` normally inside WSL. If you prefer GitHub CLI (`gh`), install and auth it in WSL.

Mount your extension into the container with an override file. Create `mediawiki/docker-compose.override.yml`:

```yaml
services:
  mediawiki:
    volumes:
      - /home/your-linux-user/dev/YourExtension:/var/www/html/w/extensions/YourExtension:cached
```

Apply the override:

```bash
docker compose down
docker compose up -d
```

Enable the extension in `mediawiki/LocalSettings.php`:

```php
wfLoadExtension( 'YourExtension' );
```

### Optional: Install Mermaid (for the dependency graph) via composer.local.json

Inside the MediaWiki container, from the MediaWiki root (`/var/www/html/w`):

```bash
cd /var/www/html/w
cat > composer.local.json <<'JSON'
{
  "require": {
    "mediawiki/mermaid": "~6.0.1"
  }
}
JSON

composer update --no-interaction --no-progress
```

Then enable Mermaid in `LocalSettings.php`:

```php
wfLoadExtension( 'Mermaid' );
```

Notes:
- Using REL1_44: `~6.0.1` is compatible. Alternatively, you can git-clone `extensions/Mermaid` and `git checkout REL1_44`.
- If you prefer not to install Mermaid in development, the UI can fall back to a CDN-loaded Mermaid, but production should install the extension for CSP and stability.

### E. Structure tests in your extension

Use the MediaWiki test harness. Place tests under `tests/phpunit/` in your extension.

Suggested layout:

```
YourExtension/
  extension.json
  includes/
  tests/
    phpunit/
      unit/
      integration/
```

- Use `MediaWikiUnitTestCase` for fast, isolated tests.
- Use `MediaWikiIntegrationTestCase` when you need services or a database.

### F. Run tests through MediaWiki’s composer entrypoints

Open a shell inside the MediaWiki container:

```bash
docker compose exec mediawiki bash
```

Run unit tests for your extension:

```bash
git config --global --add safe.directory /var/www/html/w   # fix "dubious ownership" if shown
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/unit
```

Run integration tests:

```bash
PHPUNIT_WIKI=wiki composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration
```

Run all extension tests (unit + integration) using MediaWiki core’s test suite:

```bash
composer phpunit:entrypoint -- --testsuite extensions --filter LabkiPackManager
```

Notes:
- If you see "MediaWikiIntegrationTestCase not found", run via the core suite command above (don’t pass the extension phpunit.xml for integration tests).
- Always invoke through `composer phpunit:entrypoint`; avoid calling `vendor/bin/phpunit` directly.

Run one test file or directory by passing a path at the end. Always use these entrypoints; do not invoke PHPUnit directly.

If you hit runner issues, ensure you are inside the MediaWiki container and using the composer scripts shown above.

### G. Quick database resets and fixes

Run the MediaWiki database updater (after enabling the extension or when schema changes):

```bash
docker compose exec mediawiki php maintenance/update.php --quick
```

After it completes, visit Special:Version to confirm the extension is active and that the tables were created.

For Windows “No database connection” errors with SQLite, set write perms as above. To reinstall from scratch:

```bash
rm -rf cache/sqlite
mv LocalSettings.php LocalSettings.php.bak
docker compose exec mediawiki /bin/bash /docker/install.sh
```

### H. Optional MariaDB, closer to production

Use SQLite for speed during development. For MySQL parity, add a MariaDB service in `docker-compose.override.yml` and point the installer at it. MediaWiki-Docker provides recipes for alternative databases and overrides.

### I. Coding style and coverage

Run CodeSniffer from core to enforce MediaWiki rules:

```bash
docker compose exec mediawiki composer phpcs
```

For PHPUnit HTML coverage, add `--coverage-html` to the test run.

### J. Continuous integration, GitHub Actions (mirrors local flow)

Keep CI close to your local flow. This example runs on pull requests and pushes to `main`, against REL1_44 on PHP 8.2 with SQLite, and cancels superseded runs.

```yaml
name: tests

on:
  pull_request:
    branches: ['**']
  push:
    branches: [ main ]

concurrency:
  group: phpunit-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  phpunit:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        mw_branch: [REL1_44]
        php: ['8.2']
    steps:
      - uses: actions/checkout@v4
        with:
          path: extension

      - name: Get MediaWiki core
        run: |
          git clone https://gerrit.wikimedia.org/r/mediawiki/core.git mediawiki
          cd mediawiki
          git checkout ${{ matrix.mw_branch }}

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, intl, xml, json, sqlite3
          tools: composer

      - name: Install MW deps
        working-directory: mediawiki
        run: composer update --no-interaction

      - name: Install MW with SQLite
        working-directory: mediawiki
        run: |
          mkdir -p cache/sqlite
          php maintenance/install.php \
            --dbtype sqlite --dbpath $(pwd)/cache/sqlite \
            --server "http://example.local" --scriptpath "/w" \
            --lang en --pass StrongDockerPass123! Wiki Admin

      - name: Enable extension
        run: |
          echo "wfLoadExtension( 'YourExtension' );" >> mediawiki/LocalSettings.php
          ln -s "$GITHUB_WORKSPACE/extension" mediawiki/extensions/YourExtension

      - name: PHPUnit unit
        working-directory: mediawiki
        run: composer phpunit:entrypoint -- extensions/YourExtension/tests/phpunit/unit

      - name: PHPUnit integration
        working-directory: mediawiki
        env:
          PHPUNIT_WIKI: wiki
        run: composer phpunit:entrypoint -- extensions/YourExtension/tests/phpunit/integration
```

### K. What goes where

In your extension repo:
- All extension code.
- `tests/phpunit/` with `unit` and `integration`.
- Optional `.phpcs.xml` and a short `README-DEV.md` describing how to run tests through MediaWiki.

Only on your machine (not committed):
- The `mediawiki/` clone with `.env`, `docker-compose.override.yml`, `LocalSettings.php`, and the SQLite files.
- Your WSL and Docker Desktop settings.

In CI:
- A workflow that checks out MediaWiki core, links your extension into `extensions/`, enables it in `LocalSettings.php`, then runs the same composer test entrypoints.

### Daily use, short recap

Start containers:

```bash
cd ~/dev/mediawiki
docker compose up -d
```

Open a shell in the container:

```bash
docker compose exec mediawiki bash
```

Run unit tests:

```bash
composer phpunit:entrypoint -- extensions/YourExtension/tests/phpunit/unit
```

Run integration tests:

```bash
PHPUNIT_WIKI=wiki composer phpunit:entrypoint -- extensions/YourExtension/tests/phpunit/integration
```

### K. Troubleshooting and common pitfalls

- Docker/WSL integration:
  - In Docker Desktop, enable WSL integration for your Ubuntu distro. Restart Docker after changing.
  - Verify WSL version: `wsl --list --verbose` should show version 2.
- File permissions with SQLite:
  - If MediaWiki reports DB errors, ensure `cache/sqlite` exists and is writable: `chmod -R o+rwx cache/sqlite` inside the container.
- Path mapping:
  - In `docker-compose.override.yml`, use your WSL Linux path (e.g., `/home/<user>/dev/...`), not a Windows path like `C:\...`.
- CRLF vs LF line endings:
  - Configure Git to use LF in WSL: `git config --global core.autocrlf input`.
- Composer/network issues:
  - Run Composer inside the container (`docker compose exec mediawiki composer update`). If you see rate limits, set `COMPOSER_AUTH` or use GitHub OAuth tokens.
- Test runner not found:
  - Ensure you run tests via `composer phpunit:entrypoint` from MediaWiki root, not directly with `vendor/bin/phpunit`.

If you run into other issues, capture the exact error and the command you ran. That context makes troubleshooting much faster.

