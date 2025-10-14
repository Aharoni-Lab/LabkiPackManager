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
        <template v-if="tree.length">
          <lpm-pack-node v-for="root in tree" :key="root.id" :node="root" :depth="0" />
        </template>

        <!-- Empty state -->
        <tbody v-else>
          <tr><td colspan="5"><em>No data loaded.</em></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import { idToName, isPackNode } from '../utils/nodeUtils.js';
export default {
  name: 'LpmTree',

  /* ------------------------------------------------------------------------
   *  COMPONENTS
   * ---------------------------------------------------------------------- */
  components: {
    LpmPackNode: {
      name: 'lpm-pack-node',
      inject: ['lpmCtx'],
      props: {
        node: { type: Object, required: true },
        depth: { type: Number, default: 0 }
      },

      computed: {
        packName() {
          if (typeof this.node.name === 'string' && this.node.name) return this.node.name;
          const i = this.node.id.indexOf(':');
          return i > 0 ? this.node.id.slice(i + 1) : this.node.id;
        },
        packId() {
          return this.node.id.startsWith('pack:') ? this.node.id : `pack:${this.packName}`;
        },
        packLabelId() {
          return `pack-label-${this.lpmCtx.sanitizeId(this.packName)}`;
        },
        isOpen() {
          return !!this.lpmCtx.expanded[this.packId];
        },
        isSelected() {
          return !!this.lpmCtx.selectedPacks[this.packName];
        },

        // --- Derived node data helpers ---
        pages() {
          return this.resolvePages();
        },
        childPacks() {
          return this.resolveChildPacks();
        },
        flatPack() {
          return this.lpmCtx.nodes[this.packId] || this.node;
        }
      },

      created() {
        // Default to expanded
        if (!(this.packId in this.lpmCtx.expanded)) this.lpmCtx.expanded[this.packId] = true;
      },

      /* --------------------------------------------------------------------
       *  METHODS
       * ------------------------------------------------------------------ */
      methods: {
        // ---- Small utility helpers ----
        getNode(id) {
          const normalized = id.startsWith('pack:') ? id : `pack:${id}`;
          return this.lpmCtx.nodes[normalized];
        },

        resolvePages() {
          const node = this.getNode(this.packName);
          if (Array.isArray(node?.pages)) return node.pages;
          return (this.node.children || [])
            .filter(c => c.type === 'page')
            .map(c => c.id);
        },

        resolveChildPacks() {
          const node = this.getNode(this.packName);
          const deps = Array.isArray(node?.depends_on) ? node.depends_on : [];
          const packs = deps.map(d => this.getNode(d)).filter(Boolean);
          if (packs.length) return packs;

          // fallback to children if no depends_on
          return (this.node.children || [])
            .filter(c => c.type === 'pack')
            .map(c => this.getNode(c.id) || c);
        },

        toggle() {
          this.lpmCtx.expanded[this.packId] = !this.isOpen;
        },

        computeTitleNow(page, prefix, rename) {
          const i = page.indexOf(':');
          const ns = i > 0 ? page.slice(0, i) : '';
          const base = i > 0 ? page.slice(i + 1) : page;
          const tail = (rename || '').trim() || base;
          return `${ns ? ns + ':' : ''}${prefix || ''}${tail}`;
        },

        updateSel(value) {
          this.lpmCtx.togglePackExplicit(this.packName, value);
        },

        updatePrefix(value) {
          const next = { ...this.lpmCtx.prefixes, [this.packName]: value };
          this.lpmCtx.updatePrefixes(next);
          for (const p of this.pages) {
            const key = `${this.packName}::${p}`;
            const rename = this.lpmCtx.renames[key] || '';
            const title = this.computeTitleNow(p, value, rename);
            this.lpmCtx.debounceCheck(key, title, 0);
          }
        },

        updateRename(page, value) {
          const key = `${this.packName}::${page}`;
          const next = { ...this.lpmCtx.renames, [key]: value };
          this.lpmCtx.updateRenames(next);

          const prefix = this.lpmCtx.prefixes[this.packName] || '';
          const title = this.computeTitleNow(page, prefix, value);
          this.lpmCtx.debounceCheck(key, title, 0);
        },

        final(page) {
          return this.lpmCtx.computeTitle(this.packName, page);
        },
        collide(page) {
          return !!this.lpmCtx.collisions[`${this.packName}::${page}`];
        }
      },

      /* --------------------------------------------------------------------
       *  TEMPLATE
       * ------------------------------------------------------------------ */
      template: `
        <tbody>
          <!-- PACK ROW -->
          <tr class="pack-row">
            <td class="lpm-indent" :style="{ paddingLeft: (depth * 1.75) + 'em' }">
              <button class="lpm-caret" @click="toggle">{{ isOpen ? '▼' : '▶' }}</button>
              <strong :id="packLabelId">{{ packName }}</strong>
            </td>
            <td>
              <cdx-checkbox
                :model-value="isSelected"
                :disabled="lpmCtx.isPackDisabled(packName) || !!(flatPack && flatPack.isLocked)"
                :aria-labelledby="packLabelId"
                @update:model-value="updateSel" />
            </td>
            <td>
              <cdx-text-input
                :model-value="lpmCtx.prefixes[packName]"
                placeholder="prefix"
                :disabled="!isSelected || !!(flatPack && flatPack.isLocked)"
                @update:model-value="updatePrefix" />
            </td>
            <td></td>

            <td class="status-cell">
              <span v-if="flatPack?.installStatus === 'already-installed'" class="status-imported">
                Already imported (v{{ flatPack?.installedVersion || '—' }})
              </span>
              <span v-else-if="flatPack?.installStatus === 'safe-upgrade'" class="status-update">
                Upgrade: {{ flatPack?.installedVersion || '—' }} → {{ flatPack?.version || '—' }}
              </span>
              <span v-else-if="flatPack?.installStatus === 'incompatible-update' || flatPack?.installStatus === 'downgrade'" class="status-major">
                Major version change: {{ flatPack?.installedVersion || '—' }} → {{ flatPack?.version || '—' }}
              </span>
              <span v-else class="status-new">New</span>
            </td>
          </tr>

          <!-- PAGE ROWS -->
          <tr
            v-for="p in pages"
            :key="packName + '::' + p"
            v-show="isOpen"
            :class="['page-row', { 'lpm-row-ok': isSelected && !collide(p),
                                   'lpm-row-warn': isSelected && collide(p) }]">
            <td class="lpm-indent lpm-cell-pad-left"
                :style="{ paddingLeft: ((depth + 1) * 1.75) + 'em' }">{{ p }}</td>
            <td></td>
            <td>
              <cdx-text-input
                :model-value="lpmCtx.renames[packName + '::' + p]"
                placeholder="rename"
                :disabled="!isSelected || !!(flatPack && flatPack.isLocked)"
                @update:model-value="v => updateRename(p, v)" />
            </td>
            <td>{{ final(p) }}</td>
            <td class="lpm-status-cell">
              <span v-if="isSelected && !collide(p)" class="lpm-status-included">✓</span>
              <span v-else-if="isSelected && collide(p)" class="lpm-status-warning">⚠</span>
            </td>
          </tr>
        </tbody>

        <!-- RECURSION -->
        <lpm-pack-node
          v-for="child in childPacks"
          v-if="isOpen"
          :key="child.id"
          :node="child"
          :depth="depth + 1" />
      `
    }
  },

  /* ------------------------------------------------------------------------
   *  INJECTION CONTEXT
   * ---------------------------------------------------------------------- */
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

        updateSelectedPacks: v => self.$emit('update:selectedPacks', v),
        updatePrefixes: v => self.$emit('update:prefixes', v),
        updateRenames: v => self.$emit('update:renames', v),

        computeTitle: (p, pg) => self.finalPageTitle(p, pg),
        debounceCheck: (k, t, d) => self.debounceCheck(k, t, d),
        scheduleRecheck: () => self.scheduleCollisionRecheckForVisible(),
        sanitizeId: s => String(s).replace(/[^A-Za-z0-9_-]/g, '-'),

        isPackDisabled: n => self.isPackDisabled(n),
        togglePackExplicit: (n, v) => self.togglePackExplicit(n, v)
      }
    };
  },

  /* ------------------------------------------------------------------------
   *  PROPS / DATA / COMPUTED / METHODS
   * ---------------------------------------------------------------------- */
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
      debouncers: Object.create(null),
      collisionVersion: Object.create(null),
      collisionCache: Object.create(null),
      pendingCommit: false,
      explicitSelectedPacks: Object.create(null),
      disabledPacks: Object.create(null)
    };
  },

  computed: {
    nodes() {
      return this.data?.hierarchy?.nodes || {};
    },

    tree() {
      const roots = this.data?.hierarchy?.tree || [];
      return roots.map(r => (typeof r === 'string' ? this.nodes[r] : r)).filter(Boolean);
    },

    treeIndex() {
      const map = Object.create(null);
      for (const id of Object.keys(this.nodes)) {
        if (!id.startsWith('pack:')) continue;
        map[id] ||= [];
      }
      for (const [id, node] of Object.entries(this.nodes)) {
        if (!id.startsWith('pack:')) continue;
        const parent = node.parent;
        if (!parent) continue;
        const pid = parent.startsWith('pack:') ? parent : `pack:${parent}`;
        map[pid] ||= [];
        map[pid].push(id);
      }
      return map;
    },

    dependencyMap() {
      const map = Object.create(null);
      for (const [id, node] of Object.entries(this.nodes)) {
        if (!id.startsWith('pack:')) continue;
        const name = idToName(id, node);
        const seen = new Set();
        const queue = [...(node.depends_on || [])];
        while (queue.length) {
          const dep = queue.shift();
          const depName = idToName(dep);
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

  created() {
    for (const [name, val] of Object.entries(this.selectedPacks || {})) {
      if (val) this.explicitSelectedPacks[name] = true;
    }
    this.recomputeSelectedFromExplicit();
  },

  methods: {
    pageKey(p, pg) {
      return `${p}::${pg}`;
    },
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

    computeClosureFrom(explicitSet) {
      const seen = new Set();
      const queue = Object.keys(explicitSet).filter(k => explicitSet[k]);
      for (const name of queue) seen.add(name);

      while (queue.length) {
        const cur = queue.shift();
        const childIds = this.treeIndex[`pack:${cur}`] || [];
        for (const cid of childIds) {
          const cname = idToName(cid, this.nodes[cid]);
          if (!seen.has(cname)) { seen.add(cname); queue.push(cname); }
        }
        const deps = this.dependencyMap[cur] || [];
        for (const dep of deps) {
          if (!seen.has(dep)) { seen.add(dep); queue.push(dep); }
        }
      }
      return seen;
    },

    recomputeSelectedFromExplicit() {
      const closure = this.computeClosureFrom(this.explicitSelectedPacks);
      const nextSelected = {};
      const nextDisabled = {};
      for (const name of closure) {
        nextSelected[name] = true;
        if (!this.explicitSelectedPacks[name]) nextDisabled[name] = true;
      }

      for (const [id, node] of Object.entries(this.nodes)) {
        if (isPackNode(id, node) && node?.isLocked) {
          nextSelected[idToName(id, node)] = true;
        }
      }
      this.disabledPacks = nextDisabled;
      this.$emit('update:selectedPacks', nextSelected);
      this.scheduleCollisionRecheckForVisible();
    },

    isPackDisabled(name) {
      return !!this.disabledPacks[name];
    },

    togglePackExplicit(name, selected) {
      if (selected) this.explicitSelectedPacks[name] = true;
      else delete this.explicitSelectedPacks[name];
      this.recomputeSelectedFromExplicit();
    },

    // ID/Node helpers are provided by ../utils/nodeUtils.js

    asyncCheck(title) {
      if (!this.checkTitleExists) return Promise.resolve(false);
      if (title in this.collisionCache) return Promise.resolve(this.collisionCache[title]);
      return this.checkTitleExists(title).then(r => (this.collisionCache[title] = !!r));
    },

    debounceCheck(key, title, delay = 300) {
      if (!this.checkTitleExists) return;
      clearTimeout(this.debouncers[key]);
      const version = (this.collisionVersion[key] || 0) + 1;
      this.collisionVersion[key] = version;

      const run = async () => {
        const exists = await this.asyncCheck(title);
        if (this.collisionVersion[key] !== version) return;
        this.collisions[key] = !!exists;

        if (!this.pendingCommit) {
          this.pendingCommit = true;
          Promise.resolve().then(() => {
            this.collisions = { ...this.collisions };
            this.pendingCommit = false;
          });
        }
      };
      this.debouncers[key] = setTimeout(run, delay);
    },

    /**
    * Return a flattened summary of all packs, pages, and user selections
    * suitable for sending to the backend.
    */
    exportSelectionSummary(repoUrl) {
      const packs = [];

      for (const [id, node] of Object.entries(this.nodes)) {
        if (!isPackNode(id, node)) continue;
        const name = idToName(id, node);
        const selected = !!this.selectedPacks[name];

        const packData = {
          name,
          id,
          selected,
          version: node.version || null,
          installedVersion: node.installedVersion || null,
          installStatus: node.installStatus || 'new',
          isLocked: !!node.isLocked,
          pages: []
        };

        const pages = node.pages || [];
        for (const p of pages) {
          const finalTitle = this.finalPageTitle(name, p);
          packData.pages.push({
            original: p,
            finalTitle,
            prefix: this.prefixes[name] || '',
            rename: this.renames[this.pageKey(name, p)] || '',
            collide: !!this.collisions[this.pageKey(name, p)]
          });
        }

        packs.push(packData);
      }

      return { repoUrl, packs };
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
