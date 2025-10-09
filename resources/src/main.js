/**
 * LabkiPackManager – Main Entry Point
 * ------------------------------------------------------------
 * Initializes the Vue 3 + Codex frontend for the LabkiPackManager
 * MediaWiki extension. Handles repository initialization, manifest
 * loading, user feedback messages, and high-level UI orchestration.
 */

import { createInitialState } from './state.js';
import { fetchRepos, fetchManifestFor } from './api.js';
import { MSG_TYPES } from './constants.js';

// External libs
import * as Vue from 'vue';
import * as Codex from '@wikimedia/codex';
import './styles/labkipackmanager.scss';

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
    /**
     * Root application template – renders composed components.
     */
    template: '<lpm-root />',

    /**
     * Application state (see state.js for structure)
     */
    data() {
      return createInitialState();
    },

    computed: {
      hasActiveRepo() {
        return !!this.activeRepo;
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

          // If not cached, fetch from API
          if (!manifest) {
            this.pushMessage(MSG_TYPES.INFO, `Loading manifest for ${this.activeRepo}...`);
            manifest = await fetchManifestFor(this.activeRepo, false);
          }

          // Backend returns wrapper { manifest, hierarchy, graph } → unwrap here
          this.data = manifest && manifest.manifest ? manifest.manifest : manifest;
          if (repo) repo.data = manifest;

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
          this.data = data && data.manifest ? data.manifest : data;
          const repo = this.repos.find(r => r.url === this.activeRepo);
          if (repo) repo.data = data;

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

      /** Generate a unique key for pack+page combos. */
      pageKey(pack, page) {
        return `${pack.name}::${page.name}`;
      },

      /** Compute the final display name for a page after rename override. */
      finalName(pack, page) {
        const key = this.pageKey(pack, page);
        const rename = this.renames[key];
        return rename?.trim() || page.name;
      },

      /**
       * Push a message to the UI message stack.
       * @param {string} type - One of MSG_TYPES
       * @param {string} text - Display text
       * @param {number} [timeout=5000] - Auto-dismiss timeout (ms)
       */
      pushMessage(type, text, timeout = 5000) {
        const id = this.nextMsgId++;
        this.messages.push({ id, type, text });
        if (timeout) {
          setTimeout(() => this.dismissMessage(id), timeout);
        }
      },

      /** Remove a message by ID. */
      dismissMessage(id) {
        this.messages = this.messages.filter(m => m.id !== id);
      },

      /** Show import confirmation dialog. */
      confirmImport() {
        this.showImportConfirm = true;
      },

      /** Show update confirmation dialog. */
      confirmUpdate() {
        this.showUpdateConfirm = true;
      },

      /** Execute import operation (placeholder). */
      doImport() {
        this.showImportConfirm = false;
        this.pushMessage(MSG_TYPES.SUCCESS, 'Import triggered.');
      },

      /** Execute update operation (placeholder). */
      doUpdate() {
        this.showUpdateConfirm = false;
        this.pushMessage(MSG_TYPES.SUCCESS, 'Update triggered.');
      }
    },

    /**
     * On mount: initialize available repositories.
     */
    async mounted() {
      await this.initRepos();
    }
  });

  // ------------------------------------------------------------
  // Register Codex Components
  // ------------------------------------------------------------
  function toKebabFromCdx(name) {
    // CdxTextInput -> cdx-text-input, CdxButton -> cdx-button
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
          <div id="lpm-graph" class="lpm-graph">
            <pre v-if="$root.data?.graph">{{ $root.pretty($root.data.graph) }}</pre>
            <p v-else>Graph visualization will appear here.</p>
          </div>
        </div>

        <lpm-tree
          :data="$root.data"
          :selected-packs="$root.selectedPacks"
          :selected-pages="$root.selectedPages"
          :prefixes="$root.prefixes"
          :renames="$root.renames"
          @update:selectedPacks="val => $root.selectedPacks = val"
          @update:selectedPages="val => $root.selectedPages = val"
          @update:prefixes="val => $root.prefixes = val"
          @update:renames="val => $root.renames = val"
        />

        <div class="lpm-row lpm-row-actionbar">
          <div class="lpm-actionbar">
            <cdx-button @click="$root.confirmImport">Import Selected</cdx-button>
            <cdx-button @click="$root.confirmUpdate">Update Existing</cdx-button>
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
