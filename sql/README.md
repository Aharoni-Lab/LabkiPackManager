# Database Schema Files

This directory contains the database schema for LabkiPackManager using MediaWiki's abstract schema format.

## 📁 Files

- **`tables.json`** - Abstract schema definition (source of truth, 401 lines)
- **`mysql/tables-generated.sql`** - Auto-generated MySQL/MariaDB schema (103 lines)
- **`sqlite/tables-generated.sql`** - Auto-generated SQLite schema (120 lines)

## 🔄 Complete Schema Workflow

### When to Regenerate

**You MUST regenerate when:**
- ✅ Adding/removing/modifying columns
- ✅ Adding/removing indexes
- ✅ Changing data types
- ✅ Adding/removing tables
- ✅ Modifying any part of `tables.json`

**You DON'T need to regenerate when:**
- ❌ Changing PHP code
- ❌ Updating documentation
- ❌ Modifying tests (unless testing schema changes)

### Step-by-Step Process

#### 1. Edit Abstract Schema
Edit `sql/tables.json` with your database changes.

**Example - Adding a column:**
```json
{
  "name": "new_column",
  "type": "string",
  "options": { "notnull": false, "length": 100 }
}
```

#### 2. Regenerate SQL Files
From extension root directory:
```bash
./maintenance/regenerateSchema.sh
```

**What this does:**
- ✅ Auto-detects MediaWiki installation (vendor/test env/adjacent)
- ✅ Prompts to install `php-xml` if needed (one-time setup)
- ✅ Auto-installs MediaWiki dependencies if needed
- ✅ Generates both MySQL and SQLite schemas from `tables.json`
- ✅ Validates output
- ✅ Shows success message with file paths

**First-time setup requirements:**
```bash
# Will be prompted automatically, or install manually:
sudo apt-get install php-xml
```

#### 3. Review Generated Changes
```bash
# See what SQL was generated
git diff sql/mysql/tables-generated.sql
git diff sql/sqlite/tables-generated.sql
```

Verify the generated SQL matches your intent!

#### 4. Commit All Three Files
```bash
git add sql/tables.json sql/mysql/tables-generated.sql sql/sqlite/tables-generated.sql
git commit -m "feat: add new_column to labki_pack table"
git push
```

**Never commit just `tables.json` alone!** CI will fail if the generated files are missing.

## 🤖 CI Enforcement

### Automated Schema Freshness Check

Our CI pipeline (`.github/workflows/ci.yml`) automatically:

1. **Regenerates** both MySQL and SQLite schemas from `tables.json`
2. **Compares** generated output with committed files
3. **Fails the build** if they differ

### What CI Failure Looks Like

```
❌ Error: Generated SQL schemas are out of date with tables.json
   Please run './maintenance/regenerateSchema.sh' and commit the changes.

   [Shows diff of what changed]
```

### How to Fix CI Failures

```bash
# Run regeneration locally
./maintenance/regenerateSchema.sh

# Commit the updated files
git add sql/mysql/ sql/sqlite/
git commit --amend --no-edit  # Or create new commit
git push --force-with-lease   # If amending
```

## 📊 Database Support

### Supported Databases
- ✅ **MySQL/MariaDB** - Recommended for production
- ✅ **SQLite** - Development and testing
- ⚠️ PostgreSQL - Can be added if needed (generate with `--type=postgres`)

### MediaWiki Compatibility
- ✅ MediaWiki 1.44+
- ✅ Follows official MediaWiki extension guidelines
- ✅ Compatible with standard MediaWiki installations

## 🏗️ Architecture Details

### Abstract Schema Format

The `tables.json` file uses MediaWiki's abstract schema format (JSON array of table definitions):

```json
[
  {
    "name": "labki_content_repo",
    "columns": [...],
    "indexes": [...],
    "pk": ["content_repo_id"]
  }
]
```

