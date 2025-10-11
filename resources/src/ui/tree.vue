<template>
  <div class="lpm-row lpm-row-tree">
    <div class="lpm-tree" role="region" aria-label="Pack and page selection tree">
      <table class="lpm-tree-table" role="treegrid">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Select</th>
            <th scope="col">Prefix / Rename</th>
            <th scope="col">Final Name</th>
            <th scope="col">Status</th>
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
          <tr>
            <td colspan="5"><em>No data loaded.</em></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
/**
 * LpmTree.vue
 * -------------------------
 * Recursive hierarchical pack/page selection UI for Labki Pack Manager.
 * Features:
 *   - Expandable tree of packs (with nested packs + pages)
 *   - Prefix/rename editing with real-time title collision checks
 *   - Dependency propagation across packs
 *   - Visual collision feedback
 */
export default {
  name: 'LpmTree',

  props: {
    /** Full manifest hierarchy object from backend (may be null) */
    data: { type: Object, default: null },
    /** Map: packName → selected (bool) */
    selectedPacks: { type: Object, required: true },
    /** Map: packName → prefix string */
    prefixes: { type: Object, required: true },
    /** Map: pack::page → rename string */
    renames: { type: Object, required: true },
    /** Optional async collision check (title: string) => Promise<boolean> */
    checkTitleExists: { type: Function, default: null }
  },

  emits: ['update:selectedPacks', 'update:prefixes', 'update:renames'],

  data() {
    return {
      expanded: Object.create(null),      // PackID → expanded state
      collisions: Object.create(null),    // pageKey → boolean collision
      _debouncers: Object.create(null),   // key → timeout
      _collisionVersion: Object.create(null), // version counter to drop stale checks
      _collisionCache: Object.create(null)    // title → result cache
    };
  },

  computed: {
    /** Convenience alias for manifest node dictionary */
    nodes() {
      return this.data?.hierarchy?.nodes || {};
    },
    /** Root-level tree array */
    tree() {
      return this.data?.hierarchy?.tree || [];
    }
  },

  /**
   * Provide shared tree context to all recursive LpmPackNode children.
   * This avoids deeply nested prop chains and ensures consistent reactivity.
   */
  provide() {
    const self = this;
    return {
      lpmCtx: {
        // ---- reactive sources ----
        get nodes() { return self.nodes; },
        get tree() { return self.tree; },
        get expanded() { return self.expanded; },
        get selectedPacks() { return self.selectedPacks; },
        get prefixes() { return self.prefixes; },
        get renames() { return self.renames; },
        get collisions() { return self.collisions; },

        // ---- mutation helpers ----
        updateSelectedPacks: v => self.$emit('update:selectedPacks', v),
        updatePrefixes: v => self.$emit('update:prefixes', v),
        updateRenames: v => self.$emit('update:renames', v),

        // ---- utilities ----
        computeTitle: (pack, page) => self.finalPageTitle(pack, page),
        debounceCheck: (k, t, d) => self.debounceCheck(k, t, d),
        scheduleRecheck: () => self.scheduleCollisionRecheckForVisible(),
        sanitizeId: s => String(s).replace(/[^A-Za-z0-9_-]/g, '-')
      }
    };
  },

  methods: {
    /** Generate unique key for a pack/page pair */
    pageKey(pack, page) {
      return `${pack}::${page}`;
    },

    /** Split "NS:Title" → { ns, base } */
    splitNs(title) {
      const i = title.indexOf(':');
      return i > 0
        ? { ns: title.slice(0, i), base: title.slice(i + 1) }
        : { ns: '', base: title };
    },

    /**
     * Compute final page title based on prefix + rename + namespace.
     * @param {string} pack
     * @param {string} page
     * @returns {string}
     */
    finalPageTitle(pack, page) {
      const { ns, base } = this.splitNs(page);
      const prefix = this.prefixes[pack] || '';
      const rename = (this.renames[this.pageKey(pack, page)] || '').trim();
      const tail = rename || base;
      return `${ns ? ns + ':' : ''}${prefix}${tail}`;
    },

    /**
     * Trigger collision checks for all visible selected packs' pages.
     * Uses debounceCheck internally.
     */
    scheduleCollisionRecheckForVisible() {
      if (!this.checkTitleExists) return;
      for (const [pack, isSelected] of Object.entries(this.selectedPacks)) {
        if (!isSelected) continue;
        const packNode = this.nodes[`pack:${pack}`];
        const pages = packNode?.pages || [];
        for (const pageName of pages) {
          const key = this.pageKey(pack, pageName);
          this.debounceCheck(key, this.finalPageTitle(pack, pageName));
        }
      }
    },

    /**
     * Cached async collision lookup for a title.
     * @param {string} title
     * @returns {Promise<boolean>}
     */
    asyncCheck(title) {
      if (!this.checkTitleExists) return Promise.resolve(false);
      if (title in this._collisionCache)
        return Promise.resolve(this._collisionCache[title]);
      return this.checkTitleExists(title).then(
        exists => (this._collisionCache[title] = !!exists)
      );
    },

    /**
     * Debounced collision check per key.
     * Avoids flooding server while typing prefix/rename.
     */
    debounceCheck(key, title, delay = 300) {
      if (!this.checkTitleExists) return;

      // clear previous pending run
      if (this._debouncers[key]) clearTimeout(this._debouncers[key]);

      // bump version counter to drop stale async results
      const version = (this._collisionVersion[key] || 0) + 1;
      this._collisionVersion[key] = version;

      const run = async () => {
        const exists = await this.asyncCheck(title);
        if (this._collisionVersion[key] !== version) return;
        this.collisions[key] = !!exists;
        // force reactivity
        this.collisions = { ...this.collisions };
      };

      delay <= 0 ? run() : (this._debouncers[key] = setTimeout(run, delay));
    }
  },

  /**
   * Recursive subcomponent representing one pack (and its pages/nested packs).
   * Defined inline for convenience but could be moved into a separate file.
   */
  components: {
    LpmPackNode: {
      name: 'lpm-pack-node',
      props: {
        node: { type: Object, required: true },
        depth: { type: Number, default: 0 }
      },
      inject: ['lpmCtx'],

      computed: {
        /** Raw pack name (without "pack:" prefix) */
        packName() {
          return this.node.id.startsWith('pack:')
            ? this.node.id.slice(5)
            : this.node.id;
        },
        packId() {
          return `pack:${this.packName}`;
        },
        isOpen() {
          return !!this.lpmCtx.expanded[this.packId];
        },
        isSelected() {
          return !!this.lpmCtx.selectedPacks[this.packName];
        },
        /** All pages directly under this pack */
        pages() {
          return (this.node.children || [])
            .filter(ch => ch.type === 'page')
            .map(ch => ch.id);
        },
        /** All nested packs directly under this pack */
        childPacks() {
          return (this.node.children || []).filter(ch => ch.type === 'pack');
        }
      },

      created() {
        // default to expanded on initial render
        if (!(this.packId in this.lpmCtx.expanded))
          this.lpmCtx.expanded[this.packId] = true;
      },

      methods: {
        /** Toggle open/closed state for this pack */
        toggle() {
          this.lpmCtx.expanded[this.packId] = !this.isOpen;
        },

        /** Compute title instantly (used while typing prefix/rename) */
        computeTitleNow(pageName, prefixOverride, renameOverride) {
          const { ns, base } = (() => {
            const i = pageName.indexOf(':');
            return i > 0
              ? { ns: pageName.slice(0, i), base: pageName.slice(i + 1) }
              : { ns: '', base: pageName };
          })();
          const rename = (renameOverride || '').trim();
          const prefix = prefixOverride || '';
          const tail = rename || base;
          return `${ns ? ns + ':' : ''}${prefix}${tail}`;
        },

        /** Return true if this pack has any descendant page collisions */
        packHasAnyCollision() {
          const recurse = node => {
            if (node.type === 'page') {
              const key = `${this.packName}::${node.id}`;
              return this.lpmCtx.collisions[key];
            }
            return (node.children || []).some(recurse);
          };
          return recurse(this.node);
        },

        /** Update pack selection and propagate dependencies */
        updateSel(selected) {
          const selectedRoots = Object.entries(this.lpmCtx.selectedPacks)
            .filter(([, v]) => v)
            .map(([k]) => k);

          // toggle current pack
          const idx = selectedRoots.indexOf(this.packName);
          if (selected && idx === -1) selectedRoots.push(this.packName);
          if (!selected && idx !== -1) selectedRoots.splice(idx, 1);

          const seen = new Set(selectedRoots);
          const queue = [...selectedRoots];

          const addNested = pack => {
            const scan = node => {
              if (node.type !== 'pack') return false;
              if (node.id === pack) {
                for (const ch of node.children || []) {
                  if (ch.type === 'pack' && !seen.has(ch.id)) {
                    seen.add(ch.id);
                    queue.push(ch.id);
                  }
                }
                return true;
              }
              return (node.children || []).some(scan);
            };
            (this.lpmCtx.tree || []).forEach(scan);
          };

          // BFS over dependencies + structural nesting
          while (queue.length) {
            const cur = queue.shift();
            addNested(cur);
            const curNode = this.lpmCtx.nodes[`pack:${cur}`];
            if (!curNode) continue;
            for (const dep of curNode.depends_on || []) {
              const name = dep.startsWith('pack:') ? dep.slice(5) : dep;
              if (!seen.has(name)) { seen.add(name); queue.push(name); }
            }
          }

          // emit next selection map
          const next = {};
          for (const name of seen) next[name] = true;
          this.lpmCtx.updateSelectedPacks(next);
          this.lpmCtx.scheduleRecheck();
        },

        /** Update prefix + trigger live collision checks */
        updatePrefix(prefix) {
          const next = { ...this.lpmCtx.prefixes, [this.packName]: prefix };
          this.lpmCtx.updatePrefixes(next);

          // immediate revalidation of visible pages
          for (const p of this.pages) {
            const key = `${this.packName}::${p}`;
            const rename = this.lpmCtx.renames[key] || '';
            const title = this.computeTitleNow(p, prefix, rename);
            this.lpmCtx.debounceCheck(key, title, 0);
          }
        },

        /** Update rename + trigger live collision checks */
        updateRename(page, rename) {
          const key = `${this.packName}::${page}`;
          const next = { ...this.lpmCtx.renames, [key]: rename };
          this.lpmCtx.updateRenames(next);

          const prefix = this.lpmCtx.prefixes[this.packName] || '';
          const title = this.computeTitleNow(page, prefix, rename);
          this.lpmCtx.debounceCheck(key, title, 0);
        },

        /** Final computed title for rendering */
        final(page) {
          return this.lpmCtx.computeTitle(this.packName, page);
        },

        /** True if a given page collides */
        collide(page) {
          return !!this.lpmCtx.collisions[`${this.packName}::${page}`];
        }
      },

      /** Recursive template for packs + pages */
      template: `
        <!-- pack row -->
        <tr class="pack-row">
          <td class="lpm-indent" :style="{ paddingLeft: (depth * 1.75) + 'em' }">
            <button class="lpm-caret" @click="toggle" :aria-expanded="isOpen.toString()">
              {{ isOpen ? '▼' : '▶' }}
            </button>
            <strong>{{ packName }}</strong>
          </td>
          <td>
            <cdx-checkbox
              :model-value="isSelected"
              :aria-label="'Select pack ' + packName"
              @update:model-value="updateSel"
            />
          </td>
          <td>
            <cdx-text-input
              :model-value="lpmCtx.prefixes[packName]"
              placeholder="prefix"
              aria-label="Prefix"
              @update:model-value="updatePrefix"
            />
          </td>
          <td></td><td></td>
        </tr>

        <!-- children -->
        <template v-if="isOpen">
          <!-- pages -->
          <tr
            v-for="p in pages"
            :key="packName + '::' + p"
            :class="['page-row', { 'lpm-row-ok': isSelected && !collide(p), 'lpm-row-warn': isSelected && collide(p) }]"
          >
            <td class="lpm-indent lpm-cell-pad-left" :style="{ paddingLeft: ((depth + 1) * 1.75) + 'em' }">
              {{ p }}
            </td>
            <td></td>
            <td>
              <cdx-text-input
                :model-value="lpmCtx.renames[packName + '::' + p]"
                placeholder="rename"
                aria-label="Rename page"
                @update:model-value="v => updateRename(p, v)"
              />
            </td>
            <td>{{ final(p) }}</td>
            <td class="lpm-status-cell">
              <span v-if="isSelected && !collide(p)" class="lpm-status-included">✓</span>
              <span v-else-if="isSelected && collide(p)" class="lpm-status-warning">⚠</span>
            </td>
          </tr>

          <!-- nested packs -->
          <lpm-pack-node
            v-for="child in childPacks"
            :key="child.id"
            :node="child"
            :depth="depth + 1"
          />
        </template>
      `
    }
  }
};
</script>

