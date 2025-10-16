#!/usr/bin/env bash
set -euo pipefail

#
# LabkiPackManager — MediaWiki test environment reset script (SQLite)
# with working log mount and verified debug log output
#

# --- CONFIG ---
MW_DIR="$HOME/dev/mediawiki"
EXT_DIR="$HOME/dev/LabkiPackManager"
MW_BRANCH=REL1_44
MW_PORT=8080
MW_ADMIN_USER=Admin
MW_ADMIN_PASS=dockerpass
LOG_DIR="$EXT_DIR/logs"
CONTAINER_LOG_PATH="/var/log/labkipack"
CONTAINER_LOG_FILE="$CONTAINER_LOG_PATH/labkipack.log"
CONTAINER_WIKI_PATH="/var/www/html/w"
# ---------------

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

echo "==> Setting up docker-compose.override.yml for extension + logs..."
cat > "$MW_DIR/docker-compose.override.yml" <<EOF
services:
  mediawiki:
    user: "$(id -u):$(id -g)"
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
    echo '\$wgDebugLogGroups[\"labkipack\"] = \"$CONTAINER_LOG_FILE\";'
  } >> $CONTAINER_WIKI_PATH/LocalSettings.php
"

echo "==> Running updater..."
docker compose exec -T mediawiki php maintenance/update.php --quick

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
echo \"OK\n\";
"
docker compose exec -T mediawiki tail -n 5 $CONTAINER_LOG_FILE || true


echo
echo "==> All done!"
echo "Visit: http://localhost:$MW_PORT/w"
echo "Logs:  $LOG_DIR/labkipack.log (auto-updating via Docker volume)"
