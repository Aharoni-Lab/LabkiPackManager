<template>
  <div class="lpm-row lpm-row-tree">
    <div class="lpm-tree" role="region" aria-label="Pack and page selection tree">
      <table class="lpm-tree-table" role="treegrid">
        <thead>
          <tr>
            <th>Name</th>
            <th>Select</th>
            <th>Prefix / Rename</th>
            <th>Final Name</th>
            <th>Status</th>
          </tr>
        </thead>

        <!-- Render hierarchy roots -->
        <tbody v-if="tree.length">
          <template v-for="root in tree" :key="root.id">
            <lpm-pack-node :node="root" :depth="0" />
          </template>
        </tbody>

        <!-- Fallback message -->
        <tbody v-else>
          <tr><td colspan="5"><em>No data loaded.</em></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
/**
 * LpmTree.vue (Optimized)
 * -----------------------
 * Recursive hierarchical pack/page selection UI.
 * Key optimizations:
 *  - Precomputed index maps for O(1) tree lookups
 *  - Cached dependency closure (fast BFS)
 *  - Batched collision reactivity to avoid unnecessary re-renders
 */
export default {
  name: 'LpmTree',

  props: {
    data: { type: Object, default: null },
    selectedPacks: { type: Object, required: true },
    prefixes: { type: Object, required: true },
    renames: { type: Object, required: true },
    checkTitleExists: { type: Function, default: null }
  },

  emits: ['update:selectedPacks', 'update:prefixes', 'update:renames'],

  data() {
    return {
      expanded: Object.create(null),
      collisions: Object.create(null),
      _debouncers: Object.create(null),
      _collisionVersion: Object.create(null),
      _collisionCache: Object.create(null),
      _pendingCommit: false
    };
  },

  computed: {
    nodes() {
      return this.data?.hierarchy?.nodes || {};
    },
    tree() {
      return this.data?.hierarchy?.tree || [];
    },

    /**
     * Precompute structural relationships: packId → child pack IDs
     * Enables O(1) nested pack lookup in updateSel().
     */
    treeIndex() {
      const map = Object.create(null);
      const walk = (node) => {
        if (node.type !== 'pack') return;
        const pid = node.id.startsWith('pack:') ? node.id : `pack:${node.id}`;
        map[pid] = [];
        for (const ch of node.children || []) {
          if (ch.type === 'pack') {
            const cid = ch.id.startsWith('pack:') ? ch.id : `pack:${ch.id}`;
            map[pid].push(cid);
            // recursive descent
            const chNode = this.nodes[cid];
            if (chNode) walk(chNode);
          }
        }
      };
      for (const root of this.tree) walk(root);
      return map;
    },
    /**
     * Precompute dependency closure per pack.
     * Builds a static dependency graph once for fast propagation.
     */
    dependencyMap() {
      const map = Object.create(null);
      for (const [id, node] of Object.entries(this.nodes)) {
        if (!id.startsWith('pack:')) continue;
        const name = id.slice(5);
        const seen = new Set();
        const queue = [...(node.depends_on || [])];
        while (queue.length) {
          const dep = queue.shift();
          const depName = dep.startsWith('pack:') ? dep.slice(5) : dep;
          if (seen.has(depName)) continue;
          seen.add(depName);
          const depNode = this.nodes[`pack:${depName}`];
          if (depNode?.depends_on) queue.push(...depNode.depends_on);
        }
        map[name] = [...seen];
      }
      return map;
    }
  },

  provide() {
    const self = this;
    return {
      lpmCtx: {
        get nodes() { return self.nodes; },
        get tree() { return self.tree; },
        get expanded() { return self.expanded; },
        get selectedPacks() { return self.selectedPacks; },
        get prefixes() { return self.prefixes; },
        get renames() { return self.renames; },
        get collisions() { return self.collisions; },
        get treeIndex() { return self.treeIndex; },
        get dependencyMap() { return self.dependencyMap; },

        updateSelectedPacks: (v) => self.$emit('update:selectedPacks', v),
        updatePrefixes: (v) => self.$emit('update:prefixes', v),
        updateRenames: (v) => self.$emit('update:renames', v),

        computeTitle: (p, pg) => self.finalPageTitle(p, pg),
        debounceCheck: (k, t, d) => self.debounceCheck(k, t, d),
        scheduleRecheck: () => self.scheduleCollisionRecheckForVisible(),
        sanitizeId: (s) => String(s).replace(/[^A-Za-z0-9_-]/g, '-')
      }
    };
  },

  methods: {
    pageKey(p, pg) { return `${p}::${pg}`; },
    splitNs(t) {
      const i = t.indexOf(':');
      return i > 0 ? { ns: t.slice(0, i), base: t.slice(i + 1) } : { ns: '', base: t };
    },
    finalPageTitle(pack, page) {
      const { ns, base } = this.splitNs(page);
      const pre = this.prefixes[pack] || '';
      const rename = (this.renames[this.pageKey(pack, page)] || '').trim();
      return `${ns ? ns + ':' : ''}${pre}${rename || base}`;
    },

    scheduleCollisionRecheckForVisible() {
      if (!this.checkTitleExists) return;
      for (const [pack, sel] of Object.entries(this.selectedPacks)) {
        if (!sel) continue;
        const pages = this.nodes[`pack:${pack}`]?.pages || [];
        for (const p of pages)
          this.debounceCheck(this.pageKey(pack, p), this.finalPageTitle(pack, p));
      }
    },

    asyncCheck(title) {
      if (!this.checkTitleExists) return Promise.resolve(false);
      if (title in this._collisionCache) return Promise.resolve(this._collisionCache[title]);
      return this.checkTitleExists(title).then(r => (this._collisionCache[title] = !!r));
    },

    debounceCheck(key, title, delay = 300) {
      if (!this.checkTitleExists) return;
      if (this._debouncers[key]) clearTimeout(this._debouncers[key]);
      const version = (this._collisionVersion[key] || 0) + 1;
      this._collisionVersion[key] = version;

      const run = async () => {
        const exists = await this.asyncCheck(title);
        if (this._collisionVersion[key] !== version) return;
        this.collisions[key] = !!exists;

        // batch collision commits
        if (!this._pendingCommit) {
          this._pendingCommit = true;
          Promise.resolve().then(() => {
            this.collisions = { ...this.collisions };
            this._pendingCommit = false;
          });
        }
      };
      delay <= 0 ? run() : (this._debouncers[key] = setTimeout(run, delay));
    }
  },

  components: {
    LpmPackNode: {
      name: 'lpm-pack-node',
      props: { node: { type: Object, required: true }, depth: { type: Number, default: 0 } },
      inject: ['lpmCtx'],

      computed: {
        packName() { return this.node.id.startsWith('pack:') ? this.node.id.slice(5) : this.node.id; },
        packId() { return `pack:${this.packName}`; },
        isOpen() { return !!this.lpmCtx.expanded[this.packId]; },
        isSelected() { return !!this.lpmCtx.selectedPacks[this.packName]; },
        pages() { return (this.node.children || []).filter(c => c.type === 'page').map(c => c.id); },
        childPacks() { return (this.node.children || []).filter(c => c.type === 'pack'); }
      },

      created() {
        if (!(this.packId in this.lpmCtx.expanded)) this.lpmCtx.expanded[this.packId] = true;
      },

      methods: {
        toggle() { this.lpmCtx.expanded[this.packId] = !this.isOpen; },

        computeTitleNow(p, pre, ren) {
          const i = p.indexOf(':'); const ns = i > 0 ? p.slice(0, i) : ''; const base = i > 0 ? p.slice(i + 1) : p;
          const tail = (ren || '').trim() || base;
          return `${ns ? ns + ':' : ''}${pre || ''}${tail}`;
        },

        updateSel(v) {
          const seen = new Set();
          const queue = [];

          // add currently selected packs
          for (const [name, selected] of Object.entries(this.lpmCtx.selectedPacks)) {
            if (selected) seen.add(name);
          }

          // normalize this pack name
          const rootId = this.packName;
          if (v) seen.add(rootId);
          else seen.delete(rootId);

          // BFS traversal over both dependency and structural edges
          queue.push(rootId);
          while (queue.length) {
            const cur = queue.shift();

            // hierarchical children
            const childIds = this.lpmCtx.treeIndex[`pack:${cur}`] || [];
            for (const cid of childIds) {
              const cname = cid.startsWith('pack:') ? cid.slice(5) : cid;
              if (!seen.has(cname)) {
                seen.add(cname);
                queue.push(cname);
              }
            }

            // dependency children
            const deps = this.lpmCtx.dependencyMap[cur] || [];
            for (const dep of deps) {
              if (!seen.has(dep)) {
                seen.add(dep);
                queue.push(dep);
              }
            }
          }

          // build next selection map
          const next = {};
          for (const n of seen) next[n] = true;
          this.lpmCtx.updateSelectedPacks(next);
          this.lpmCtx.scheduleRecheck();
        },

        updatePrefix(v) {
          const next = { ...this.lpmCtx.prefixes, [this.packName]: v };
          this.lpmCtx.updatePrefixes(next);
          for (const p of this.pages) {
            const k = `${this.packName}::${p}`;
            const ren = this.lpmCtx.renames[k] || '';
            const title = this.computeTitleNow(p, v, ren);
            this.lpmCtx.debounceCheck(k, title, 0);
          }
        },

        updateRename(p, v) {
          const k = `${this.packName}::${p}`;
          const next = { ...this.lpmCtx.renames, [k]: v };
          this.lpmCtx.updateRenames(next);
          const pre = this.lpmCtx.prefixes[this.packName] || '';
          const t = this.computeTitleNow(p, pre, v);
          this.lpmCtx.debounceCheck(k, t, 0);
        },

        final(p) { return this.lpmCtx.computeTitle(this.packName, p); },
        collide(p) { return !!this.lpmCtx.collisions[`${this.packName}::${p}`]; }
      },

      template: `
        <tr class="pack-row">
          <td class="lpm-indent" :style="{ paddingLeft: (depth * 1.75) + 'em' }">
            <button class="lpm-caret" @click="toggle">{{ isOpen ? '▼' : '▶' }}</button>
            <strong>{{ packName }}</strong>
          </td>
          <td>
            <cdx-checkbox :model-value="isSelected" @update:model-value="updateSel" />
          </td>
          <td>
            <cdx-text-input :model-value="lpmCtx.prefixes[packName]" placeholder="prefix"
              @update:model-value="updatePrefix" />
          </td>
          <td></td><td></td>
        </tr>

        <template v-if="isOpen">
          <tr v-for="p in pages" :key="packName + '::' + p"
              :class="['page-row', { 'lpm-row-ok': isSelected && !collide(p), 'lpm-row-warn': isSelected && collide(p) }]">
            <td class="lpm-indent lpm-cell-pad-left" :style="{ paddingLeft: ((depth + 1) * 1.75) + 'em' }">{{ p }}</td>
            <td></td>
            <td>
              <cdx-text-input :model-value="lpmCtx.renames[packName + '::' + p]" placeholder="rename"
                @update:model-value="v => updateRename(p, v)" />
            </td>
            <td>{{ final(p) }}</td>
            <td class="lpm-status-cell">
              <span v-if="isSelected && !collide(p)" class="lpm-status-included">✓</span>
              <span v-else-if="isSelected && collide(p)" class="lpm-status-warning">⚠</span>
            </td>
          </tr>

          <lpm-pack-node v-for="child in childPacks" :key="child.id" :node="child" :depth="depth + 1" />
        </template>
      `
    }
  }
};
</script>

<style scoped>
.lpm-tree { overflow-x: auto; }
.lpm-tree-table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
.lpm-tree-table th, .lpm-tree-table td {
  padding: 0.4rem 0.6rem;
  text-align: left;
  border-bottom: 1px solid var(--border-color, #ddd);
  vertical-align: middle;
}
.pack-row { background-color: hsl(0 0% 98%); }
.page-row { background-color: hsl(0 0% 100%); }
.lpm-indent { transition: padding-left 0.15s ease; }
.page-row .lpm-indent { font-style: italic; }
.lpm-caret { background: none; border: none; cursor: pointer; margin-right: 0.4rem; display: inline-flex; align-items: center; }
.lpm-status-cell { white-space: nowrap; }
.lpm-status-included { color: var(--included-color, #16a34a); margin-right: 6px; }
.lpm-status-warning { color: var(--warning-color, #d97706); }
.lpm-row-ok { background-color: hsla(120, 40%, 90%, 0.4); }
.lpm-row-warn { background-color: hsla(40, 90%, 90%, 0.5); }
</style>
