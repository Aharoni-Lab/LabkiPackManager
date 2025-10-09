import { createInitialState } from './state.js';
import { fetchRepos, fetchManifestFor, normalizeManifest } from './api.js';
import { MSG_TYPES } from './constants.js';

const Vue = require('vue');
const Codex = require('@wikimedia/codex');
import LpmToolbar from './ui/toolbar.vue';
import LpmTree from './ui/tree.vue';
import LpmMessages from './ui/messages.vue';
import LpmDialogs from './ui/dialogs.vue';

function pretty(obj) {
  try { return JSON.stringify(obj, null, 2); } catch (e) { return String(obj); }
}

export function mountApp(rootSelector = '#labki-pack-manager-root') {
  const app = Vue.createApp({
    data() { return createInitialState(); },
    computed: {
      hasActiveRepo() { return !!this.activeRepo; }
    },
    methods: {
      pretty,
      async initRepos() {
        const repos = await fetchRepos();
        this.repos = repos;
        this.repoMenuItems = repos.map(r => ({ label: r.name || r.url, value: r.url }));
      },
      async loadRepo() {
        if (!this.activeRepo) return;
        this.isLoadingRepo = true;
        const repo = this.repos.find(r => r.url === this.activeRepo);
        if (repo && repo.data) {
          if (!repo.data?._meta?.schemaVersion) {
            this.pushMessage(MSG_TYPES.ERROR, 'Manifest missing schema version.');
            this.isLoadingRepo = false; return;
          }
          try { this.data = normalizeManifest(repo.data); }
          catch (e) { this.pushMessage(MSG_TYPES.ERROR, e.message); this.isLoadingRepo = false; return; }
          this.pushMessage(MSG_TYPES.SUCCESS, mw.msg('labkipackmanager-load-success') || 'Manifest loaded.');
          this.isLoadingRepo = false;
          return;
        }
        this.pushMessage('info', `Loading manifest for ${this.activeRepo}...`);
        try {
          const data = await fetchManifestFor(this.activeRepo, false);
          if (data) {
            if (!data?._meta?.schemaVersion) {
              this.pushMessage(MSG_TYPES.ERROR, 'Manifest missing schema version.');
              return;
            }
            this.data = normalizeManifest(data);
            if (repo) repo.data = data;
            this.pushMessage(MSG_TYPES.SUCCESS, mw.msg('labkipackmanager-load-success') || 'Manifest loaded.');
          }
        } catch (e) {
          this.pushMessage(MSG_TYPES.ERROR, `Failed to load ${this.activeRepo}`);
        } finally { this.isLoadingRepo = false; }
      },
      async refresh() {
        if (!this.activeRepo) {
          this.pushMessage('warning', 'Select a repository first.');
          return;
        }
        this.isRefreshing = true;
        this.pushMessage('info', `Refreshing manifest for ${this.activeRepo}...`);
        try {
          const data = await fetchManifestFor(this.activeRepo, true);
          if (data) {
            if (!data?._meta?.schemaVersion) {
              this.pushMessage(MSG_TYPES.ERROR, 'Manifest missing schema version.');
              return;
            }
            this.data = normalizeManifest(data);
            const repo = this.repos.find(r => r.url === this.activeRepo);
            if (repo) repo.data = data;
            this.pushMessage(MSG_TYPES.SUCCESS, mw.msg('labkipackmanager-refresh-success') || 'Manifest refreshed.');
          }
        } catch (e) {
          this.pushMessage(MSG_TYPES.ERROR, `Failed to refresh ${this.activeRepo}`);
        } finally { this.isRefreshing = false; }
      },
      pageKey(pack, page) { return `${pack.name}::${page.name}`; },
      finalName(pack, page) {
        const key = this.pageKey(pack, page);
        const rename = this.renames[key];
        return rename && rename.trim() ? rename.trim() : page.name;
      },
      pushMessage(type, text, timeout = 5000) {
        const id = this.nextMsgId++;
        this.messages.push({ id, type, text });
        if (timeout) setTimeout(() => this.dismissMessage(id), timeout);
      },
      dismissMessage(id) {
        this.messages = this.messages.filter(m => m.id !== id);
      },
      confirmImport() { this.showImportConfirm = true; },
      confirmUpdate() { this.showUpdateConfirm = true; },
      doImport() { this.showImportConfirm = false; this.pushMessage('success', 'Import triggered.'); },
      doUpdate() { this.showUpdateConfirm = false; this.pushMessage('success', 'Update triggered.'); }
    },
    async mounted() { await this.initRepos(); }
  });

  app.component('cdx-select', Codex.CdxSelect);
  app.component('cdx-button', Codex.CdxButton);
  app.component('cdx-checkbox', Codex.CdxCheckbox);
  app.component('cdx-text-input', Codex.CdxTextInput);
  app.component('cdx-message', Codex.CdxMessage);
  app.component('cdx-dialog', Codex.CdxDialog);

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
            <pre v-if="$root.data && $root.data.graph">{{ $root.pretty($root.data.graph) }}</pre>
            <p v-else>Graph visualization will appear here.</p>
          </div>
        </div>

        <lpm-tree
          :data="$root.data"
          :selected-packs="$root.selectedPacks"
          :selected-pages="$root.selectedPages"
          :prefixes="$root.prefixes"
          :renames="$root.renames"
        />

        <div class="lpm-row lpm-row-actionbar">
          <div class="lpm-actionbar">
            <cdx-button @click="$root.confirmImport">Import Selected</cdx-button>
            <cdx-button @click="$root.confirmUpdate">Update Existing</cdx-button>
            <span class="lpm-action-info" style="margin-left: 1em;">
              {{ $root.activeRepo ? ('Active repo: ' + $root.activeRepo) : 'No repository selected.' }}
            </span>
          </div>
        </div>

        <lpm-messages :messages="$root.messages" @dismiss="$root.dismissMessage" />

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

  app.mount(rootSelector).$forceUpdate?.();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => mountApp());
} else {
  mountApp();
}


