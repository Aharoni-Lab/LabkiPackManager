/**
 * LabkiPackManager – Phase 1 Skeleton (post–ApiLabkiManifest)
 *
 * This file defines the client-side framework for the LabkiPackManager UI.
 * It provides a minimal but stable foundation for later phases:
 *   1. State management
 *   2. API layer (data fetch)
 *   3. Renderers (tree, summary, graph)
 *   4. Actions (user interactions)
 *   5. Initialization and layout
 */

(function () {
    'use strict';
  
    /* -------------------------------------------------------------------------
     * 1. STATE
     * ------------------------------------------------------------------------- */
    const state = {
      data: null,           // latest manifest/hierarchy/graph payload
      repo: null,           // active repository URL or name
      selected: {},         // pack IDs currently selected
      expanded: {},         // tree expansion state
      planDraft: {},        // future rename/prefix map
      mermaidReady: false   // flag once Mermaid has loaded
    };
  
    /* -------------------------------------------------------------------------
     * 2. API LAYER
     * ------------------------------------------------------------------------- */
    const api = {
      /**
       * Fetch canonical manifest data from MediaWiki API.
       * @param {Object} opts - optional { refresh: boolean }
       * @returns {Promise<Object|null>}
       */
      async fetchManifest(opts = {}) {
        const base = mw.util.wikiScript('api');
        const params = new URLSearchParams({
          action: 'labkimanifest',
          format: 'json',
          formatversion: '2'
        });
  
        if (state.repo) params.set('repo', state.repo);
        if (opts.refresh) params.set('refresh', '1');
  
        const url = `${base}?${params.toString()}`;
        try {
          const res = await fetch(url, { credentials: 'same-origin' });
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          const json = await res.json();
          return json.labkiManifest || json;
        } catch (e) {
          console.error('Fetch error:', e);
          mw.notify(`Failed to load manifest: ${e}`, { type: 'error' });
          return null;
        }
      }
    };
  
    /* -------------------------------------------------------------------------
     * 3. RENDERERS
     * ------------------------------------------------------------------------- */
    const render = {
      /**
       * Render left-hand pack hierarchy.
       * Later: use state.data.hierarchy.tree from ApiLabkiManifest.
       */
      tree(container) {
        container.innerHTML = '<p>Pack hierarchy will appear here.</p>';
      },
  
      /**
       * Render right-hand summary panel.
       * Later: show repo info, counts, and plan status.
       */
      summary(container) {
        const meta = state.data?._meta || {};
        const repo = meta.repo || '(unknown repo)';
        const timestamp = meta.timestamp || '';
        container.innerHTML =
          `<p><strong>Repository:</strong> ${repo}</p>
           <p><strong>Fetched:</strong> ${timestamp}</p>
           <p>Summary view placeholder.</p>`;
      },
  
      /**
       * Render Mermaid graph (later phase).
       */
      graph(container) {
        container.innerHTML = '<p>Graph visualization will appear here.</p>';
      }
    };
  
    /* -------------------------------------------------------------------------
     * 4. ACTIONS
     * ------------------------------------------------------------------------- */
    const actions = {
      /**
       * Select or deselect a pack.
       * @param {string} id
       */
      togglePack(id) {
        state.selected[id] = !state.selected[id];
        console.log('togglePack', id, state.selected[id]);
        renderAll();
      },
  
      /**
       * Expand or collapse a tree node.
       * @param {string} id
       */
      toggleExpand(id) {
        state.expanded[id] = !state.expanded[id];
        renderAll();
      },
  
      /**
       * Refresh manifest data from the API.
       */
      async refresh() {
        console.log('Refreshing manifest...');
        const data = await api.fetchManifest({ refresh: true });
        if (data) state.data = data;
        renderAll();
      }
    };
  
    /* -------------------------------------------------------------------------
     * 5. INITIALIZATION
     * ------------------------------------------------------------------------- */
  
    /**
     * Entry point: mounts UI into #labki-pack-manager-root.
     */
    async function init() {
      const root = document.getElementById('labki-pack-manager-root');
      if (!root) return;
  
      root.innerHTML = '<p>Loading manifest...</p>';
  
      // Initial fetch
      state.data = await api.fetchManifest();
      renderAll();
    }
  
    /**
     * Render full two-column layout and call sub-renders.
     */
    function renderAll() {
      const root = document.getElementById('labki-pack-manager-root');
      if (!root) return;
  
      root.innerHTML = '';
  
      const layout = document.createElement('div');
      layout.className = 'lpm-layout';
  
      const left = document.createElement('div');
      left.className = 'lpm-left';
  
      const right = document.createElement('div');
      right.className = 'lpm-right';
  
      layout.appendChild(left);
      layout.appendChild(right);
      root.appendChild(layout);
  
      // Modular renderers
      render.tree(left);
      render.summary(right);
      render.graph(right);
    }
  
    // Bootstrap once DOM is ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  
  })();
  