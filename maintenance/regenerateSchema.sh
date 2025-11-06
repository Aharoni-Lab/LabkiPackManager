#!/bin/bash
# Regenerate database-specific SQL from abstract schema
#
# This script uses MediaWiki's generateSchemaSql.php to convert the abstract
# schema (sql/tables.json) into database-specific SQL files.
#
# Requirements:
#   - PHP XML extension (install with: sudo apt-get install php-xml)
#   - MediaWiki core installed in ../mediawiki/
#
# Usage:
#   ./maintenance/regenerateSchema.sh

set -e

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(dirname "$SCRIPT_DIR")"

# Determine cache directory (matches setup_mw_test_env.sh logic)
get_cache_dir() {
    case "$(uname -s)" in
        Darwin*)
            echo "$HOME/Library/Caches/labki"
            ;;
        MINGW*|MSYS*|CYGWIN*)
            local appdata="${LOCALAPPDATA:-$HOME/AppData/Local}"
            echo "$appdata/labki"
            ;;
        *)
            echo "${XDG_CACHE_HOME:-$HOME/.cache}/labki"
            ;;
    esac
}

CACHE_DIR="$(get_cache_dir)"
TEST_MW_DIR="$CACHE_DIR/mediawiki-test"

# Try vendor/mediawiki/core first (local, no Docker needed), then ../mediawiki, then test environment
if [ -f "$EXT_DIR/vendor/mediawiki/core/maintenance/run.php" ]; then
    MW_DIR="$EXT_DIR/vendor/mediawiki/core"
    USE_DOCKER=false
    echo "Using vendor MediaWiki core: $MW_DIR"
    
    # Check if vendor MW has dependencies installed
    if [ ! -d "$MW_DIR/vendor/wikimedia" ]; then
        echo ""
        echo "Warning: MediaWiki core dependencies not installed."
        echo "Installing dependencies (this may take a moment)..."
        cd "$MW_DIR"
        composer install --quiet
        cd "$EXT_DIR"
        echo "✓ Dependencies installed"
        echo ""
    elif [ ! -d "$MW_DIR/vendor/seld/jsonlint" ]; then
        echo ""
        echo "Warning: Dev dependencies missing (needed for schema generation)."
        echo "Installing dev dependencies..."
        cd "$MW_DIR"
        composer install --quiet
        cd "$EXT_DIR"
        echo "✓ Dev dependencies installed"
        echo ""
    fi
elif [ -f "$EXT_DIR/../mediawiki/maintenance/run.php" ]; then
    MW_DIR="$EXT_DIR/../mediawiki"
    USE_DOCKER=false
    echo "Using adjacent MediaWiki core: $MW_DIR"
elif [ -f "$TEST_MW_DIR/maintenance/run.php" ]; then
    MW_DIR="$TEST_MW_DIR"
    USE_DOCKER=true
    echo "Using test MediaWiki instance: $MW_DIR"
else
    echo "Error: MediaWiki not found."
    echo ""
    echo "Please install MediaWiki in one of these locations:"
    echo "  - $EXT_DIR/vendor/mediawiki/core (via 'composer install')"
    echo "  - $EXT_DIR/../mediawiki (adjacent clone)"
    echo "  - $TEST_MW_DIR (test environment via setup_mw_test_env.sh)"
    echo ""
    echo "Or set MW_DIR environment variable to point to a MediaWiki installation."
    exit 1
fi

# Check for PHP XML extension
if ! php -m | grep -q "^xml$"; then
    echo "Warning: PHP XML extension not found"
    echo "This extension is required for MediaWiki's schema generation."
    echo ""
    read -p "Would you like to install it now? (requires sudo) [Y/n]: " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]] || [[ -z $REPLY ]]; then
        echo "Installing php-xml..."
        sudo apt-get install -y php-xml
        echo ""
        echo "✓ PHP XML extension installed"
        echo ""
    else
        echo "Skipping installation. The script will likely fail without it."
        echo "You can install it manually later with: sudo apt-get install php-xml"
        echo ""
    fi
fi

# Generate schemas
if [ "$USE_DOCKER" = true ]; then
    echo "Running schema generation inside Docker container..."
    cd "$MW_DIR"
    
    echo "Generating MySQL schema from sql/tables.json..."
    docker compose exec -T mediawiki php maintenance/run.php generateSchemaSql \
        --json=/var/www/html/w/extensions/LabkiPackManager/sql/tables.json \
        --sql=/var/www/html/w/extensions/LabkiPackManager/sql/mysql/tables-generated.sql \
        --type=mysql
    
    echo "Generating SQLite schema from sql/tables.json..."
    docker compose exec -T mediawiki php maintenance/run.php generateSchemaSql \
        --json=/var/www/html/w/extensions/LabkiPackManager/sql/tables.json \
        --sql=/var/www/html/w/extensions/LabkiPackManager/sql/sqlite/tables-generated.sql \
        --type=sqlite
else
    # Running directly on host
    echo "Generating MySQL schema from sql/tables.json..."
    php "$MW_DIR/maintenance/run.php" generateSchemaSql \
        --json="$EXT_DIR/sql/tables.json" \
        --sql="$EXT_DIR/sql/mysql/tables-generated.sql" \
        --type=mysql
    
    echo "Generating SQLite schema from sql/tables.json..."
    php "$MW_DIR/maintenance/run.php" generateSchemaSql \
        --json="$EXT_DIR/sql/tables.json" \
        --sql="$EXT_DIR/sql/sqlite/tables-generated.sql" \
        --type=sqlite
fi

echo ""
echo "✓ Schema files regenerated successfully!"
echo ""
echo "Generated files:"
echo "  - sql/mysql/tables-generated.sql"
echo "  - sql/sqlite/tables-generated.sql"
echo ""
echo "Don't forget to commit these files to version control."

