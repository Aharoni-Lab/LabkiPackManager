# LabkiPackManager API Documentation

## Overview

LabkiPackManager uses MediaWiki's **Action API**, not REST. All requests go through `api.php` with an `action` parameter.

### Request Format

```
POST api.php?action={actionName}&format=json
GET  api.php?action={actionName}&format=json
```

**Note**: Write operations require POST and CSRF tokens.

### REST vs Action API

| REST Style | MediaWiki Action API |
|------------|---------------------|
| `POST /repos` | `POST api.php?action=labkiReposAdd` |
| `GET /repos/{id}` | `GET api.php?action=labkiReposList&repo_id={id}` |
| `DELETE /repos/{id}` | `POST api.php?action=labkiReposRemove&repo_id={id}` |

---

## API Structure

```
includes/API/
├── Repos/              # Repository management
├── Manifests/          # Manifest retrieval
├── Packs/              # Pack operations
├── Pages/              # Page validation
└── Operations/         # Operation tracking
```

---

## API Endpoints

### Repositories

#### `labkiReposList` (GET)
List all content repositories and their refs.

```http
GET api.php?action=labkiReposList&format=json
```

#### `labkiReposAdd` (POST)
Add a new Git repository as a content source. Returns `operation_id` for tracking.

```http
POST api.php?action=labkiReposAdd&url=...&refs=main|v1.0&format=json
```

#### `labkiReposSync` (POST)
Fetch latest changes from remote repository.

```http
POST api.php?action=labkiReposSync&repo_id=1&format=json
```

#### `labkiReposRemove` (POST)
Remove a repository and optionally its installed packs.

```http
POST api.php?action=labkiReposRemove&repo_id=1&format=json
```

---

### Manifests

#### `labkiManifestGet` (GET)
Retrieve parsed manifest data for a specific `repo@ref`.

```http
GET api.php?action=labkiManifestGet&repo_id=1&ref=main&format=json
```

---

### Packs

#### `labkiPacksList` (GET)
List all installed packs with their pages.

```http
GET api.php?action=labkiPacksList&format=json
```

#### `labkiPacksPreview` (POST)
Preview install/update/remove operation. Detects naming conflicts before committing changes.

```http
POST api.php?action=labkiPacksPreview&operation=install&repo_id=1&ref=main&format=json
```

#### `labkiPacksInstall` (POST)
Install packs with resolved page titles. Returns `operation_id` for tracking.

```http
POST api.php?action=labkiPacksInstall&repo_id=1&ref=main&packs=...&format=json
```

#### `labkiPacksUpdate` (POST)
Update an installed pack to a newer version. Returns `operation_id`.

```http
POST api.php?action=labkiPacksUpdate&pack_id=10&format=json
```

#### `labkiPacksRemove` (POST)
Remove an installed pack and optionally its pages.

```http
POST api.php?action=labkiPacksRemove&pack_id=10&format=json
```

---

### Pages

#### `labkiPagesValidate` (POST)
Validate multiple page titles for MediaWiki compatibility.

```http
POST api.php?action=labkiPagesValidate&titles=Page1|Page2&format=json
```

#### `labkiPagesExists` (POST)
Check if pages exist in the wiki (bulk check).

```http
POST api.php?action=labkiPagesExists&titles=Page1|Page2&format=json
```

---

### Operations

#### `labkiOperationsStatus` (GET)
Track progress of long-running background operations.

**Single operation:**
```http
GET api.php?action=labkiOperationsStatus&operation_id=repo_add_abc123&format=json
```

**List operations:**
```http
GET api.php?action=labkiOperationsStatus&limit=10&format=json
```

**Status values:** `queued`, `running`, `success`, `failed`

---

## Response Format

All responses include a `_meta` field:

```json
{
  "...": "response data",
  "_meta": {
    "schemaVersion": 1,
    "timestamp": "20251024120000"
  }
}
```

---

## Authentication & Permissions

### Required Permissions

Write operations require the `labkipack-manage` permission.

### CSRF Tokens

Get a token:
```http
GET api.php?action=query&meta=tokens&format=json
```

Use in requests:
```http
POST api.php?action=labkiReposAdd&token=YOUR_TOKEN&...
```

---

## Complete API Reference

| Action | Domain | Method | Purpose | File |
|--------|--------|--------|---------|------|
| `labkiReposList` | Repos | GET | List repositories | `API/Repos/ApiLabkiReposList.php` |
| `labkiReposAdd` | Repos | POST | Add repository | `API/Repos/ApiLabkiReposAdd.php` |
| `labkiReposSync` | Repos | POST | Sync repository | `API/Repos/ApiLabkiReposSync.php` |
| `labkiReposRemove` | Repos | POST | Remove repository | `API/Repos/ApiLabkiReposRemove.php` |
| `labkiManifestGet` | Manifests | GET | Get manifest for repo@ref | `API/Manifests/ApiLabkiManifestGet.php` |
| `labkiPacksList` | Packs | GET | List installed packs | `API/Packs/ApiLabkiPacksList.php` |
| `labkiPacksPreview` | Packs | POST | Preview operation | `API/Packs/ApiLabkiPacksPreview.php` |
| `labkiPacksInstall` | Packs | POST | Install packs | `API/Packs/ApiLabkiPacksInstall.php` |
| `labkiPacksUpdate` | Packs | POST | Update pack | `API/Packs/ApiLabkiPacksUpdate.php` |
| `labkiPacksRemove` | Packs | POST | Remove pack | `API/Packs/ApiLabkiPacksRemove.php` |
| `labkiPagesValidate` | Pages | POST | Validate titles | `API/Pages/ApiLabkiPagesValidate.php` |
| `labkiPagesExists` | Pages | POST | Check existence | `API/Pages/ApiLabkiPagesExists.php` |
| `labkiOperationsStatus` | Operations | GET | Track operation progress | `API/Operations/ApiLabkiOperationsStatus.php` |

---

## Design Principles

1. **Single Responsibility** - Each endpoint does one thing
2. **Preview Before Action** - Detect conflicts before committing
3. **Async Operations** - Long operations return `operation_id` immediately
4. **Consistent Naming** - `labki{Domain}{Action}` pattern

---

## Data Flow

```
User Interaction (Special Page)
    ↓
Frontend (Vue/TypeScript) OR Server-rendered PHP
    ↓
API Endpoint (Action API)
    ↓
Service Layer (GitContentManager, Registries)
    ↓
Database / Filesystem
```
