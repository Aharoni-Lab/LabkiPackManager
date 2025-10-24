**Naming Convention**:
- Format: `ApiLabki{Domain}{Action}.php`
- Examples: `ApiLabkiReposList.php`, `ApiLabkiPacksInstall.php`, `ApiLabkiOperationsStatus.php`
- Action names: `labki{Domain}{Action}` (e.g., `labkiReposList`, `labkiPacksInstall`)

**Shared Base Classes**:
- Each domain has a base class (e.g., `RepoApiBase`, `PackApiBase`)
- Base classes provide shared validation, permissions, and registry access
- Reduces code duplication and enforces consistency

**Data Flow**:
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
