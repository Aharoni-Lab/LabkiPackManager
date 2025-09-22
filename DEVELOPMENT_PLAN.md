LabkiPackManager Development Plan
=================================

Overview and Goals
------------------

LabkiPackManager integrates the `labki-packs` content pack repository with a MediaWiki 1.44 instance. It allows administrators to import predefined wiki content (layouts, templates, forms) stored as `.wiki` files (not XML) from a version-controlled repository. The upstream packs repository is `Aharoni-Lab/labki-packs` on GitHub. This extension will be developed in this repository and cloned into the Docker-based MediaWiki platform.

Key objectives:

- Create a minimal MediaWiki extension structure that loads (visible on `Special:Version`). [Implemented]
- Add a special page `Special:LabkiPackManager` for admins to initiate imports. [Implemented]
- Fetch and parse a YAML manifest (`manifest.yml`) from the `labki-packs` repository (GitHub) and display available packs. [Implemented]
- Import `.wiki`/`.md` files into pages (bypassing XML; parse and save text directly).
- Define a `labkipackmanager-manage` right to restrict usage.
- Plan future export of content back to the repo via pull requests.

Current Status
--------------

- Root manifest fetching via HTTP and YAML parsing is implemented.
- Cached storage of fetched packs and refresh flow from the special page are implemented.
- `Special:LabkiPackManager` displays the fetched manifest data as a selectable list.
- Basic unit tests exist for manifest parsing, fetching, store, and i18n keys.

Content Pack Repository Structure (labki-packs)
-----------------------------------------------

`labki-packs/` (GitHub: `Aharoni-Lab/labki-packs`)

- manifest.yml          (global hierarchical registry of packs with refs to pack.yml)
- README.md             (overview of how to use/update packs)
- schema/               (optional JSON/YAML schemas for validation)
- packs/                (all content organized here)

Packs layout
------------

Inside `packs/`, use a directory hierarchy that mirrors logical grouping.

- Any node in the hierarchy can contain a `pages/` folder with plain `.wiki` or `.md` files.
- Leaves are individual packs, each with their own `pack.yml` (name, version, dependencies, pages).

Example
-------

```
packs/

├─ lab-operations/
│   ├─ manifest.yml             # optional local manifest (tracks sub-packs, version)
│   ├─ pages/
│   │   └─ safety_overview.wiki
│   ├─ equipment/
│   │   ├─ calibration/
│   │   │   ├─ microscope_pack/
│   │   │   │   ├─ pack.yml     # pack definition (name, version, dependencies)
│   │   │   │   └─ pages/
│   │   │   │       ├─ intro.wiki
│   │   │   │       └─ procedure.wiki
│   │   │   └─ scale_pack/
│   │   │       ├─ pack.yml
│   │   │       └─ pages/...
│   │   └─ maintenance_pack/
│   │       ├─ pack.yml
│   │       └─ pages/...
│   └─ training_pack/
│       ├─ pack.yml
│       └─ pages/...
│
└─ tool-development/
    ├─ imaging/
    │   └─ miniscope_pack/
    │       ├─ pack.yml
    │       └─ pages/...
    └─ acquisition_pack/
        ├─ pack.yml
        └─ pages/...
```

Root-level manifest example (manifest.yml):

```yaml
version: 1.0.0
last_updated: 2025-09-22

packs:
  lab-operations:
    ref: packs/lab-operations/pack.yml
    children:
      equipment:
        ref: packs/lab-operations/equipment/pack.yml
        children:
          calibration:
            ref: packs/lab-operations/equipment/calibration/pack.yml
            children:
              microscope_pack:
                ref: packs/lab-operations/equipment/calibration/microscope_pack/pack.yml
              scale_pack:
                ref: packs/lab-operations/equipment/calibration/scale_pack/pack.yml
          maintenance_pack:
            ref: packs/lab-operations/equipment/maintenance_pack/pack.yml
      training_pack:
        ref: packs/lab-operations/training_pack/pack.yml

  tool-development:
    ref: packs/tool-development/pack.yml
    children:
      imaging:
        ref: packs/tool-development/imaging/pack.yml
        children:
          miniscope_pack:
            ref: packs/tool-development/imaging/miniscope_pack/pack.yml
      acquisition_pack:
        ref: packs/tool-development/acquisition_pack/pack.yml
```

pack.yml (per-pack metadata) examples:

Example parent pack: `packs/lab-operations/pack.yml`

```yaml
name: lab-operations
version: 2.1.0
description: "Guides and procedures for day-to-day lab operations."
pages:
  - pages/safety_overview.wiki
  - pages/general_policies.wiki
dependencies: []
```

Example leaf or intermediate pack: `packs/tool-development/imaging/pack.yml`

```yaml
name: imaging
version: 2.0.0
description: "Imaging-related tools and methods."
pages: []
dependencies: []
```

Notes
-----

- Every directory under `packs/` is a pack with its own `pack.yml`.
- Leaf packs typically only have pages. Parent packs may have both pages and children.
- The root `manifest.yml` encodes hierarchy via `ref` pointers to each directory's `pack.yml` and optional nested `children`.
- `pages/` may contain `.wiki` or `.md` content files at any level.

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

Step 3: Fetch Manifest (Implemented)
------------------------------------

- Config: `LabkiContentManifestURL` in `extension.json` (exposed as `$wgLabkiContentManifestURL`). Defaults to the raw URL for `Aharoni-Lab/labki-packs` root manifest.
- Use MediaWiki HTTP facilities to fetch YAML.
- Parse YAML and handle errors.
- Future: support fetching folder-level manifests for recursive browsing.

Step 4: List Packs (UI) (Implemented)
-------------------------------------

- Render a form listing packs with checkboxes and CSRF token.
- Refresh control allows re-fetching and caching the latest manifest.
- On POST, selected pack IDs will be captured for import (import flow pending).

Step 5: Import `.wiki` Packs
----------------------------

- Config: `LabkiContentBaseURL` to construct raw file URLs.
- For each selected pack, fetch the pack-level `manifest.yml` under the pack path (which may be nested), then fetch the corresponding `.wiki` files under `<pack path>/pages/`.
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

