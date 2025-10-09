# LabkiPackManager Frontend - Developer Guide

This guide explains how to develop, build, and troubleshoot the new Vue + Codex frontend that’s bundled for MediaWiki ResourceLoader (RL).

## Prerequisites
- Node.js 20+ (use nvm on Linux/WSL)
- MediaWiki core with RL (extension requires MW ≥ 1.44)

### Recommended: nvm (Linux/WSL)
```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"
nvm install 20 && nvm use 20
```

## Install
```bash
npm install
```

## Build and Run
We ship a single RL-friendly IIFE bundle:
- Output: `resources/modules/ext.LabkiPackManager/app.bundle.js`
- RL module: `ext.LabkiPackManager.bundle` (see `extension.json` and `SpecialLabkiPackManager`)

Build once:
```bash
npm run build
```

Auto-rebuild on changes:
```bash
npm run watch
```

Open Special page: `Special:LabkiPackManager`.

Notes:
- Vue and Codex are externalized and provided by RL (`vue`, `@wikimedia/codex`).
- In development you may see the Vue "development build" warning; production RL serves the minified/prod build automatically.

## Do I need to rebuild after changes?
- Yes, changes under `resources/src/**` must be bundled to `app.bundle.js` to run in MW.
- During development use `npm run watch` (auto rebuilds). For CI/deploy, run `npm run build`.

## Project Layout
```
resources/src/
  api.js            # API calls + manifest normalization
  state.js          # state factory (see state.d.ts)
  constants.js      # shared constants (MSG_TYPES, etc.)
  ui/               # Vue SFCs (Codex-based components)
  main.js           # app entry; mounts to #labki-pack-manager-root
resources/modules/  # built bundle (gitignored)
```

## Linting & Formatting
```bash
npm run lint      # ESLint (flat config)
npm run format    # Prettier
```

## Testing
```bash
npm test          # Vitest unit tests
```
Tests live in `resources/tests/` and mock `mw` and `fetch`.

## CI
`.github/workflows/ci.yml` runs: install → test → lint → build. The built file is not committed (see `.gitignore`), so CI should build it for artifacts/deployments.

## Common Troubleshooting
1) Windows/UNC path or WSL exec-bit issues
- If `rollup` permission is denied or tries to resolve in `C:\Windows`, invoke Rollup via Node:
  ```bash
  node ./node_modules/rollup/dist/bin/rollup -c
  ```
- Optionally change `package.json` scripts to use the Node path for portability.

2) "Codex is not defined"
- The bundle injects a small banner to bind `Vue` and `Codex` from `mw.loader.require` before executing. Ensure RL dependencies include `vue` and `@wikimedia/codex` (see `extension.json`).

3) "Component is missing template or render function"
- The root app must render `<lpm-root />`. This is set in `resources/src/main.js`.

4) Vue dev-build warning
- Expected in development. On production wikis RL serves the prod/minified build.

## Conventions
- Codex + Vue 3 only (OOUI is not used).
- Prefer i18n via `mw.msg( key )`.
- Keep inline styles out of templates; add to `resources/css/packs.css`.
- Use JSDoc for public functions; `.d.ts` files provide optional TS types.

## Releasing
- Update `package.json` version.
- Tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z" && git push --tags`.
- Ensure your deployment builds the bundle (since `resources/modules/` is gitignored).
