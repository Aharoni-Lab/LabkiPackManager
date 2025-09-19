Title: Implement YAML manifest fetching and listing in Special:LabkiPackManager

Summary
-------

This PR introduces read-only support for fetching the top-level YAML manifest (manifest.yml) from the Labki content repo and listing available packs on Special:LabkiPackManager. It lays the groundwork for selecting packs to import in a follow-up PR.

Key Goals
---------

- Fetch YAML manifest from $wgLabkiContentManifestURL
- Parse YAML and normalize into an internal array of packs
- Render a simple list/table of packs on Special:LabkiPackManager
- Handle errors gracefully and show actionable messages

Scope of Changes
----------------

1) Add a small fetcher service

- File: includes/Services/ManifestFetcher.php
- Responsibilities:
  - Use MediaWiki\Services\HttpRequestFactory to GET the YAML manifest
  - Parse YAML using Symfony Yaml component
  - Validate expected structure (packs: [ { id, path, version, description } ])
  - Return array of packs or a detailed StatusValue error

2) Update special page to list packs

- File: includes/Special/SpecialLabkiPackManager.php
- Changes:
  - Require right: labkipackmanager-manage
  - Call ManifestFetcher; on success, render a list/table of packs (id, description, version)
  - Add basic checkbox form + CSRF token (submit only confirms selection for now)
  - Error paths: network failures, non-200 responses, YAML parse failures, invalid schema

3) Add composer dependency for YAML parsing

- File: composer.json (new)
- Dependency: "symfony/yaml": "^6.0"
- Rationale: robust, well-maintained YAML parser; consistent with PHP 8.1+ used by MW 1.44

4) Adjust configuration default and docs

- Update extension.json default for LabkiContentManifestURL to point at manifest.yml
- README already reflects YAML/structure; ensure alignment

5) i18n strings

- File: i18n/en.json
- New messages (examples):
  - labkipackmanager-list-title: "Available Content Packs"
  - labkipackmanager-list-empty: "No packs found in the manifest."
  - labkipackmanager-error-fetch: "Failed to fetch content pack manifest."
  - labkipackmanager-error-parse: "Failed to parse content pack manifest (YAML error)."
  - labkipackmanager-error-schema: "Manifest format is invalid or missing required fields."

Non-Goals (Out of Scope)
------------------------

- No actual import of .wiki pages yet (will come in a separate PR)
- No per-pack manifest retrieval or dependency resolution yet
- No UI polish beyond basic list and checkboxes

Testing Plan
------------

- Unit tests
  - ManifestFetcher: success path with sample YAML; error paths (HTTP 404/500, invalid YAML, missing fields)
  - Special page: render with empty packs; render with multiple packs; error message rendering
- Manual verification
  - Set $wgLabkiContentManifestURL to a test repo’s raw manifest.yml
  - Navigate to Special:LabkiPackManager as a sysop
  - Confirm list renders; select a few checkboxes; submit to see confirmation

Acceptance Criteria
-------------------

- As an admin with labkipackmanager-manage, I can open Special:LabkiPackManager and see a list of packs sourced from manifest.yml
- If the manifest can’t be fetched/parsed, I see a clear error message
- The page includes a POST form with checkboxes and a valid CSRF token
- No writes occur to the wiki yet

Backward Compatibility / Deployment Notes
----------------------------------------

- Adds composer dependency symfony/yaml; run composer install in the extension directory during build
- Config default for LabkiContentManifestURL changes to manifest.yml
- No schema changes or new DB tables

Security / Permissions
----------------------

- Page is restricted to labkipackmanager-manage (granted to sysop by default)
- CSRF token required for POST

Follow-ups
----------

- Implement per-pack manifest fetch and import execution (.wiki pages via WikiPageFactory/PageUpdater)
- Add dependency resolution UI hinting when packs require others
- Add search/filter and better display for pack metadata

Linked Docs
-----------

- README: YAML manifest and packs structure
- DEVELOPMENT_PLAN.md: Steps 3–5 updated for YAML flow

