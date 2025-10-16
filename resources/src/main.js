/**
 * LabkiPackManager – Main Entry Point
 * ------------------------------------------------------------
 * Initializes the Vue 3 + Codex frontend for the LabkiPackManager
 * MediaWiki extension. Handles repository initialization, manifest
 * loading, user feedback messages, and high-level UI orchestration.
 */

import { createInitialState } from './state.js';
import { fetchRepos, fetchManifestFor, fetchInstalledFor } from './api.js';
import { major, compareVersions } from './utils/version.js';
import { MSG_TYPES } from './constants.js';
import { idToName, isPackNode } from './utils/nodeUtils.js';
import { buildMermaidFromGraph } from './utils/mermaidBuilder.js';

// External libs
import * as Vue from 'vue';
import * as Codex from '@wikimedia/codex';
import mermaid from 'mermaid';
import './styles/labkipackmanager.scss';

// Root UI components
import LpmToolbar from './ui/toolbar.vue';
import LpmTree from './ui/tree.vue';
import LpmMessages from './ui/messages.vue';
import LpmDialogs from './ui/dialogs.vue';

// ------------------------------------------------------------
// Mermaid configuration (on-demand)
// ------------------------------------------------------------
let mermaidConfigured = false;
function ensureMermaidConfigured() {
  if (mermaidConfigured) return true;
  try {
    const api = mermaid && (mermaid.default?.initialize ? mermaid.default : mermaid);
    if (api && typeof api.initialize === 'function') {
      api.initialize({
        startOnLoad: false,
        theme: 'neutral',
        securityLevel: 'loose',
        fontFamily: 'Inter, system-ui, sans-serif'
      });
      mermaidConfigured = true;
      return true;
    }
  } catch {
    // swallow; skip rendering if not ready
  }
  return false;
}

// ------------------------------------------------------------
// Helper utilities
// ------------------------------------------------------------

/** Returns true if any pack node satisfies predicate() and is selected. */
function somePackSelected(nodes, selectedPacks, predicate) {
  for (const [id, node] of Object.entries(nodes)) {
    if (!isPackNode(id, node)) continue;
    const name = idToName(id, node);
    if (selectedPacks[name] && predicate(node)) return true;
  }
  return false;
}

