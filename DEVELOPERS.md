# LabkiPackManager Frontend - Developer Guide

## Prerequisites
- Node.js 20+
- MediaWiki core with ResourceLoader (extension requires MW >= 1.44)

## Install
```bash
npm ci
```

## Build
```bash
npm run build   # builds resources/modules/ext.LabkiPackManager/app.bundle.js
npm run watch   # rebuild on changes
```

The bundle is loaded via RL module `ext.LabkiPackManager.bundle` (see extension.json). Vue and Codex are provided by RL; Rollup externalizes them.

## Test
```bash
npm test   # runs vitest
```

Unit tests live under `resources/tests/` and mock `mw`/`fetch` for isolation.

## Code Layout
```
resources/src/
  api.js            # API calls + manifest normalization
  state.js          # state factory (see state.d.ts)
  constants.js      # message types, shared consts
  ui/               # Vue SFCs (Codex components inside)
  main.js           # app entry; mounts to #labki-pack-manager-root
```

## Conventions
- Codex + Vue 3 components only. No OOUI.
- Use JSDoc for public functions.
- Prefer i18n via mw.msg( key ).
- Keep inline styles out of templates; use packs.css.

## Releasing
- Update version in package.json.
- Tag release: `git tag -a vX.Y.Z -m "Release vX.Y.Z" && git push --tags`.

## Linting (optional)
Install ESLint + Prettier:
```bash
npm i -D eslint prettier @eslint/js eslint-config-prettier eslint-plugin-vue
```
Create `.eslintrc.json` and `.prettierrc` as desired, then add scripts:
```json
{
  "scripts": {
    "lint": "eslint resources/src --ext .js,.vue",
    "format": "prettier -w resources/src"
  }
}
```
