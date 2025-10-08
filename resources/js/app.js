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

  /* -------------------------------------------------------------------------
   * 1. STATE
   * ------------------------------------------------------------------------- */
  const state = {
    data: null,           // manifest + hierarchy data for current repo
    repo: null,           // active repo name/URL
    repos: [],            // [{ url, name, data }]
    selected: {},         // selected pack/page IDs
    expanded: {},         // tree expansion
    planDraft: {},        // rename/prefix plan
    mermaidReady: false   // flag once mermaid.js has loaded
  };

  /* -------------------------------------------------------------------------
   * 2. API LAYER
   * ------------------------------------------------------------------------- */
  const api = {
    /**
     * Fetch all configured repos and warm their cached manifests.
     * Uses $wgLabkiContentSources from LocalSettings.php.
     */
    async fetchRepos() {
      const cfg =
        (typeof mw !== 'undefined' && mw.config)
          ? (mw.config.get('LabkiContentSources') || mw.config.get('wgLabkiContentSources'))
          : null;

      const urls = Array.isArray(cfg) ? cfg : [];

      if (urls.length === 0) {
        console.warn('No LabkiContentSources defined in LocalSettings.php');
        state.repos = [];
        return;
      }

      // Fetch all manifests in parallel
      const results = await Promise.allSettled(
        urls.map((u) => api.fetchManifestFor(u, false))
      );

      state.repos = urls.map((u, i) => {
        const r = results[i];
        if (r.status === 'fulfilled' && r.value) {
          const data = r.value;
          let name =
            data?._meta?.repoName ||
            data?.manifest?.name ||
            u.split('/').slice(-2).join('/'); // fallback: last two path parts
          return { url: u, name, data };
        } else {
          console.warn(`Repo ${u} failed to load:`, r.reason);
          return { url: u, name: `${u} (unavailable)` };
        }
      });
    },

    /**
     * Fetch manifest data for a specific repository.
     * This calls ApiLabkiManifest (cached or refreshed).
     *
     * @param {string} repoUrl
     * @param {boolean} refresh
     * @returns {Promise<Object>}
     */
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
    }
  };

  /* -------------------------------------------------------------------------
   * 3. RENDERERS
   * ------------------------------------------------------------------------- */
  const render = {
    /**
     * Row 1 – Top toolbar with repo selector.
     */
    toolbar(container) {
      const wrapper = document.createElement('div');
      wrapper.className = 'lpm-toolbar';
    
      const select = document.createElement('select');
      select.id = 'lpm-repo-select';
      select.className = 'lpm-repo-select';
    
      // Default prompt option
      const defaultOpt = document.createElement('option');
      defaultOpt.value = '';
      defaultOpt.textContent = 'Select a content repository…';
      select.appendChild(defaultOpt);
    
      // Populate options
      state.repos.forEach(repo => {
        const opt = document.createElement('option');
        opt.value = repo.url;
        opt.textContent = repo.name || repo.url;
        select.appendChild(opt);
      });
    
      // Restore current selection if already chosen
      if (state.repo) select.value = state.repo;
    
      // When user picks a repo (only updates state)
      select.addEventListener('change', () => {
        state.repo = select.value || null;
      });
    
      // --- Buttons -------------------------------------------------------------
    
      const loadBtn = document.createElement('button');
      loadBtn.textContent = 'Load';
      loadBtn.addEventListener('click', actions.loadRepo);
    
      const refreshBtn = document.createElement('button');
      refreshBtn.textContent = 'Refresh';
      refreshBtn.addEventListener('click', actions.refresh);
    
      // Append all
      wrapper.append(select, loadBtn, refreshBtn);
      container.appendChild(wrapper);
    },

    
    /**
     * Row 2 – Mermaid graph display (horizontal).
     */
    graph(container) {
      const graph = document.createElement('div');
      graph.id = 'lpm-graph';
      graph.className = 'lpm-graph';
      if (state.data?.graph) {
        graph.innerHTML = `<pre>${JSON.stringify(state.data.graph, null, 2)}</pre>`;
      } else {
        graph.innerHTML = '<p>Graph visualization will appear here.</p>';
      }
      container.appendChild(graph);
    },

    /**
     * Row 3 – Tree: nested pack/page table.
     */
    tree(container) {
      const tree = document.createElement('div');
      tree.className = 'lpm-tree';

      let bodyContent = '<tr><td colspan="4"><em>No data loaded.</em></td></tr>';

      if (state.data?.hierarchy?.packs) {
        const rows = [];
        for (const pack of state.data.hierarchy.packs) {
          rows.push(`
            <tr class="pack-row">
              <td><strong>${pack.name}</strong></td>
              <td><input type="checkbox"></td>
              <td><input type="text" placeholder="prefix"></td>
              <td></td>
            </tr>
          `);
          if (pack.pages) {
            for (const page of pack.pages) {
              rows.push(`
                <tr class="page-row">
                  <td style="padding-left: 2em;">${page.name}</td>
                  <td><input type="checkbox"></td>
                  <td><input type="text" placeholder="rename"></td>
                  <td>${page.name}</td>
                </tr>
              `);
            }
          }
        }
        bodyContent = rows.join('');
      }

      tree.innerHTML = `
        <table class="lpm-tree-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Select</th>
              <th>Prefix / Rename</th>
              <th>Final Name</th>
            </tr>
          </thead>
          <tbody id="lpm-tree-body">${bodyContent}</tbody>
        </table>
      `;
      container.appendChild(tree);
    },

    /**
     * Row 4 – Bottom action bar.
     */
    actionBar(container) {
      const bar = document.createElement('div');
      bar.className = 'lpm-actionbar';

      const importBtn = document.createElement('button');
      importBtn.textContent = 'Import Selected';
      importBtn.addEventListener('click', () => {
        mw.notify('Import triggered.');
      });

      const updateBtn = document.createElement('button');
      updateBtn.textContent = 'Update Existing';
      updateBtn.addEventListener('click', () => {
        mw.notify('Update triggered.');
      });

      const info = document.createElement('span');
      info.className = 'lpm-action-info';
      info.textContent = state.repo
        ? `Active repo: ${state.repo}`
        : 'No repository selected.';

      bar.append(importBtn, updateBtn, info);
      container.appendChild(bar);
    }
  };

  /* -------------------------------------------------------------------------
   * 4. ACTIONS
   * ------------------------------------------------------------------------- */
  const actions = {
    /**
     * Load a repo from already-fetched data.
     */
    async loadRepo() {
      if (!state.repo) return;

      const repo = state.repos.find(r => r.url === state.repo);
      if (repo?.data) {
        state.data = repo.data;
        renderAll();
      } else {
        // Fallback: fetch if data not cached
        mw.notify(`Loading manifest for ${state.repo}...`);
        try {
          const data = await api.fetchManifestFor(state.repo, false);
          if (data) {
            state.data = data;
            repo.data = data;
          }
        } catch (e) {
          mw.notify(`Failed to load ${state.repo}`, { type: 'error' });
        }
        renderAll();
      }
    },

    /**
     * Force-refresh manifest for current repo.
     */
    async refresh() {
      if (!state.repo) {
        mw.notify('Select a repository first.', { type: 'warn' });
        return;
      }

      mw.notify(`Refreshing manifest for ${state.repo}...`);
      try {
        const data = await api.fetchManifestFor(state.repo, true);
        if (data) {
          state.data = data;
          const repo = state.repos.find(r => r.url === state.repo);
          if (repo) repo.data = data;
          mw.notify('Manifest refreshed.', { type: 'info' });
        }
      } catch (e) {
        mw.notify(`Failed to refresh ${state.repo}`, { type: 'error' });
      }

      renderAll();
    }
  };

  /* -------------------------------------------------------------------------
   * 5. INITIALIZATION
   * ------------------------------------------------------------------------- */
  async function init() {
    const root = document.getElementById('labki-pack-manager-root');
    if (!root) return;
    root.innerHTML = '<p>Loading repositories…</p>';

    await api.fetchRepos();
    renderAll();
  }

  /**
   * Main layout builder (four rows).
   */
  function renderAll() {
    const root = document.getElementById('labki-pack-manager-root');
    if (!root) return;
    root.innerHTML = '';

    const row1 = document.createElement('div');
    row1.className = 'lpm-row lpm-row-toolbar';
    render.toolbar(row1);
    root.appendChild(row1);

    const row2 = document.createElement('div');
    row2.className = 'lpm-row lpm-row-graph';
    render.graph(row2);
    root.appendChild(row2);

    const row3 = document.createElement('div');
    row3.className = 'lpm-row lpm-row-tree';
    render.tree(row3);
    root.appendChild(row3);

    const row4 = document.createElement('div');
    row4.className = 'lpm-row lpm-row-actionbar';
    render.actionBar(row4);
    root.appendChild(row4);
  }

  // Bootstrap when DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
