[![codecov](https://codecov.io/gh/Aharoni-Lab/LabkiPackManager/branch/main/graph/badge.svg)](https://codecov.io/gh/Aharoni-Lab/LabkiPackManager)

# LabkiPackManager

MediaWiki extension to import Labki content packs from Git repositories. Content is stored as `.wiki` page files, managed through a Vue.js-powered UI with dependency resolution and conflict detection.

**Requirements:**
- MediaWiki 1.44+
- PHP 8.2+
- Node.js 20+ (for frontend development)
- Git (for repository management)

## Features

- 📦 **Git-based content repositories** - Clone and sync content from GitHub/GitLab/etc.
- 🌳 **Multi-ref support** - Manage different branches/tags per repository
- 🔗 **Dependency resolution** - Automatic dependency graph and hierarchy visualization
- 🎨 **Modern UI** - Vue 3 + Codex components with real-time state management
- ⚡ **Background operations** - Async repo syncing with progress tracking
- 🔄 **Page conflict detection** - Prevent overwrites
- 📊 **Mermaid graphs** - Visual dependency trees

## Installation

### 1. Clone into MediaWiki extensions directory

```bash
cd /var/www/html/extensions
git clone https://github.com/Aharoni-Lab/LabkiPackManager.git
```

### 2. Enable in LocalSettings.php

```php
wfLoadExtension( 'LabkiPackManager' );
```

### 3. Install PHP dependencies

```bash
cd extensions/LabkiPackManager
composer install --no-dev
```

### 4. Run database migrations

```bash
php maintenance/update.php
```

### 5. Build frontend assets

```bash
cd extensions/LabkiPackManager
npm install
npm run build
```

## Quick Development Setup (Docker)

For local development with MediaWiki-Docker, use the setup script:

```bash
cd ~/dev/LabkiPackManager
chmod +x setup_mw_test_env.sh
./setup_mw_test_env.sh
```

**Notes:**
- Docker must be running
- MediaWiki cloned to platform cache directory:
  - Linux: `~/.cache/labki/mediawiki-test`
  - macOS: `~/Library/Caches/labki/mediawiki-test`
  - Windows: `~\AppData\Local\labki\mediawiki-test`
- Override with: `MW_DIR=/custom/path ./setup_mw_test_env.sh`
- Script is idempotent - safe to re-run

**Then access:**
- Wiki: `http://localhost:8080/w`
- Special page: `http://localhost:8080/w/index.php/Special:LabkiPackManager`

## Usage

### Add a Repository

1. Visit `Special:LabkiPackManager` as an admin
2. Click "Add Repository" and enter Git URL (e.g., `https://github.com/Aharoni-Lab/labki-packs`)
3. Wait for initial sync to complete
4. Select repository and ref (branch/tag) from dropdowns

### Install Packs

1. Choose repository and ref
2. View dependency hierarchy and select packs
3. Customize page titles/prefixes if needed
4. Click "Apply" to queue installation
5. Monitor progress in operations panel

## Configuration

Add to `LocalSettings.php`:

```php
// Optional: Global prefix for collision avoidance
// Example: 'Labki' → imports "Page" as "Labki:Page"
$wgLabkiGlobalPrefix = '';

// Optional: Cache directory for Git worktrees
// Default: $IP/cache/labki
$wgLabkiCacheDirectory = "$IP/cache/labki";
```

### Permissions

By default, only users with `labkipackmanager-manage` right can manage packs (granted to `sysop` group). Customize in `LocalSettings.php`:

```php
// Grant to additional groups
$wgGroupPermissions['bureaucrat']['labkipackmanager-manage'] = true;
```

## API Endpoints

The extension provides action APIs:

### Repositories
- `labkiReposAdd` - Add a new Git repository
- `labkiReposList` - List repositories and refs
- `labkiReposSync` - Sync/fetch repository updates
- `labkiReposRemove` - Remove repository or specific refs

### Manifests
- `labkiManifestGet` - Get parsed manifest data
- `labkiGraphGet` - Get dependency graph
- `labkiHierarchyGet` - Get computed hierarchy tree

### Packs
- `labkiPacksList` - List installed packs
- `labkiPacksAction` - Unified pack interaction endpoint (init/select/rename/apply)

### Operations
- `labkiOperationsStatus` - Query background operation status

See `/includes/API/` for full API documentation.

## Development

### Project Structure

```
LabkiPackManager/
├── includes/           # PHP backend
│   ├── API/           # Action API modules
│   ├── Domain/        # Domain models
│   ├── Handlers/      # Command handlers
│   ├── Jobs/          # Background jobs
│   └── Services/      # Business logic
├── resources/
│   ├── src/           # Vue.js frontend source
│   │   ├── ui/        # Components
│   │   ├── state/     # State management
│   │   └── api/       # API client
│   └── modules/       # Built bundles (committed)
└── tests/
    ├── phpunit/       # PHP tests
    │   ├── unit/
    │   └── integration/
    └── fixtures/      # Shared test data
```

### Frontend Development

```bash
# Install dependencies
npm install

# Development build (watch mode)
npm run watch

# Production build
npm run build

# Run tests
npm test

# Lint code
npm run lint
```

### Backend Testing

From MediaWiki root directory:

```bash
# Unit tests (171 tests)
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/unit

# Integration tests (500 tests)
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration

# All tests
composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit
```

Or using Docker:

```bash
cd ~/.cache/labki/mediawiki-test  # or your MW_DIR

# Unit tests
docker compose exec mediawiki bash -lc 'composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/unit'

# Integration tests  
docker compose exec mediawiki bash -lc 'composer phpunit:entrypoint -- extensions/LabkiPackManager/tests/phpunit/integration'
```

### Test Coverage

- Coverage reports uploaded to Codecov

## Content Repository Format

Your Git repository should have a single `manifest.yml` at the root with all content pages in a `pages/` directory:

```
your-repo/
├── manifest.yml              # Single root manifest (required)
└── pages/                    # All .wiki/.lua/.js/.css files
    ├── page-name.wiki
    ├── template-publication.wiki
    └── module-data.lua
```

### manifest.yml Structure

```yaml
schema_version: "1.0.0"
name: "Repository Display Name"
last_updated: "2025-01-15T10:30:00Z"

# Global page registry (page titles → file paths)
pages:
  "Page Name":
    file: "pages/page-name.wiki"
    last_updated: "2025-01-15T10:30:00Z"
    description: "Optional description"
  
  "Template:Publication":
    file: "pages/template-publication.wiki"
    last_updated: "2025-01-14T08:00:00Z"
  
  "Module:Data":
    file: "pages/module-data.lua"
    last_updated: "2025-01-10T12:00:00Z"

# Pack registry (pack IDs → metadata and page lists)
packs:
  core:
    version: "1.0.0"
    description: "Core templates and modules"
    pages:
      - "Template:Publication"
      - "Module:Data"
    tags:
      - "core"
      - "templates"
  
  advanced:
    version: "1.2.0"
    description: "Advanced features"
    pages:
      - "Page Name"
    depends_on:
      - "core"  # Dependency on other packs
    tags:
      - "advanced"
```

### Schema Rules

- **schema_version**: Semantic version (e.g., `"1.0.0"`)
- **name**: Repository display name (letters, digits, spaces, hyphens, colons, underscores)
- **pages**: Object mapping page titles to file metadata
  - Keys: Canonical wiki titles (e.g., `"Template:Name"`)
  - `file`: Path under `pages/` (lowercase, `.wiki`/`.lua`/`.js`/`.css`)
  - `last_updated`: ISO 8601 UTC timestamp
- **packs**: Object mapping pack IDs to pack metadata
  - `version`: Required semantic version
  - `pages`: Array of page titles (must exist in global `pages` registry)
  - `depends_on`: Optional array of pack IDs
  - `tags`: Optional slugified labels (lowercase, hyphens)
  - Either `pages` (1+) or `depends_on` (2+) required

### Validation

Manifests are validated using [JSON Schema](https://json-schema.org/). The schema is enforced by CI validation from [labki-packs-tools](https://github.com/Aharoni-Lab/labki-packs-tools) which can be added to your repository's GitHub Actions.

### Example Repositories

- [labki-packs](https://github.com/Aharoni-Lab/labki-packs) - Main content repository
- [labki-base-packs](https://github.com/Aharoni-Lab/labki-base-packs) - Base templates

## Troubleshooting

### Git operations fail
- Ensure Git is installed and accessible to PHP
- Check write permissions on cache directory
- Verify repository URL is accessible

### Frontend not loading
- Run `npm run build` to generate bundles
- Check browser console for errors
- Verify Mermaid extension is installed (optional but recommended)

### Database errors
- Run `php maintenance/update.php` to apply migrations
- Check tables exist: `labki_content_repo`, `labki_content_ref`, `labki_pack`, `labki_page`, `labki_operations`, `labki_pack_session_state`

## License

EUPL-1.2

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development guidelines.
