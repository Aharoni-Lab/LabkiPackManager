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

// External libs
import * as Vue from 'vue';
import * as Codex from '@wikimedia/codex';
import mermaid from 'mermaid';
import { buildMermaidFromGraph } from './utils/mermaidBuilder.js';
import { idToName, isPackNode } from './utils/nodeUtils.js';
import './styles/labkipackmanager.scss';
// ------------------------------------------------------------
// Mermaid configuration (guarded, on-demand)
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
    // swallow; we'll skip rendering if not ready
  }
  return false;
}

// Root UI components
import LpmToolbar from './ui/toolbar.vue';
import LpmTree from './ui/tree.vue';
import LpmMessages from './ui/messages.vue';
import LpmDialogs from './ui/dialogs.vue';

/**
 * Pretty-print a JSON object for debug or preformatted display.
 * @param {any} obj - Object to stringify.
 * @returns {string} Human-readable JSON or stringified fallback.
 */
function pretty(obj) {
  try {
    return JSON.stringify(obj, null, 2);
  } catch {
    return String(obj);
  }
}

/**
 * Mount the LabkiPackManager Vue application.
 * @param {string} [rootSelector='#labki-pack-manager-root']
 *   DOM selector for the root container.
 */
export function mountApp(rootSelector = '#labki-pack-manager-root') {
  const app = Vue.createApp({
    template: '<lpm-root ref="root" />',

    data() {
      return {
        ...createInitialState(),
        importSummary: [],
        upgradeSummary: [],
        messages: [],
        nextMsgId: 1,
      };
    },

    computed: {
      hasActiveRepo() {
        return !!this.activeRepo;
      },

      /** At least one selected pack is new (eligible for import). */
      hasImportableSelection() {
        const nodes = this.data?.hierarchy?.nodes || {};
        for (const [id, node] of Object.entries(nodes)) {
          if (!isPackNode(id, node)) continue;
          const name = idToName(id, node);
          if (this.selectedPacks[name] && node?.installStatus === 'new') return true;
        }
        return false;
      },

      /** At least one selected pack has a safe upgrade available. */
      hasUpgradeableSelection() {
        const nodes = this.data?.hierarchy?.nodes || {};
        for (const [id, node] of Object.entries(nodes)) {
          if (!isPackNode(id, node)) continue;
          const name = idToName(id, node);
          if (this.selectedPacks[name] && node?.installStatus === 'safe-upgrade') return true;
        }
        return false;
      }
    },

    methods: {
      pretty,

      /**
       * Initialize repository list from MediaWiki configuration.
       */
      async initRepos() {
        try {
          const repos = await fetchRepos();
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

      /**
       * Load manifest for the selected repository, using cache if available.
       */
      async loadRepo() {
        if (!this.activeRepo) return;

        this.isLoadingRepo = true;
        const repo = this.repos.find(r => r.url === this.activeRepo);

        try {
          let manifest = repo?.data;

          if (!manifest) {
            this.pushMessage(MSG_TYPES.INFO, `Loading manifest for ${this.activeRepo}...`);
            manifest = await fetchManifestFor(this.activeRepo, false);
          }

          // Backend returns wrapper { manifest, hierarchy, graph }
          this.data =
            manifest && (manifest.hierarchy || manifest.manifest)
              ? manifest
              : { hierarchy: null };

          if (repo) repo.data = manifest;

          // --- Merge installed info for upgrade detection ---
          try {
            const installed = await fetchInstalledFor(this.activeRepo);
            const installedByName = Object.create(null);
            for (const p of installed) {
              if (!p || !p.name) continue;
              installedByName[p.name] = p;
            }

            const nodes = this.data?.hierarchy?.nodes || {};
            const selectedPacks = { ...this.selectedPacks };
            for (const [id, node] of Object.entries(nodes)) {
              if (!isPackNode(id, node)) continue;
              const name = idToName(id, node);
              const installedInfo = installedByName[name];
              if (!installedInfo) {
                node.installedVersion = null;
                node.installStatus = 'new';
                node.isLocked = false;
                continue;
              }

              const curV = String(installedInfo.version || '0.0.0');
              const nextV = String(node.version || '0.0.0');
              const cmp = compareVersions(curV, nextV);
              const sameMajor = major(curV) === major(nextV);

              let status = 'already-installed';
              if (cmp === 0) status = 'already-installed';
              else if (sameMajor && cmp < 0) status = 'safe-upgrade';
              else if (sameMajor && cmp > 0) status = 'downgrade';
              else status = 'incompatible-update';

              node.installedVersion = curV;
              node.installStatus = status;
              node.isLocked = true; // imported packs are locked per requirements

              // Ensure these are selected and not deselectable
              selectedPacks[name] = true;
            }

            // Commit selected packs update (tree.vue will mark dependent packs disabled as needed)
            this.selectedPacks = selectedPacks;
          } catch (e) {
            // Non-fatal; continue without installed status
            console.warn('[LabkiPackManager] Failed to fetch installed packs:', e);
          }

          // Render Mermaid graph if available (after DOM updates settle)
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

      /**
       * Force-refresh manifest from the backend, bypassing cache.
       */
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

          // Render Mermaid graph if available (after DOM updates settle)
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

      /**
       * Lightweight API helper that checks if a page exists in the wiki.
       * Returns true if the page exists, false otherwise.
       * (Used by LpmTree for live collision detection.)
       */
      async checkTitleExists(title) {
        try {
          const api = new mw.Api();
          const res = await api.get({
            action: 'labkiPageExists', // planned API module
            format: 'json',
            formatversion: '2',
            title
          });
          // Response: { labkiPageExists: { exists: boolean } }
          return Boolean(res && res.labkiPageExists && res.labkiPageExists.exists);
        } catch (err) {
          console.warn('[LabkiPackManager] checkTitleExists failed:', err);
          return false;
        }
      },

      /**
       * Render a Mermaid graph into the #lpm-graph container.
       */
      async renderMermaidGraph(graph) {
        try {
          const code = buildMermaidFromGraph(graph);
          const container = document.getElementById('lpm-graph');
          if (!container) return;
          // Defer to ensure Vue finished patching
          await new Promise(r => requestAnimationFrame(() => r()));
          container.innerHTML = '';
          const el = document.createElement('div');
          el.className = 'mermaid';
          el.textContent = code; // Important: plain text, not innerHTML
          container.appendChild(el);
          if (!ensureMermaidConfigured()) return;
          const api = mermaid && (mermaid.default?.run ? mermaid.default : mermaid);
          if (api && typeof api.run === 'function') {
            await api.run({ nodes: [el] });
          }
        } catch (err) {
          console.error('[LabkiPackManager] Mermaid render failed:', err);
        }
      },

      /**
       * Push a message to the UI message stack.
       */
      pushMessage(type, text, timeout = 5000) {
        console.log('[pushMessage]', { type, text });
        const id = this.nextMsgId++;
        this.messages.push({ id, type, text });
        if (timeout) {
          setTimeout(() => this.dismissMessage(id), timeout);
        }
      },

      dismissMessage(id) {
        this.messages = this.messages.filter(m => m.id !== id);
      },

      confirmImport() {
        // Generate summary of what will be imported
        const root = this.$refs.root;
        const tree = root && root.$refs && root.$refs.tree;
        if (tree && this.activeRepo) {
          const summary = tree.exportSelectionSummary(this.activeRepo);
          // Only new packs (installStatus === 'new') will be imported
          const selectedNew = (summary?.packs || [])
            .filter(p => p.selected && (p.installStatus === 'new'));
          this.importSummary = selectedNew;
        } else {
          this.importSummary = [];
        }

        this.showImportConfirm = true;
      },

      confirmUpdate() {
        // Generate summary of what will be upgraded
        const root = this.$refs.root;
        const tree = root && root.$refs && root.$refs.tree;
        if (tree && this.activeRepo) {
          const summary = tree.exportSelectionSummary(this.activeRepo);
          // Only packs with safe-upgrade will be upgraded
          const selectedUpgrades = (summary?.packs || [])
            .filter(p => p.selected && (p.installStatus === 'safe-upgrade'));
          this.upgradeSummary = selectedUpgrades;
        } else {
          this.upgradeSummary = [];
        }

        this.showUpdateConfirm = true;
      },

      async doImport() {
        this.showImportConfirm = false;
        const root = this.$refs.root;
        const tree = root && root.$refs && root.$refs.tree;
        if (!tree || typeof tree.exportSelectionSummary !== 'function') {
          this.pushMessage(MSG_TYPES.ERROR, 'Tree component not ready. Try again.');
          return;
        }
        const payload = tree.exportSelectionSummary(this.activeRepo);
        console.log('[DEBUG] All packs:', payload.packs);
        
        // could instead send entire payload and have backend handle filtering. 
        // Basically just tell backend what packs are selected and what action to take.
        const selected = payload.packs.filter(p => p.selected && p.installStatus === 'new');
        console.log('[DEBUG] Selected packs (selected=true, installStatus=new):', selected);

        this.pushMessage(MSG_TYPES.INFO, 'Starting import…');

        try {
          // maybe should put in api.js?
          const api = new mw.Api();
          const res = await api.postWithToken('csrf', {
            action: 'labkiUpdate',
            format: 'json',
            formatversion: '2',
            actionType: 'importPack',
            contentRepoUrl: payload.repoUrl,
            packs: JSON.stringify(selected)   // new param to handle multi-pack
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
        const tree = root && root.$refs && root.$refs.tree;
        if (!tree || typeof tree.exportSelectionSummary !== 'function') {
          this.pushMessage(MSG_TYPES.ERROR, 'Tree component not ready. Try again.');
          return;
        }
        const payload = tree.exportSelectionSummary(this.activeRepo);
        console.log('[Upgrade payload]', payload);
        
        // Example: send to backend
        // const api = new mw.Api();
        // await api.post({ action: 'labkiPackUpgrade', format: 'json', payload: JSON.stringify(payload) });

        this.pushMessage(MSG_TYPES.SUCCESS, 'Upgrade triggered.');
      }
    },

    async mounted() {
      await this.initRepos();
    }
  });

  // ------------------------------------------------------------
  // Register Codex Components
  // ------------------------------------------------------------
  function toKebabFromCdx(name) {
    return name
      .replace(/^Cdx/, 'Cdx-')
      .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
      .toLowerCase();
  }
  for (const [name, comp] of Object.entries(Codex)) {
    if (name && name.startsWith('Cdx') && comp) {
      app.component(toKebabFromCdx(name), comp);
    }
  }

  // ------------------------------------------------------------
  // Root Composition: Define root layout component
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

        <div class="lpm-row lpm-row-graph">
          <div id="lpm-graph" class="lpm-graph"></div>
        </div>

        <lpm-tree
          ref="tree"
          :data="$root.data"
          :selected-packs="$root.selectedPacks"
          :selected-pages="$root.selectedPages"
          :prefixes="$root.prefixes"
          :renames="$root.renames"
          :check-title-exists="$root.checkTitleExists"
          @update:selectedPacks="val => $root.selectedPacks = val"
          @update:selectedPages="val => $root.selectedPages = val"
          @update:prefixes="val => $root.prefixes = val"
          @update:renames="val => $root.renames = val"
        />

        <div class="lpm-row lpm-row-actionbar">
          <div class="lpm-actionbar">
            <cdx-button :disabled="!$root.hasImportableSelection" @click="$root.confirmImport">Import Selected</cdx-button>
            <cdx-button :disabled="!$root.hasUpgradeableSelection" @click="$root.confirmUpdate">Upgrade Existing</cdx-button>
            <span class="lpm-action-info" style="margin-left: 1em;">
              {{ $root.activeRepo
                ? ('Active repo: ' + $root.activeRepo)
                : 'No repository selected.' }}
            </span>
          </div>
        </div>

        <lpm-messages
          :messages="$root.messages"
          @dismiss="$root.dismissMessage"
        />

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