Each table definition includes:
- **columns** - Array of column definitions with types and options
- **indexes** - Array of index definitions (unique and non-unique)
- **pk** - Primary key column(s)

### Type Mappings

| Abstract Type | MySQL | SQLite | Notes |
|--------------|-------|--------|-------|
| `integer` | `INT` | `INTEGER` | Use `unsigned: true` for IDs |
| `string` | `VARCHAR(n)` | `VARCHAR(n)` | Requires `length` option |
| `blob` | `BLOB` | `BLOB` | For binary data |
| `float` | `FLOAT` | `REAL` | Floating point numbers |

**Common options:**
- `autoincrement: true` - Auto-incrementing primary key
- `unsigned: true` - Unsigned integers (MySQL only, ignored in SQLite)
- `notnull: true/false` - NULL or NOT NULL
- `default: value` - Default value
- `length: n` - Max length for strings/blobs

### MediaWiki Conventions

**No Foreign Keys:**
This extension follows MediaWiki convention of handling referential integrity in PHP code rather than database-level foreign key constraints. This ensures:
- Maximum database compatibility
- Standard MediaWiki extension pattern
- Explicit cascade control with logging

**Cascade deletion is implemented in:**
- `LabkiRepoRegistry::deleteRepo()` - Manually cascades to refs → packs → pages
- `LabkiRefRegistry::deleteRef()` - Manually cascades to packs → pages
- `LabkiPackRegistry::removePack()` - Manually cascades to pages and dependencies

## 🔧 Regeneration Script Details

### What `./maintenance/regenerateSchema.sh` Does

1. **Finds MediaWiki** (priority order):
   - `vendor/mediawiki/core` (local Composer dependency)
   - `../mediawiki` (adjacent clone)
   - `~/.cache/labki/mediawiki-test` (test environment)

2. **Checks Dependencies:**
   - PHP XML extension (offers to install if missing)
   - MediaWiki core dependencies (auto-installs if needed)

3. **Generates SQL:**
   - Runs MediaWiki's `generateSchemaSql.php` 
   - Creates MySQL schema with proper VARCHAR, INT types
   - Creates SQLite schema with proper INTEGER, VARCHAR types
   - Includes MediaWiki table/index prefix placeholders

4. **Validates Output:**
   - Ensures files were created
   - Shows file paths and success message

### Manual Generation (Advanced)

If you can't use the script, generate manually:

```bash
# From MediaWiki core directory
php maintenance/run.php generateSchemaSql \
  --json=/path/to/LabkiPackManager/sql/tables.json \
  --type=mysql \
  --sql=/path/to/LabkiPackManager/sql/mysql/tables-generated.sql

php maintenance/run.php generateSchemaSql \
  --json=/path/to/LabkiPackManager/sql/tables.json \
  --type=sqlite \
  --sql=/path/to/LabkiPackManager/sql/sqlite/tables-generated.sql
```

## 📝 Common Scenarios

### Adding a New Column

```bash
# 1. Add to tables.json
vim sql/tables.json
# Add column definition to appropriate table's "columns" array

# 2. Regenerate
./maintenance/regenerateSchema.sh

# 3. Review
git diff sql/mysql/tables-generated.sql
# Verify the ALTER TABLE or column addition looks correct

# 4. Commit
git add sql/
git commit -m "feat: add description column to labki_pack"
```

### Adding a New Index

```bash
# 1. Add to tables.json
vim sql/tables.json
# Add to the "indexes" array of the appropriate table

# 2. Regenerate
./maintenance/regenerateSchema.sh

# 3. Commit
git add sql/
git commit -m "perf: index labki_page.final_title for faster lookups"
```

### Adding a New Table

```bash
# 1. Add complete table definition to tables.json
vim sql/tables.json
# Add new object to the root array

# 2. Regenerate
./maintenance/regenerateSchema.sh

# 3. Update SchemaHooks.php to register the new table
vim includes/Hooks/SchemaHooks.php
# Add: $updater->addExtensionTable('new_table_name', $tablesFile);

# 4. Commit
git add sql/ includes/Hooks/SchemaHooks.php
git commit -m "feat: add labki_settings table"
```

