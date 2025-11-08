#!/usr/bin/env bash
set -euo pipefail

#
# LabkiPackManager — MediaWiki test environment setup script (SQLite)
# Sets up MediaWiki with working log mount, dedicated jobrunner, and verified debug log output.
# Uses platform-appropriate cache directories (macOS/Windows/Linux).
#

# --- Determine platform-appropriate cache directory ---
get_cache_dir() {
    case "$(uname -s)" in
        Darwin*)
            # macOS: ~/Library/Caches/labki
            echo "$HOME/Library/Caches/labki"
            ;;
        MINGW*|MSYS*|CYGWIN*)
            # Windows (Git Bash/MSYS)
            local appdata="${LOCALAPPDATA:-$HOME/AppData/Local}"
            echo "$appdata/labki"
            ;;
        *)
            # Linux/Unix: ~/.cache/labki (XDG Base Directory spec)
            echo "${XDG_CACHE_HOME:-$HOME/.cache}/labki"
            ;;
    esac
}

# --- CONFIG ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE_BASE="$(get_cache_dir)"
MW_DIR="${MW_DIR:-$CACHE_BASE/mediawiki-test}"
EXT_DIR="${EXT_DIR:-$SCRIPT_DIR}"
MW_BRANCH=REL1_44
MW_PORT=8080
MW_ADMIN_USER=Admin
MW_ADMIN_PASS=dockerpass
LOG_DIR="$EXT_DIR/logs"
CONTAINER_LOG_PATH="/var/log/labkipack"
CONTAINER_LOG_FILE="$CONTAINER_LOG_PATH/labkipack.log"
CONTAINER_WIKI_PATH="/var/www/html/w"
# ---------------

echo "==> Using test environment directory: $MW_DIR"

echo "==> Stopping and removing old MediaWiki containers/volumes..."
if [ -d "$MW_DIR" ]; then
  cd "$MW_DIR"
  docker compose down -v || true
fi

echo "==> Ensuring MediaWiki core is present..."
if [ ! -d "$MW_DIR/.git" ]; then
  mkdir -p "$(dirname "$MW_DIR")"
  git clone https://gerrit.wikimedia.org/r/mediawiki/core.git "$MW_DIR"
fi

cd "$MW_DIR"
git fetch --all
git checkout "$MW_BRANCH"
git pull --ff-only

echo "==> Resetting MediaWiki core (wipe untracked files)..."
git reset --hard "$MW_BRANCH"
git clean -fdx
git submodule update --init --recursive || true

echo "==> Writing fresh .env..."
cat > "$MW_DIR/.env" <<EOF
MW_SCRIPT_PATH=/w
MW_SERVER=http://localhost:$MW_PORT
MW_DOCKER_PORT=$MW_PORT
MEDIAWIKI_USER=$MW_ADMIN_USER
MEDIAWIKI_PASSWORD=$MW_ADMIN_PASS
XDEBUG_CONFIG=
XDEBUG_ENABLE=true
XHPROF_ENABLE=true
MW_DOCKER_UID=$(id -u)
MW_DOCKER_GID=$(id -g)
EOF

echo "==> Starting MediaWiki containers..."
docker compose up -d
docker compose ps

echo "==> Installing dependencies..."
docker compose exec -T mediawiki composer update --no-interaction --no-progress

echo "==> Running provided docker/install.sh..."
docker compose exec -T mediawiki /bin/bash /docker/install.sh

echo "==> Fixing SQLite permissions..."
docker compose exec -T mediawiki bash -c "
  chmod -R o+rwx /var/www/html/w/cache/sqlite
"

# --- Log setup ---
echo "==> Preparing host log directory..."
mkdir -p "$LOG_DIR"
chmod 777 "$LOG_DIR"

echo "==> Setting up docker-compose.override.yml for extension + logs + jobrunner..."
cat > "$MW_DIR/docker-compose.override.yml" <<EOF
services:
  mediawiki:
    user: "$(id -u):$(id -g)"
    volumes:
      - $EXT_DIR:/var/www/html/w/extensions/LabkiPackManager:cached
      - $LOG_DIR:$CONTAINER_LOG_PATH

  mediawiki-jobrunner:
    # Don't override image - use base docker-compose.yml's jobrunner image
    user: "$(id -u):$(id -g)"
    # Restrict to only LabkiPackManager jobs
    command: ["php", "maintenance/runJobs.php", "--wait", "--type=labkiRepoAdd", "--type=labkiRepoSync", "--type=labkiRepoRemove", "--type=labkiPackApply"]
    volumes:
      - $EXT_DIR:/var/www/html/w/extensions/LabkiPackManager:cached
      - $LOG_DIR:$CONTAINER_LOG_PATH
EOF

echo "==> Restarting MediaWiki with extension + logs mount..."
docker compose down
docker compose up -d

echo "==> Installing Mermaid..."
docker compose exec -T mediawiki bash -lc '
  cd /var/www/html/w
  cat > composer.local.json <<JSON
{
  "require": {
    "mediawiki/mermaid": "~6.0.1"
  }
}
JSON
  composer update --no-interaction --no-progress
