#!/usr/bin/env bash
set -euo pipefail

#
# LabkiPackManager — MediaWiki test environment reset script (SQLite)
#
# Run from host (not inside container):
#   cd ~/dev/LabkiPackManager
#   chmod +x reset_mw_test.sh
#   ./reset_mw_test.sh
#
# DO NOT run with sudo.
# If you see permission errors, it means files got created by root (usually from running as sudo).
# To fix: sudo chown -R $USER:$USER ~/dev/mediawiki
#
# This script will:
#   - Stop/remove containers + volumes
#   - Ensure ~/dev/mediawiki exists
#   - Hard reset MediaWiki core to upstream (branch REL1_44)
#   - Nuke ALL untracked files with git clean -fdx
#   - Reinstall MediaWiki via /docker/install.sh (with SQLite)
#   - Mount LabkiPackManager extension (with logs directory)
#   - Install Mermaid
#   - Enable both extensions in LocalSettings.php
#   - Configure debug logs to write to ./extensions/LabkiPackManager/logs/labkipack.log
#

# --- CONFIG ---
MW_DIR="$HOME/dev/mediawiki"
EXT_DIR="$HOME/dev/LabkiPackManager"
MW_BRANCH=REL1_44
MW_PORT=8080
MW_ADMIN_USER=Admin
MW_ADMIN_PASS=dockerpass
LOG_DIR="$EXT_DIR/logs"
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

# --- New: log directory mount ---
echo "==> Preparing log directory..."
mkdir -p "$LOG_DIR"
chmod 777 "$LOG_DIR"

echo "==> Setting up docker-compose.override.yml for extension and logs..."
cat > "$MW_DIR/docker-compose.override.yml" <<EOF
services:
  mediawiki:
    volumes:
      - $EXT_DIR:/var/www/html/w/extensions/LabkiPackManager:cached
      - $LOG_DIR:/var/log/labkipack
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

echo "==> Creating debug log directory inside container..."
docker compose exec -T mediawiki bash -lc '
  mkdir -p /var/log/labkipack
  chmod 777 /var/log/labkipack
'

echo "==> Enabling LabkiPackManager + Mermaid + DBViewer + Debug log..."
docker compose exec -T mediawiki bash -lc '
  # Remove only prior LabkiPackManager/Mermaid lines (not all extensions)
  sed -i -E "/wfLoadExtension\((\"|\\x27)(LabkiPackManager|Mermaid)(\"|\\x27)\)/d" /var/www/html/w/LocalSettings.php

  {
    echo "wfLoadExtension( \"LabkiPackManager\" );"
    echo "wfLoadExtension( \"Mermaid\" );"
    echo "\$wgLabkiEnableDBViewer = true;"
    echo "\$wgDebugLogGroups[\"labkipack\"] = \"/var/log/labkipack/labkipack.log\";"
  } >> /var/www/html/w/LocalSettings.php
'


echo "==> Running updater..."
docker compose exec -T mediawiki php maintenance/update.php --quick

echo "==> Sanity check..."
docker compose exec -T mediawiki ls -l /var/www/html/w/LocalSettings.php
docker compose exec -T mediawiki ls -l /var/log/labkipack

echo "==> All done!"
echo "Visit: http://localhost:$MW_PORT/w"
echo "Logs:  $LOG_DIR/labkipack.log (auto-updating via Docker volume)"