<style scoped>
/* ----------------- Layout ----------------- */
.lpm-tree { overflow-x: auto; }
.lpm-tree-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 0.5rem;
}

.lpm-tree-table th,
.lpm-tree-table td {
  padding: 0.4rem 0.6rem;
  text-align: left;
  border-bottom: 1px solid var(--border-color, #ddd);
  vertical-align: middle;
}

/* ----------------- Row styling ----------------- */
.pack-row { background-color: hsl(0 0% 98%); }
.page-row { background-color: hsl(0 0% 100%); }
.pack-row:nth-child(even) { background-color: hsl(0 0% 97%); }

/* Indentation */
.lpm-indent {
  transition: padding-left 0.15s ease;
}
.page-row .lpm-indent {
  font-style: italic;
}

/* Caret toggle */
.lpm-caret {
  background: none;
  border: none;
  cursor: pointer;
  margin-right: 0.4rem;
  display: inline-flex;
  align-items: center;
}

/* Status indicators */
.lpm-status-cell { white-space: nowrap; }
.lpm-status-included { color: var(--included-color, #16a34a); margin-right: 6px; }
.lpm-status-warning { color: var(--warning-color, #d97706); }

/* Visual feedback for pack rows */
.lpm-row-ok   { background-color: hsla(120, 40%, 90%, 0.4); }
.lpm-row-warn { background-color: hsla(40, 90%, 90%, 0.5); }
</style>
