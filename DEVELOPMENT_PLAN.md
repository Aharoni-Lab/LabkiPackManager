LabkiPackManager Development Plan
=================================

Overview and Goals
------------------

LabkiPackManager integrates a Labki content pack repository with a MediaWiki 1.44 instance. It allows administrators to import predefined wiki content (layouts, templates, forms) stored as `.wiki` files (not XML) from a version-controlled repository. This extension will be developed in this repository and cloned into the Docker-based MediaWiki platform.

Key objectives:

- Create a minimal MediaWiki extension structure that loads (visible on `Special:Version`).
- Add a special page `Special:LabkiPackManager` for admins to initiate imports.
- Fetch a YAML manifest (`manifest.yml`) from a remote content pack repository (GitHub) to list available packs.
- Import `.wiki` files into pages (bypassing XML; parse and save text directly).
- Define a `labkipackmanager-manage` right to restrict usage.
- Plan future export of content back to the repo via pull requests.

Content Pack Repository Structure (Planned)
------------------------------------------

labki-content/

- manifest.yml             (lists available packs)
- packs/
  - publication/
    - manifest.yml
    - pages/
      - Template:Publication.wiki
      - Form:Publication.wiki
      - Category:Publication.wiki
      - Property:Has author.wiki
  - onboarding/
    - manifest.yml
    - pages/
      - Template:Onboarding.wiki
      - Form:Onboarding.wiki
      - Category:Onboarding.wiki

Top-level manifest example (manifest.yml):

```yaml
packs:
  - id: publication
    path: packs/publication
    version: 1.0.0
    description: Templates and forms for managing publications
  - id: onboarding
    path: packs/onboarding
    version: 1.1.0
    description: Standardized onboarding checklists
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
- Use MediaWiki HTTP facilities to fetch YAML.
- Parse YAML and handle errors.

Step 4: List Packs (UI)
-----------------------

- Render a form listing packs with checkboxes and CSRF token.
- On POST, capture selected pack IDs and confirm selection.

Step 5: Import `.wiki` Packs
----------------------------

- Config: `LabkiContentBaseURL` to construct raw file URLs.
- For each selected pack, fetch the pack-level `manifest.yml` under `packs/<id>/` to enumerate contents, then fetch the corresponding `.wiki` files under `packs/<id>/pages/`.
- Create or update each target page via `WikiPageFactory` / `PageUpdater` with the fetched wikitext.
- Provide success/error feedback per pack.

Step 6: Permissions & Security
------------------------------

- Define `labkipackmanager-manage` right; grant to `sysop` by default.
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

