#!/usr/bin/env bash
set -euo pipefail

#
# LabkiPackManager — Quick MediaWiki debug log test
#

MW_DIR="$HOME/dev/mediawiki"
LOG_FILE="$HOME/dev/LabkiPackManager/logs/labkipack.log"
CONTAINER_WIKI_PATH="/var/www/html/w"
CONTAINER_LOG_FILE="/var/log/labkipack/labkipack.log"

echo "==> Running wfDebugLog() test inside MediaWiki container..."
docker compose -f "$MW_DIR/docker-compose.yml" exec -T mediawiki bash -lc "
php -r '\$_SERVER[\"REMOTE_ADDR\"] = \"127.0.0.1\"; define(\"MW_INSTALL_PATH\", \"$CONTAINER_WIKI_PATH\"); require_once MW_INSTALL_PATH . \"/includes/WebStart.php\"; wfDebugLog(\"labkipack\", \"CLI auto-test at \" . date(\"H:i:s\")); echo \"OK\\n\";'"

echo "==> Checking for new entries..."
if [ -f "$LOG_FILE" ]; then
  echo "Last 5 lines of $LOG_FILE:"
  tail -n 5 "$LOG_FILE"
else
  echo "⚠️  Log file not found at $LOG_FILE"
  echo "   Make sure LocalSettings.php defines \$wgDebugLogGroups['labkipack'] correctly."
fi
