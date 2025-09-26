## MediaWiki Extension Testing on Windows with WSL2, Ubuntu, and Docker

This guide shows an end-to-end setup to run PHPUnit tests for a MediaWiki extension on Windows using WSL2, Ubuntu, and Docker. Keep your repositories inside the Linux filesystem in WSL (e.g., under `~/dev`) for best performance.

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

### B. Get MediaWiki core with the built-in Docker dev environment

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

If you have docker issues here, make sure to go into docker settings -> resources and enable wsl integration for ubuntu. Need to run ubuntu as windows admin too.

Open `http://localhost:8080` to confirm the wiki is live.

### C. Attach your extension without polluting core

We will want our extension's cloned repo to live in WSL just like the MW instance. We can clone into something like ~/dev/LabkiPackManager. We will need the WSL plugin in VS Code.

Keep your extension in its own repo outside core, for example:

```
~/dev/YourExtension
```
You will need to install gh and auth your account to be able to clone and work with the .git.

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

### D. Structure tests in your extension

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

### E. Run tests through MediaWiki’s composer entrypoints

Open a shell inside the MediaWiki container:

```bash
docker compose exec mediawiki bash
```

Run unit tests for your extension:

```bash
composer phpunit:unit -- extensions/YourExtension/tests/phpunit/unit
```

Run integration tests:

```bash
PHPUNIT_WIKI=wiki composer phpunit:entrypoint -- extensions/YourExtension/tests/phpunit/integration
```

Run one test file or directory by passing a path at the end. These entrypoints are the supported way to run tests.

If a few legacy tests require the old runner, use:

```bash
php tests/phpunit/phpunit.php -- tests/phpunit/some/legacy/path
```

### F. Quick database resets and fixes

For Windows “No database connection” errors with SQLite, set write perms as above. To reinstall from scratch:

```bash
rm -rf cache/sqlite
mv LocalSettings.php LocalSettings.php.bak
docker compose exec mediawiki /bin/bash /docker/install.sh
```

### G. Optional MariaDB, closer to production

Use SQLite for speed during development. For MySQL parity, add a MariaDB service in `docker-compose.override.yml` and point the installer at it. MediaWiki-Docker provides recipes for alternative databases and overrides.

### H. Coding style and coverage

Run CodeSniffer from core to enforce MediaWiki rules:

```bash
docker compose exec mediawiki composer phpcs
```

For PHPUnit HTML coverage, add `--coverage-html` to the test run.

### I. Continuous integration, minimal GitHub Actions

Keep CI close to your local flow. This matrix runs against two MW branches with SQLite:

```yaml
name: tests
on: [push, pull_request]

jobs:
  phpunit:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        mw_branch: [REL1_44, REL1_43]
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
            --lang en --pass pass Wiki Admin

      - name: Enable extension
        run: |
          echo "wfLoadExtension( 'YourExtension' );" >> mediawiki/LocalSettings.php
          ln -s $GITHUB_WORKSPACE/extension mediawiki/extensions/YourExtension

      - name: PHPUnit unit
        working-directory: mediawiki
        run: composer phpunit:unit -- extensions/YourExtension/tests/phpunit/unit

      - name: PHPUnit integration
        working-directory: mediawiki
        env:
          PHPUNIT_WIKI: wiki
        run: composer phpunit:entrypoint -- extensions/YourExtension/tests/phpunit/integration
```

### J. What goes where

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
composer phpunit:unit -- extensions/YourExtension/tests/phpunit/unit
```

Run integration tests:

```bash
PHPUNIT_WIKI=wiki composer phpunit:entrypoint -- extensions/YourExtension/tests/phpunit/integration
```

If you want, we can generate a ready-to-drop `docker-compose.override.yml` for your exact WSL path, plus a `README-DEV.md` tailored to your extension.