'

echo "==> Ensuring debug log directory inside container..."
docker compose exec -T mediawiki bash -lc "
  mkdir -p $CONTAINER_LOG_PATH
  chmod 777 $CONTAINER_LOG_PATH
"

echo "==> Enabling LabkiPackManager + Mermaid + DBViewer + Debug log..."
docker compose exec -T mediawiki bash -lc "
  sed -i -E \"/wfLoadExtension\\((\\\"|\\\\x27)(LabkiPackManager|Mermaid)(\\\"|\\\\x27)\\)/d\" $CONTAINER_WIKI_PATH/LocalSettings.php

  {
    echo 'wfLoadExtension( \"LabkiPackManager\" );'
    echo 'wfLoadExtension( \"Mermaid\" );'
    echo '\$wgLabkiEnableDBViewer = true;'
    echo '\$wgLabkiShowImportFooter = true;'
    echo '\$wgDebugLogGroups[\"labkipack\"] = \"$CONTAINER_LOG_FILE\";'
    echo ''
    echo '// Set cache directory to shared volume (accessible to jobrunner)'
    echo '\$wgCacheDirectory = \"\$IP/cache\";'
  } >> $CONTAINER_WIKI_PATH/LocalSettings.php
"

echo "==> Configuring job queue for LabkiPackManager..."
docker compose exec -T mediawiki bash -lc "
  {
    echo ''
    echo '// === Job Queue Configuration ==='
    echo '// Disable job execution on web requests - jobs only run via maintenance/run.php (jobrunner)'
    echo '\$wgJobRunRate = 0;'
    echo ''
    echo '// Optional: Enable job queue logging for debugging'
    echo '\$wgDebugLogGroups[\"jobqueue\"] = \"$CONTAINER_LOG_PATH/jobqueue.log\";'
    echo '\$wgDebugLogGroups[\"runJobs\"] = \"$CONTAINER_LOG_PATH/runJobs.log\";'
  } >> $CONTAINER_WIKI_PATH/LocalSettings.php
"

echo "==> Running updater..."
docker compose exec -T mediawiki php maintenance/update.php --quick

echo "==> Migrating labki repos to shared cache directory..."
docker compose exec -T mediawiki bash -lc "
  # Move any existing repos from /tmp to shared cache
  if [ -d /tmp/my_wiki/labki-content-repos ]; then
    echo 'Found repos in /tmp, moving to shared cache...'
    mkdir -p $CONTAINER_WIKI_PATH/cache/labki-content-repos
    cp -r /tmp/my_wiki/labki-content-repos/* $CONTAINER_WIKI_PATH/cache/labki-content-repos/ 2>/dev/null || true
    rm -rf /tmp/my_wiki/labki-content-repos
    echo 'Migration complete'
  else
    echo 'No repos to migrate'
  fi
"

echo "==> Sanity check..."
docker compose exec -T mediawiki tail -n 5 $CONTAINER_WIKI_PATH/LocalSettings.php
docker compose exec -T mediawiki ls -l $CONTAINER_LOG_PATH

# --- Verify logging ---
echo "==> Verifying MediaWiki debug log output..."
docker compose exec -T mediawiki bash -lc "chmod -R 777 $CONTAINER_LOG_PATH"
docker compose exec -T mediawiki php -r "
define('MW_INSTALL_PATH', '/var/www/html/w');
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once MW_INSTALL_PATH . '/includes/WebStart.php';
wfDebugLog('labkipack', 'Reset complete – test entry at ' . date('H:i:s'));
echo \"OK\n\";"
docker compose exec -T mediawiki tail -n 5 $CONTAINER_LOG_FILE || true

echo
echo "==> Restarting jobrunner to ensure it picks up configuration..."
docker compose restart mediawiki-jobrunner
sleep 3

echo
echo "==> Verifying jobrunner is running..."
docker compose ps mediawiki-jobrunner
docker compose top mediawiki-jobrunner | grep runJobs || echo "Warning: runJobs process not found"

echo
echo "==> Verifying jobrunner can access repos..."
if docker compose exec -T mediawiki-jobrunner test -d /var/www/html/w/cache/labki-content-repos 2>/dev/null; then
  echo "✓ Jobrunner can access labki-content-repos"
  docker compose exec -T mediawiki-jobrunner ls -la /var/www/html/w/cache/labki-content-repos/ 2>/dev/null || true
else
  echo "✓ No repos yet (will be created on first use)"
fi

echo
echo "==> Checking recent jobrunner activity..."
docker compose logs --tail 10 mediawiki-jobrunner

echo
echo "==> All done!"
echo "Visit: http://localhost:$MW_PORT/w"
echo "Logs:  $LOG_DIR/labkipack.log"
echo "Monitor jobrunner: docker compose logs -f mediawiki-jobrunner"
echo
echo "Note: Git repos are now stored in cache/labki-content-repos/ (shared with jobrunner)"