### Schema Migration (Existing Installations)

For changes that need to migrate existing data:

```bash
# 1. Update tables.json and regenerate (as above)
./maintenance/regenerateSchema.sh

# 2. Create migration patch
cat > sql/mysql/patch-add-description.sql <<'SQL'
-- Add description column to labki_pack
ALTER TABLE /*_*/labki_pack 
  ADD COLUMN description VARCHAR(500) DEFAULT NULL;
SQL

# 3. Create SQLite version
cat > sql/sqlite/patch-add-description.sql <<'SQL'
-- Add description column to labki_pack
ALTER TABLE /*_*/labki_pack 
  ADD COLUMN description TEXT DEFAULT NULL;
SQL

# 4. Register in SchemaHooks.php
# $updater->addExtensionField(
#     'labki_pack',
#     'description',
#     "$dir/$dbDir/patch-add-description.sql"
# );
```

## 🐛 Troubleshooting

### "No such service: LabkiPackRegistry"

**Cause:** Service container not properly wired

**Solution:** Verify these files exist:
- `includes/ServiceWiring.php`
- `extension.json` has `ServiceWiringFiles: ["includes/ServiceWiring.php"]`

### "PHP XML extension not found"

**Cause:** Required PHP extension not installed

**Solution:** The script will prompt to install:
```bash
sudo apt-get install php-xml
```

Or install manually before running the script.

### "MediaWiki not found"

**Cause:** No MediaWiki installation detected

**Solution:** Install MediaWiki in one of these locations:
```bash
# Option 1: Use Composer (recommended)
composer install  # Installs to vendor/mediawiki/core

# Option 2: Setup test environment
./setup_mw_test_env.sh

# Option 3: Clone adjacent to extension
cd ~/dev
git clone https://gerrit.wikimedia.org/r/mediawiki/core.git mediawiki
```

### "Error Loading extension" during generation

**Cause:** Using Docker test environment but extension not mounted

**Solution:** Script automatically handles this - it detects Docker and uses container paths

### "Schema files out of date" in CI

**Cause:** Forgot to regenerate after editing `tables.json`

**Solution:**
```bash
./maintenance/regenerateSchema.sh
git add sql/mysql/ sql/sqlite/
git commit --amend --no-edit
git push --force-with-lease
```

### CI passes but schemas look wrong

**Cause:** You committed manually edited SQL instead of regenerated files

**Solution:** Always use the regeneration script:
```bash
# Discard manual edits
git checkout sql/mysql/tables-generated.sql sql/sqlite/tables-generated.sql

# Regenerate properly
./maintenance/regenerateSchema.sh

# Commit
git add sql/
git commit -m "fix: properly regenerate schemas"
```

## 🎯 Best Practices

1. **Never edit generated SQL directly** - Always edit `tables.json` and regenerate
2. **Always commit all three files together** - JSON + MySQL + SQLite
3. **Review diffs before committing** - Ensure generated SQL matches intent
4. **Run tests after schema changes** - Verify nothing broke
5. **Create migration patches for deployed installations** - Don't just update table definitions

## 🚀 Quick Reference

```bash
# Edit schema
vim sql/tables.json

# Regenerate (one command, handles everything)
./maintenance/regenerateSchema.sh

# Review
git diff sql/

# Commit all three
git add sql/
git commit -m "feat: schema update"

# CI will verify ✓
git push
```

## 📚 More Information

- **Detailed docs:** `../DEVELOPERS.md` (Database Schema Management section)
- **MediaWiki guide:** https://www.mediawiki.org/wiki/Manual:Schema_changes
- **Abstract format:** https://www.mediawiki.org/wiki/Manual:Schema_changes#Automatically_generated

