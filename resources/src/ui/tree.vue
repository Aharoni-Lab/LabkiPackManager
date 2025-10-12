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

        <!-- Render hierarchy roots as individual TBODY sections to keep valid table structure -->
        <template v-if="tree.length">
          <lpm-pack-node v-for="root in tree" :key="root.id" :node="root" :depth="0" />
        </template>

        <!-- Fallback message -->
        <tbody v-else>
          <tr><td colspan="5"><em>No data loaded.</em></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
export default {
  name: 'LpmTree',

  components: {
    LpmPackNode: {
      name: 'lpm-pack-node',
      props: { node: { type: Object, required: true }, depth: { type: Number, default: 0 } },
      inject: ['lpmCtx'],

      computed: {
        packName() { return this.node.id.startsWith('pack:') ? this.node.id.slice(5) : this.node.id; },
        packId() { return `pack:${this.packName}`; },
        packLabelId() { return `pack-label-${this.lpmCtx.sanitizeId(this.packName)}`; },
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
          const i = p.indexOf(':');
          const ns = i > 0 ? p.slice(0, i) : '';
          const base = i > 0 ? p.slice(i + 1) : p;
          const tail = (ren || '').trim() || base;
          return `${ns ? ns + ':' : ''}${pre || ''}${tail}`;
        },

        updateSel(v) { this.lpmCtx.togglePackExplicit(this.packName, v); },

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
        <tbody>
          <tr class="pack-row">
            <td class="lpm-indent" :style="{ paddingLeft: (depth * 1.75) + 'em' }">
              <button class="lpm-caret" @click="toggle">{{ isOpen ? '▼' : '▶' }}</button>
              <strong :id="packLabelId">{{ packName }}</strong>
            </td>
            <td>
              <cdx-checkbox :model-value="isSelected"
                            :disabled="lpmCtx.isPackDisabled(packName)"
                            :aria-labelledby="packLabelId"
                            @update:model-value="updateSel" />
            </td>
            <td>
              <cdx-text-input :model-value="lpmCtx.prefixes[packName]"
                              placeholder="prefix"
                              @update:model-value="updatePrefix" />
            </td>
            <td></td><td></td>
          </tr>

          <tr v-for="p in pages" :key="packName + '::' + p" v-show="isOpen"
              :class="['page-row', { 'lpm-row-ok': isSelected && !collide(p),
                                     'lpm-row-warn': isSelected && collide(p) }]">
            <td class="lpm-indent lpm-cell-pad-left"
                :style="{ paddingLeft: ((depth + 1) * 1.75) + 'em' }">{{ p }}</td>
            <td></td>
            <td>
              <cdx-text-input :model-value="lpmCtx.renames[packName + '::' + p]"
                              placeholder="rename"
                              @update:model-value="v => updateRename(p, v)" />
            </td>
            <td>{{ final(p) }}</td>
            <td class="lpm-status-cell">
              <span v-if="isSelected && !collide(p)" class="lpm-status-included">✓</span>
              <span v-else-if="isSelected && collide(p)" class="lpm-status-warning">⚠</span>
            </td>
          </tr>
        </tbody>

        <lpm-pack-node v-for="child in childPacks" v-if="isOpen"
                       :key="child.id"
                       :node="child"
                       :depth="depth + 1" />
      `
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

  created() {
    for (const [name, val] of Object.entries(this.selectedPacks || {})) {
      if (val) this.explicitSelectedPacks[name] = true;
    }
    this.recomputeSelectedFromExplicit();
  },

  computed: {
    nodes() {
      return this.data?.hierarchy?.nodes || {};
    },

    // ✅ FIX: ensure `tree` always contains full node objects
    tree() {
      const roots = this.data?.hierarchy?.tree || [];
      return roots
        .map(r => (typeof r === 'string' ? this.nodes[r] : r))
        .filter(Boolean);
    },

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
            const chNode = this.nodes[cid];
            if (chNode) walk(chNode);
          }
        }
      };
      for (const root of this.tree) walk(root);
      return map;
    },

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

    computeClosureFrom(explicitSet) {
      const seen = new Set();
      const queue = [];
      for (const name of Object.keys(explicitSet)) {
        if (explicitSet[name]) { seen.add(name); queue.push(name); }
      }
      while (queue.length) {
        const cur = queue.shift();
        const childIds = this.treeIndex[`pack:${cur}`] || [];
        for (const cid of childIds) {
          const cname = cid.startsWith('pack:') ? cid.slice(5) : cid;
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
      this.disabledPacks = nextDisabled;
      this.$emit('update:selectedPacks', nextSelected);
      this.scheduleCollisionRecheckForVisible();
    },

    isPackDisabled(name) { return !!this.disabledPacks[name]; },
    togglePackExplicit(name, selected) {
      if (selected) this.explicitSelectedPacks[name] = true;
      else delete this.explicitSelectedPacks[name];
      this.recomputeSelectedFromExplicit();
    },

    asyncCheck(title) {
      if (!this.checkTitleExists) return Promise.resolve(false);
      if (title in this.collisionCache) return Promise.resolve(this.collisionCache[title]);
      return this.checkTitleExists(title).then(r => (this.collisionCache[title] = !!r));
    },

    debounceCheck(key, title, delay = 300) {
      if (!this.checkTitleExists) return;
      if (this.debouncers[key]) clearTimeout(this.debouncers[key]);
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
      delay <= 0 ? run() : (this.debouncers[key] = setTimeout(run, delay));
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
