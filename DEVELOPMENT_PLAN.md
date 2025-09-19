LabkiPackManager Development Plan
=================================

Overview and Goals
------------------

LabkiPackManager integrates a Labki content pack repository with a MediaWiki 1.44 instance. It allows administrators to import predefined wiki content (layouts, templates, forms) stored as `.wiki` files (not XML) from a version-controlled repository. This extension will be developed in this repository and cloned into the Docker-based MediaWiki platform.

Key objectives:

- Create a minimal MediaWiki extension structure that loads (visible on `Special:Version`).
- Add a special page `Special:LabkiPackManager` for admins to initiate imports.
- Fetch a manifest from a remote content pack repository (GitHub) to list available packs.
- Import `.wiki` files into pages (bypassing XML; parse and save text directly).
- Define a `labki-import` right to restrict usage.
- Plan future export of content back to the repo via pull requests.

Content Pack Repository Structure (Planned)
------------------------------------------

labki-content/

- manifest.json            (lists available packs)
- layouts/
  - standard_lab_layout.wiki
- templates/
  - ExperimentPack.wiki

Manifest example:

```json
{
  "packs": [
    { "id": "standard_lab_layout", "type": "layout", "file": "layouts/standard_lab_layout.wiki", "description": "Standard lab layout pages" },
    { "id": "ExperimentPack", "type": "template", "file": "templates/ExperimentPack.wiki", "description": "Experimental forms and templates" }
  ]
}
```

Step 1: Bare-Bones Extension
----------------------------

- Directory: `extensions/LabkiPackManager/`
- Files: `extension.json`, `includes/`, `i18n/`, `README.md`
- Registration: `wfLoadExtension( 'LabkiPackManager' );`
- Verify on `Special:Version`.

Step 2: Special Page (LabkiPackManager)
-----------------------------------

- Class: `LabkiPackManager\\Special\\SpecialLabkiPackManager` extending `SpecialPage`.
- Register via `extension.json` `SpecialPages`.
- Placeholder output confirming page loads.

Step 3: Fetch Manifest
----------------------

- Config: `LabkiContentManifestURL` in `extension.json` (exposed as `$wgLabkiContentManifestURL`).
- Use MediaWiki HTTP facilities to fetch manifest JSON.
- Parse JSON and handle errors.

Step 4: List Packs (UI)
-----------------------

- Render a form listing packs with checkboxes and CSRF token.
- On POST, capture selected pack IDs and confirm selection.

Step 5: Import `.wiki` Packs
----------------------------

- Config: `LabkiContentBaseURL` to construct raw file URLs.
- For each selected pack, fetch `.wiki` file content.
- For each logical page in the pack:
  - Since packs are `.wiki` not XML, define a simple delimiter format within `.wiki` files (e.g., `=== Page: Title ===` followed by wikitext) or restrict to single-page packs initially.
  - Create or update the target page via `WikiPageFactory` / `PageUpdater` with the fetched wikitext.
- Provide success/error feedback per pack.

Step 6: Permissions & Security
------------------------------

- Define `labki-import` right; grant to `sysop` by default.
- Restrict `Special:LabkiPackManager` to users with this right.
- Validate POST token and selected IDs against fetched manifest.

Step 7: Future Export & PRs
---------------------------

- Export selected pages’ current wikitext.
- Commit to a new branch in the content repo using GitHub REST API with a bot token (`$wgLabkiGitHubToken`).
- Open a pull request and show link to the admin.

Step 8: Testing & Docs
----------------------

- PHPUnit tests for manifest parsing and import routines.
- GitHub Actions CI for PHPCS and PHPUnit.
- Expand README with Docker clone instructions and configuration.

Docker Integration
------------------

- Add a `git clone` step for this repo in your MediaWiki Dockerfile or compose build stage.
- Bind mount or bake into image under `extensions/LabkiPackManager`.
- Add `wfLoadExtension( 'LabkiPackManager' );` in `LocalSettings.php` within your Dockerized environment.

