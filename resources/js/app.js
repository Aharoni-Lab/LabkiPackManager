/**
 * LabkiPackManager – Phase 1 Framework (Four-Row Layout, Cached Manifest Optimization)
 *
 * Layout:
 *   Row 1: Top toolbar – repo selector and load button
 *   Row 2: Mermaid graph display
 *   Row 3: Tree – nested packs/pages table
 *   Row 4: Bottom action bar
 */

(function () {
  'use strict';

  const Vue = require('vue');
  const Codex = require('@wikimedia/codex');

  function pretty(obj) {
    try { return JSON.stringify(obj, null, 2); } catch (e) { return String(obj); }
  }

  const app = Vue.createApp({
    data() {
      return {
        // Core state
        data: null,
        activeRepo: null,
        repos: [], // [{ url, name, data }]

        // UI helpers
        repoMenuItems: [], // Codex select menu items
        messages: [], // { id, type, text }
        nextMsgId: 1,

        // Dialog state
        showImportConfirm: false,
        showUpdateConfirm: false,

        // Tree interaction state
        selectedPacks: {},
        selectedPages: {},
        prefixes: {},
        renames: {}
      };
    },
    computed: {
      hasActiveRepo() { return !!this.activeRepo; }
    },
    methods: {
      pretty,

    async fetchRepos() {
        const cfg = (typeof mw !== 'undefined' && mw.config)
          ? (mw.config.get('LabkiContentSources') || mw.config.get('wgLabkiContentSources'))
          : null;
      const urls = Array.isArray(cfg) ? cfg : [];
      if (urls.length === 0) {
        console.warn('No LabkiContentSources defined in LocalSettings.php');
          this.repos = [];
          this.repoOptions = [];
        return;
      }
      const results = await Promise.allSettled(
          urls.map((u) => this.fetchManifestFor(u, false))
      );
        this.repos = urls.map((u, i) => {
        const r = results[i];
        if (r.status === 'fulfilled' && r.value) {
          const data = r.value;
            let name = data?._meta?.repoName || data?.manifest?.name || u.split('/').slice(-2).join('/');
          return { url: u, name, data };
        } else {
          console.warn(`Repo ${u} failed to load:`, r.reason);
          return { url: u, name: `${u} (unavailable)` };
        }
      });
        this.repoMenuItems = this.repos.map(r => ({ label: r.name || r.url, value: r.url }));
      },

    async fetchManifestFor(repoUrl, refresh = false) {
      const base = mw.util.wikiScript('api');
      const params = new URLSearchParams({
        action: 'labkiManifest',
        format: 'json',
        formatversion: '2',
        repo: repoUrl
      });
      if (refresh) params.set('refresh', '1');
      const url = `${base}?${params.toString()}`;
      try {
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        return json.labkiManifest || json;
      } catch (e) {
        console.error(`Error fetching manifest for ${repoUrl}:`, e);
        mw.notify(`Failed to fetch manifest for ${repoUrl}`, { type: 'error' });
        throw e;
      }
      },

      async loadRepo() {
        if (!this.activeRepo) return;
        const repo = this.repos.find(r => r.url === this.activeRepo);
        if (repo && repo.data) {
          this.data = repo.data;
          this.pushMessage('success', 'Manifest loaded from cache.');
          return;
        }
        this.pushMessage('info', `Loading manifest for ${this.activeRepo}...`);
        try {
          const data = await this.fetchManifestFor(this.activeRepo, false);
          if (data) {
            this.data = data;
            if (repo) repo.data = data;
            this.pushMessage('success', 'Manifest loaded.');
          }
        } catch (e) {
          this.pushMessage('error', `Failed to load ${this.activeRepo}`);
        }
      },

      async refresh() {
        if (!this.activeRepo) {
          this.pushMessage('warning', 'Select a repository first.');
          return;
        }
        this.pushMessage('info', `Refreshing manifest for ${this.activeRepo}...`);
        try {
          const data = await this.fetchManifestFor(this.activeRepo, true);
          if (data) {
            this.data = data;
            const repo = this.repos.find(r => r.url === this.activeRepo);
            if (repo) repo.data = data;
            this.pushMessage('success', 'Manifest refreshed.');
          }
        } catch (e) {
          this.pushMessage('error', `Failed to refresh ${this.activeRepo}`);
        }
      },

      pushMessage(type, text) {
        const id = this.nextMsgId++;
        this.messages.push({ id, type, text });
      },
      dismissMessage(id) {
        this.messages = this.messages.filter(m => m.id !== id);
      },

      confirmImport() { this.showImportConfirm = true; },
      confirmUpdate() { this.showUpdateConfirm = true; },
      doImport() {
        this.showImportConfirm = false;
        this.pushMessage('success', 'Import triggered.');
      },
      doUpdate() {
        this.showUpdateConfirm = false;
        this.pushMessage('success', 'Update triggered.');
      },

      pageKey(pack, page) { return `${pack.name}::${page.name}`; },
      finalName(pack, page) {
        const key = this.pageKey(pack, page);
        const rename = this.renames[key];
        return rename && rename.trim() ? rename.trim() : page.name;
      }
    },
    async mounted() {
      await this.fetchRepos();
    },
    template: `
      <div class="lpm-root">
        <div class="lpm-row lpm-row-toolbar">
          <cdx-select
            id="lpm-repo-select"
            aria-label="Repository"
            :menu-items="repoMenuItems"
            :selected="activeRepo"
            @update:selected="activeRepo = $event"
            placeholder="Select a content repository…"
          />
          <cdx-button :disabled="!hasActiveRepo" @click="loadRepo">Load</cdx-button>
          <cdx-button :disabled="!hasActiveRepo" @click="refresh">Refresh</cdx-button>
        </div>

        <div class="lpm-row lpm-row-graph">
          <div id="lpm-graph" class="lpm-graph">
            <pre v-if="data && data.graph">{{ pretty(data.graph) }}</pre>
            <p v-else>Graph visualization will appear here.</p>
          </div>
        </div>

        <div class="lpm-row lpm-row-tree">
          <div class="lpm-tree">
        <table class="lpm-tree-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Select</th>
              <th>Prefix / Rename</th>
              <th>Final Name</th>
            </tr>
          </thead>
              <tbody id="lpm-tree-body" v-if="data && data.hierarchy && data.hierarchy.packs && data.hierarchy.packs.length">
                <template v-for="pack in data.hierarchy.packs" :key="pack.name">
                  <tr class="pack-row">
                    <td><strong>{{ pack.name }}</strong></td>
                    <td><cdx-checkbox v-model="selectedPacks[pack.name]" /></td>
                    <td><cdx-text-input v-model="prefixes[pack.name]" placeholder="prefix" /></td>
                    <td></td>
                  </tr>
                  <tr class="page-row" v-for="page in (pack.pages || [])" :key="page.name">
                    <td style="padding-left: 2em;">{{ page.name }}</td>
                    <td><cdx-checkbox v-model="selectedPages[pageKey(pack, page)]" /></td>
                    <td><cdx-text-input v-model="renames[pageKey(pack, page)]" placeholder="rename" /></td>
                    <td>{{ finalName(pack, page) }}</td>
                  </tr>
                </template>
                <tr v-if="!data || !data.hierarchy || !data.hierarchy.packs || !data.hierarchy.packs.length">
                  <td colspan="4"><em>No data loaded.</em></td>
                </tr>
              </tbody>
              <tbody v-else>
                <tr>
                  <td colspan="4"><em>No data loaded.</em></td>
                </tr>
              </tbody>
        </table>
          </div>
        </div>

        <div class="lpm-row lpm-row-actionbar">
          <div class="lpm-actionbar">
            <cdx-button @click="confirmImport">Import Selected</cdx-button>
            <cdx-button @click="confirmUpdate">Update Existing</cdx-button>
            <span class="lpm-action-info" style="margin-left: 1em;">
              {{ activeRepo ? ('Active repo: ' + activeRepo) : 'No repository selected.' }}
            </span>
          </div>
        </div>

        <div class="lpm-row lpm-row-messages" v-if="messages.length">
          <div class="lpm-messages">
            <cdx-message
              v-for="m in messages"
              :key="m.id"
              :type="m.type"
              dismissible
              @dismiss="dismissMessage(m.id)"
            >
              {{ m.text }}
            </cdx-message>
          </div>
        </div>

        <cdx-dialog
          v-if="showImportConfirm"
          title="Confirm Import"
          @close="showImportConfirm = false"
        >
          <p>Import selected packs and pages?</p>
          <template #footer>
            <cdx-button @click="doImport">Confirm</cdx-button>
            <cdx-button @click="showImportConfirm = false">Cancel</cdx-button>
          </template>
        </cdx-dialog>

        <cdx-dialog
          v-if="showUpdateConfirm"
          title="Confirm Update"
          @close="showUpdateConfirm = false"
        >
          <p>Update existing pages from the selected repository?</p>
          <template #footer>
            <cdx-button @click="doUpdate">Confirm</cdx-button>
            <cdx-button @click="showUpdateConfirm = false">Cancel</cdx-button>
          </template>
        </cdx-dialog>
      </div>
    `
  });

  app.component('cdx-select', Codex.CdxSelect);
  app.component('cdx-button', Codex.CdxButton);
  app.component('cdx-checkbox', Codex.CdxCheckbox);
  app.component('cdx-text-input', Codex.CdxTextInput);
  app.component('cdx-message', Codex.CdxMessage);
  app.component('cdx-dialog', Codex.CdxDialog);

  function init() {
    const root = document.getElementById('labki-pack-manager-root');
    if (!root) return;
    app.mount('#labki-pack-manager-root');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