export function mountApp(rootSelector = '#labki-pack-manager-root') {
  const app = Vue.createApp({
    template: '<lpm-root ref="root" />',

    data() {
      return {
        ...createInitialState(),
        importSummary: [],
        upgradeSummary: [],
        messages: [],
        nextMsgId: 1
      };
    },

    computed: {
      hasActiveRepo() {
        return !!this.activeRepo;
      },
      hasImportableSelection() {
        return somePackSelected(
          this.data?.hierarchy?.nodes || {},
          this.selectedPacks,
          n => n.installStatus === 'new'
        );
      },
      hasUpgradeableSelection() {
        return somePackSelected(
          this.data?.hierarchy?.nodes || {},
          this.selectedPacks,
          n => n.installStatus === 'safe-upgrade'
        );
      }
    },

    methods: {
      // --------------------------------------------------------
      // Repository + Manifest loading
      // --------------------------------------------------------

      async loadRepos(forceRefresh = false) {
        try {
          const repos = await fetchRepos(forceRefresh);
          this.repos = repos;
          this.repoMenuItems = repos.map(r => ({
            label: r.name || r.url,
            value: r.url
          }));
        } catch (e) {
          console.error('[LabkiPackManager] Failed to initialize repos:', e);
          this.pushMessage(MSG_TYPES.ERROR, 'Failed to load repositories.');
        }
      },

      async getOrFetchManifest(repo) {
        let manifest = repo?.data;
        if (!manifest) {
          this.pushMessage(MSG_TYPES.INFO, `Loading manifest for ${this.activeRepo}...`);
          manifest = await fetchManifestFor(this.activeRepo, false);
          if (repo) repo.data = manifest;
        }
        this.data =
          manifest && (manifest.hierarchy || manifest.manifest)
            ? manifest
            : { hierarchy: null };
        return manifest;
      },

      /** Merge installed pack/page data into the manifest hierarchy. */
      mergeInstalledData(manifest, installed) {
        const installedByPack = Object.create(null);
        for (const pack of installed) {
          if (!pack?.name) continue;
          installedByPack[pack.name] = {
            version: pack.version,
            installedVersion: pack.installedVersion || pack.version,
            installStatus: pack.installStatus,
            pages: Object.fromEntries(
              (pack.pages || []).map(pg => [pg.name, pg.final_title || pg.finalTitle])
            )
          };
        }

        const nodes = this.data?.hierarchy?.nodes || {};
        const selectedPacks = { ...this.selectedPacks };

        for (const [id, node] of Object.entries(nodes)) {
          if (!isPackNode(id, node)) continue;
          const name = idToName(id, node);
          const info = installedByPack[name];

          if (!info) {
            Object.assign(node, {
              installStatus: 'new',
              isLocked: false,
              installedVersion: null
            });
            continue;
          }

          const curV = String(info.installedVersion || '0.0.0');
          const nextV = String(node.version || '0.0.0');
          const cmp = compareVersions(curV, nextV);
          const sameMajor = major(curV) === major(nextV);

          node.installedVersion = curV;
          node.isLocked = true;
          node.installStatus =
            cmp === 0
              ? 'already-installed'
              : sameMajor && cmp < 0
              ? 'safe-upgrade'
              : sameMajor && cmp > 0
              ? 'downgrade'
              : 'incompatible-update';

          selectedPacks[name] = true;

          // Attach final titles to pages
          node.pages = (node.pages || []).map(p => {
            const pageName = typeof p === 'string' ? p : p.name;
            const finalTitle = info.pages[pageName] || null;
            return { name: pageName, finalTitle };
          });
        }

        this.selectedPacks = selectedPacks;
      },

      async loadRepo() {
        if (!this.activeRepo) return;

        this.isLoadingRepo = true;
        const repo = this.repos.find(r => r.url === this.activeRepo);

        try {
          const manifest = await this.getOrFetchManifest(repo);
          const installed = await fetchInstalledFor(this.activeRepo);
          this.mergeInstalledData(manifest, installed);

          if (this.data?.graph) {
            await this.$nextTick();
            await this.renderMermaidGraph(this.data.graph);
          }

          this.pushMessage(
            MSG_TYPES.SUCCESS,
            mw.msg('labkipackmanager-load-success') || 'Manifest loaded.'
          );
        } catch (e) {
          const msg = `Failed to load ${this.activeRepo}: ${e?.message || e}`;
          this.pushMessage(MSG_TYPES.ERROR, msg.trim());
        } finally {
          this.isLoadingRepo = false;
        }
      },

      async refresh() {
        if (!this.activeRepo) {
          this.pushMessage(MSG_TYPES.WARNING, 'Select a repository first.');
          return;
        }

        this.isRefreshing = true;
        this.pushMessage(MSG_TYPES.INFO, `Refreshing manifest for ${this.activeRepo}...`);
        try {
          const data = await fetchManifestFor(this.activeRepo, true);
          this.data = data && (data.hierarchy || data.manifest) ? data : { hierarchy: null };
          const repo = this.repos.find(r => r.url === this.activeRepo);
          if (repo) repo.data = data;

          if (this.data?.graph) {
            await this.$nextTick();
            await this.renderMermaidGraph(this.data.graph);
          }

          this.pushMessage(
            MSG_TYPES.SUCCESS,
            mw.msg('labkipackmanager-refresh-success') || 'Manifest refreshed.'
          );
        } catch (e) {
          const msg = `Failed to refresh ${this.activeRepo}: ${e?.message || e}`;
          this.pushMessage(MSG_TYPES.ERROR, msg.trim());
        } finally {
          this.isRefreshing = false;
        }
      },

      // --------------------------------------------------------
      // Page + Graph utilities
      // --------------------------------------------------------

      async checkTitleExists(title) {
        try {
          const api = new mw.Api();
          const res = await api.get({
            action: 'labkiPageExists',
            format: 'json',
            formatversion: '2',
            title
          });
          return Boolean(res?.labkiPageExists?.exists);
        } catch (err) {
          console.warn('[LabkiPackManager] checkTitleExists failed:', err);
          return false;
        }
      },

      async renderMermaidGraph(graph) {
        try {
          const code = buildMermaidFromGraph(graph);
          const container = document.getElementById('lpm-graph');
          if (!container) return;

          await new Promise(r => requestAnimationFrame(r));
          container.innerHTML = `<div class="mermaid">${code}</div>`;

          if (!ensureMermaidConfigured()) return;
          const api = mermaid && (mermaid.default?.run ? mermaid.default : mermaid);
          if (api && typeof api.run === 'function') {
            await api.run({ nodes: [container.firstChild] });
          }
        } catch (err) {
          console.error('[LabkiPackManager] Mermaid render failed:', err);
        }
      },

      // --------------------------------------------------------
      // User feedback messages
      // --------------------------------------------------------

      pushMessage(type, text, timeout = 5000) {
        const id = this.nextMsgId++;
        this.messages.push({ id, type, text });
        if (timeout) setTimeout(() => this.dismissMessage(id), timeout);
      },

      dismissMessage(id) {
        this.messages = this.messages.filter(m => m.id !== id);
      },

      // --------------------------------------------------------
      // Import + Update Actions
      // --------------------------------------------------------

      confirmImport() {
        const root = this.$refs.root;
        const tree = root?.$refs?.tree;
        if (tree && this.activeRepo) {
          const summary = tree.exportSelectionSummary(this.activeRepo);
          this.importSummary = (summary?.packs || []).filter(
            p => p.selected && p.installStatus === 'new'
          );
        } else {
          this.importSummary = [];
        }
        this.showImportConfirm = true;
      },

      confirmUpdate() {
        const root = this.$refs.root;
        const tree = root?.$refs?.tree;
        if (tree && this.activeRepo) {
          const summary = tree.exportSelectionSummary(this.activeRepo);
          this.upgradeSummary = (summary?.packs || []).filter(
            p => p.selected && p.installStatus === 'safe-upgrade'
          );
        } else {
          this.upgradeSummary = [];
        }
        this.showUpdateConfirm = true;
      },

      async doImport() {
        this.showImportConfirm = false;
        const root = this.$refs.root;
        const tree = root?.$refs?.tree;
        if (!tree || typeof tree.exportSelectionSummary !== 'function') {
          this.pushMessage(MSG_TYPES.ERROR, 'Tree component not ready. Try again.');
          return;
        }

        const payload = tree.exportSelectionSummary(this.activeRepo);
        const selected = payload.packs.filter(
          p => p.selected && p.installStatus === 'new'
        );

        this.pushMessage(MSG_TYPES.INFO, 'Starting import…');

        try {
          const api = new mw.Api();
          const res = await api.postWithToken('csrf', {
            action: 'labkiUpdate',
            format: 'json',
            formatversion: '2',
            actionType: 'importPack',
            contentRepoUrl: payload.repoUrl,
            packs: JSON.stringify(selected)
          });

          if (res?.labkiUpdate?.success) {
            this.pushMessage(MSG_TYPES.SUCCESS, 'Import completed.');
          } else {
            const err = res?.labkiUpdate?.error || 'Import failed.';
            this.pushMessage(MSG_TYPES.ERROR, err);
          }
        } catch (e) {
          console.error('[Import]', e);
          this.pushMessage(MSG_TYPES.ERROR, e.message || 'Import error.');
        }
      },

      doUpdate() {
        this.showUpdateConfirm = false;
        const root = this.$refs.root;
        const tree = root?.$refs?.tree;
        if (!tree || typeof tree.exportSelectionSummary !== 'function') {
          this.pushMessage(MSG_TYPES.ERROR, 'Tree component not ready. Try again.');
          return;
        }
        const payload = tree.exportSelectionSummary(this.activeRepo);
        console.log('[Upgrade payload]', payload);
        this.pushMessage(MSG_TYPES.SUCCESS, 'Upgrade triggered.');
      }
    },

    async mounted() {
      await this.loadRepos();
    }
  });

  // ------------------------------------------------------------
  // Register Codex Components
  // ------------------------------------------------------------
  for (const [name, comp] of Object.entries(Codex)) {
    if (name?.startsWith('Cdx') && comp) {
      const kebab = name
        .replace(/^Cdx/, 'Cdx-')
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .toLowerCase();
      app.component(kebab, comp);
    }
  }

  // ------------------------------------------------------------
  // Root UI Composition
  // ------------------------------------------------------------
  app.component('lpm-root', {
    components: { LpmToolbar, LpmTree, LpmMessages, LpmDialogs },
    template: `
      <div class="lpm-root">
        <lpm-toolbar
          :repo-menu-items="$root.repoMenuItems"
          :active-repo="$root.activeRepo"
          :is-loading-repo="$root.isLoadingRepo"
          :is-refreshing="$root.isRefreshing"
          :has-active-repo="$root.hasActiveRepo"
          @update:activeRepo="val => $root.activeRepo = val"
          @load="$root.loadRepo"
          @refresh="$root.refresh"
        />
        <div class="lpm-row lpm-row-graph"><div id="lpm-graph" class="lpm-graph"></div></div>
        <lpm-tree
          ref="tree"
          :data="$root.data"
          :selected-packs="$root.selectedPacks"
          :prefixes="$root.prefixes"
          :renames="$root.renames"
          :check-title-exists="$root.checkTitleExists"
          @update:selectedPacks="val => $root.selectedPacks = val"
          @update:prefixes="val => $root.prefixes = val"
          @update:renames="val => $root.renames = val"
        />
        <div class="lpm-row lpm-row-actionbar">
          <div class="lpm-actionbar">
            <cdx-button :disabled="!$root.hasImportableSelection" @click="$root.confirmImport">Import Selected</cdx-button>
            <cdx-button :disabled="!$root.hasUpgradeableSelection" @click="$root.confirmUpdate">Upgrade Existing</cdx-button>
            <span class="lpm-action-info" style="margin-left: 1em;">
              {{ $root.activeRepo ? ('Active repo: ' + $root.activeRepo) : 'No repository selected.' }}
            </span>
          </div>
        </div>
        <lpm-messages :messages="$root.messages" @dismiss="$root.dismissMessage" />
        <lpm-dialogs
          :import-summary="$root.importSummary"
          :upgrade-summary="$root.upgradeSummary"
          :show-import-confirm="$root.showImportConfirm"
          :show-update-confirm="$root.showUpdateConfirm"
          @confirm-import="$root.doImport"
          @close-import="() => $root.showImportConfirm = false"
          @confirm-update="$root.doUpdate"
          @close-update="() => $root.showUpdateConfirm = false"
        />
      </div>
    `
  });

  // ------------------------------------------------------------
  // Mount Application
  // ------------------------------------------------------------
  const rootEl = document.querySelector(rootSelector);
  if (!rootEl) {
    console.error(`[LabkiPackManager] Root element not found: ${rootSelector}`);
    return;
  }
  app.mount(rootSelector);
}

// Ensure mount after DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => mountApp());
} else {
  mountApp();
}
