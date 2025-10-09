<template>
  <div class="lpm-row lpm-row-tree">
    <div class="lpm-tree" role="region" aria-label="Pack and page selection tree">
      <table class="lpm-tree-table">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Select</th>
            <th scope="col">Prefix / Rename</th>
            <th scope="col">Final Name</th>
            <th scope="col">Status</th>
          </tr>
        </thead>

        <tbody v-if="rootsToRender.length" id="lpm-tree-body">
          <template v-for="rootId in rootsToRender" :key="rootId">
            <lpm-pack-node
              :pack-id="rootId"
              :nodes="nodes"
              :expanded.sync="expanded"
              :depth="0"
              :selected-packs="selectedPacks"
              :selected-pages="selectedPages"
              :prefixes="prefixes"
              :renames="renames"
              :collisions="collisions"
              :check-title-exists="checkTitleExists"
              @update:selectedPacks="onUpdateSelectedPacks"
              @update:selectedPages="onUpdateSelectedPages"
              @update:prefixes="onUpdatePrefixes"
              @update:renames="onUpdateRenames"
            />
          </template>
        </tbody>

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
 * LpmTree – Hierarchical pack/page selection with:
 *  - collapsible packs
 *  - per-pack prefix, per-page rename
 *  - import selection with dependency propagation
 *  - live collision markers (optional async check)
 *
 * Expects `data.hierarchy` shaped like:
 * {
 *   tree: [{ type: 'pack'|'page', id: '...' , children?: [...] }],
 *   nodes: { 'pack:ID': {..., depends_on?: ['pack:Other', ...]}, 'page:ID': {...} },
 *   roots: ['pack:RootA', ...]
 * }
 */

const CARET_RIGHT = '▶';
const CARET_DOWN  = '▼';

export default {
  name: 'LpmTree',

  props: {
    /** Full manifest data object (may be null). */
    data: { type: Object, default: null },

    /** Map of packName → boolean for selection state. (keys = raw pack id, not "pack:...") */
    selectedPacks: { type: Object, required: true },

    /** Map of "pack::page" → boolean for selection state. */
    selectedPages: { type: Object, required: true },

    /** Map of packName → prefix string. */
    prefixes: { type: Object, required: true },

    /** Map of "pack::page" → renamed page string. */
    renames: { type: Object, required: true },

    /**
     * Optional async title checker: (finalTitle: string) => Promise<boolean>
     * Should resolve true if the title exists in the wiki.
     */
    checkTitleExists: { type: Function, default: null }
  },

  emits: [
    'update:selectedPacks',
    'update:selectedPages',
    'update:prefixes',
    'update:renames'
  ],

  data() {
    return {
      /** Expanded state per pack key "pack:ID" */
      expanded: Object.create(null),
      /** Local collision map: pageKey -> boolean */
      collisions: Object.create(null),
      /** Debounce handles per titleKey */
      _debouncers: Object.create(null)
    };
  },

  computed: {
    nodes() {
      return this.data?.hierarchy?.nodes || {};
    },

    roots() {
      // Prefer explicit roots if present; else derive from tree
      const explicit = this.data?.hierarchy?.roots || [];
      if (explicit.length) return explicit;
      const tree = this.data?.hierarchy?.tree || [];
      return tree.filter(n => n.type === 'pack').map(n => `pack:${n.id}`);
    },

    /** Build a set of all dependency packs referenced anywhere */
    dependencySet() {
      const set = new Set();
      for (const [key, node] of Object.entries(this.nodes)) {
        if (!key.startsWith('pack:')) continue;
        const deps = node.depends_on || node.dependsOn || [];
        for (const d of deps) {
          const depKey = d.startsWith('pack:') ? d : `pack:${d}`;
          set.add(depKey);
        }
      }
      return set;
    },

    /** Final root list to render: exclude packs that only appear as dependencies */
    rootsToRender() {
      const roots = this.roots.length ? this.roots : [];
      if (!roots.length) return [];
      const deps = this.dependencySet;
      return roots.filter(r => !deps.has(r));
    }
  },

  methods: {
    // ---------- shared helpers ----------
    pageKey(packName, pageName) {
      return `${packName}::${pageName}`;
    },

    splitNamespace(title) {
      // If title contains "NS:Name", extract NS and base
      const idx = title.indexOf(':');
      if (idx > 0) {
        return { ns: title.slice(0, idx), base: title.slice(idx + 1) };
      }
      return { ns: '', base: title };
    },

    finalPageTitle(packName, pageName) {
      const { ns, base } = this.splitNamespace(pageName);
      const prefix = this.prefixes[packName] || '';
      const key = this.pageKey(packName, pageName);
      const rename = (this.renames[key] || '').trim();
      const tail = rename || base;
      return `${ns ? ns + ':' : ''}${prefix}${tail}`;
    },

    // ---------- emits with whole-object updates (immutable) ----------
    onUpdateSelectedPacks(next) {
      this.$emit('update:selectedPacks', next);
    },
    onUpdateSelectedPages(next) {
      this.$emit('update:selectedPages', next);
    },
    onUpdatePrefixes(next) {
      this.$emit('update:prefixes', next);
      // Recheck collisions for visible pages (debounced)
      this.scheduleCollisionRecheckForVisible();
    },
    onUpdateRenames(next) {
      this.$emit('update:renames', next);
      // Recheck collisions for this changed key (debounced)
      this.scheduleCollisionRecheckForVisible();
    },

    // Re-run collision checks for currently visible pages
    scheduleCollisionRecheckForVisible() {
      if (!this.checkTitleExists) return;
      // Scan pages included through selected packs
      const packsToScan = new Set(Object.keys(this.selectedPacks).filter(k => this.selectedPacks[k]));
      for (const packName of packsToScan) {
        const packKey = `pack:${packName}`;
        const pages = this.collectPagesForPack(packKey);
        for (const pageName of pages) {
          const pkey = this.pageKey(packName, pageName);
          this.debounceCheck(pkey, this.finalPageTitle(packName, pageName));
        }
      }
    },

    debounceCheck(pageKey, finalTitle, delay = 300) {
      if (!this.checkTitleExists) return;
      if (this._debouncers[pageKey]) clearTimeout(this._debouncers[pageKey]);
      const run = async () => {
        try {
          const exists = await this.checkTitleExists(finalTitle);
          this.$set ? this.$set(this.collisions, pageKey, !!exists)
                    : (this.collisions[pageKey] = !!exists);
          // force reactivity in Vue 3 options API
          this.collisions = { ...this.collisions };
        } catch {
          // on failure, do nothing (treat as unknown/no warning)
        }
      };
      if (delay <= 0) {
        // immediate check (no debounce)
        void run();
        return;
      }
      this._debouncers[pageKey] = setTimeout(run, delay);
    },

    // Collect pages directly under a pack (from tree structure)
    collectPagesForPack(packKey) {
      // Find the pack node inside hierarchy.tree (by id match)
      // tree uses {type, id, children[]}, packKey is "pack:ID"
      const id = packKey.replace(/^pack:/, '');
      const list = [];
      const tree = this.data?.hierarchy?.tree || [];
      const visit = (node) => {
        if (node.type === 'pack' && node.id === id) {
          for (const ch of (node.children || [])) {
            if (ch.type === 'page') list.push(ch.id);
          }
          // do not traverse deeper here; nested packs' pages are under their own packs
        } else if (node.children) {
          node.children.forEach(visit);
        }
      };
      tree.forEach(visit);
      return list;
    }
  },

  components: {
    /**
     * Recursive node for packs (renders pack row + its children: pages, nested packs, and dependency packs)
     */
    LpmPackNode: {
      name: 'lpm-pack-node',
      props: {
        packId: { type: String, required: true }, // "pack:ID"
        nodes: { type: Object, required: true },
        depth: { type: Number, default: 0 },
        expanded: { type: Object, required: true }, // v-model:expanded-like external map
        selectedPacks: { type: Object, required: true },
        selectedPages: { type: Object, required: true },
        prefixes: { type: Object, required: true },
        renames: { type: Object, required: true },
        collisions: { type: Object, required: true },
        checkTitleExists: { type: Function, default: null }
      },
      emits: [
        'update:selectedPacks',
        'update:selectedPages',
        'update:prefixes',
        'update:renames'
      ],
      data() {
        return {
          // local cache of children from hierarchy.tree (pages and nested packs)
          treeChildren: []
        };
      },
      computed: {
        packName() {
          return this.packId.replace(/^pack:/, '');
        },
        packNode() {
          return this.nodes[this.packId] || { type: 'pack', id: this.packId };
        },
        isOpen() {
          return !!this.expanded[this.packId];
        },
        dependsOn() {
          const arr = this.packNode.depends_on || this.packNode.dependsOn || [];
          // normalize to "pack:Name" keys
          return arr.map(d => (d.startsWith('pack:') ? d : `pack:${d}`));
        },
        // children to render = tree nested packs + dependency packs (deduped)
        childPacks() {
          const fromTree = (this.treeChildren.filter(c => c.type === 'pack').map(c => `pack:${c.id}`));
          const merged = Array.from(new Set([...fromTree, ...this.dependsOn]));
          return merged;
        },
        pages() {
          return this.treeChildren.filter(c => c.type === 'page').map(c => c.id);
        },
        // A page is included if its pack is selected
        includedPageMap() {
          const map = Object.create(null);
          const packSelected = !!this.selectedPacks[this.packName];
          for (const p of this.pages) {
            const key = `${this.packName}::${p}`;
            map[p] = packSelected;
          }
          return map;
        }
      },
      created() {
        // find tree children for this pack from the root tree list
        const tree = this.$parent?.data?.hierarchy?.tree || [];
        const id = this.packName;
        const find = (node) => {
          if (node.type === 'pack' && node.id === id) {
            this.treeChildren = node.children || [];
            return true;
          }
          if (node.children) {
            return node.children.some(find);
          }
          return false;
        };
        tree.forEach(n => { if (!this.treeChildren.length) find(n); });

        // default expanded for roots
        if (!(this.packId in this.expanded)) {
          this.$set ? this.$set(this.expanded, this.packId, true)
                    : (this.expanded[this.packId] = true);
        }
      },
      methods: {
        caretSymbol() {
          return this.isOpen ? '▼' : '▶';
        },
        toggleOpen() {
          this.expanded[this.packId] = !this.isOpen;
          // force reactivity for external object map
          this.$parent.expanded = { ...this.$parent.expanded };
        },

        // -------- updates (immutable) --------
        updateSelectedPack(val) {
          // propagate: selecting this pack also selects all dependency packs
          const next = { ...this.selectedPacks, [this.packName]: val };
          if (val) {
            const walkDeps = (pid) => {
              const node = this.nodes[pid] || {};
              const deps = (node.depends_on || node.dependsOn || []).map(d => (d.startsWith('pack:') ? d : `pack:${d}`));
              for (const d of deps) {
                const name = d.replace(/^pack:/, '');
                if (!next[name]) {
                  next[name] = true;
                  walkDeps(d);
                }
              }
            };
            walkDeps(this.packId);
          }
          this.$emit('update:selectedPacks', next);
          // trigger collision recheck at parent
          this.$parent.scheduleCollisionRecheckForVisible?.();
        },

        updatePrefix(val) {
          const next = { ...this.prefixes, [this.packName]: val };
          this.$emit('update:prefixes', next);
        },

        // page selection removed; inclusion derives from pack selection

        updateRename(pageName, val) {
          const key = `${this.packName}::${pageName}`;
          const next = { ...this.renames, [key]: val };
          this.$emit('update:renames', next);
          if (this.checkTitleExists) {
            // ensure parent state (renames) has applied before computing final title
            this.$nextTick(() => {
              const final = this.$parent.finalPageTitle(this.packName, pageName);
              this.$parent.debounceCheck(key, final, 0); // run immediately
            });
          }
        },

        finalName(pageName) {
          return this.$parent.finalPageTitle(this.packName, pageName);
        },

        // status cell content for a page
        pageStatus(pageName) {
          const included = !!this.includedPageMap[pageName];
          const key = `${this.packName}::${pageName}`;
          const collides = !!this.collisions[key];

          return {
            included,
            collides
          };
        }
      },

      template: `
        <!-- pack row -->
        <tr class="pack-row">
          <td :style="{ paddingLeft: (depth * 2) + 'em' }">
            <button class="lpm-caret" @click="toggleOpen" :aria-label="'Toggle ' + packName">
              {{ caretSymbol() }}
            </button>
            <strong>{{ packName }}</strong>
          </td>
          <td>
            <cdx-checkbox
              :model-value="!!selectedPacks[packName]"
              :aria-label="'Select pack ' + packName"
              @update:model-value="val => updateSelectedPack(val)"
            />
          </td>
          <td>
            <cdx-text-input
              :model-value="prefixes[packName]"
              placeholder="prefix"
              aria-label="Prefix"
              @update:model-value="val => updatePrefix(val)"
            />
          </td>
          <td></td>
          <td></td>
        </tr>

        <!-- children (pages first, then packs, collapsible) -->
        <template v-if="isOpen">
          <tr v-for="p in pages" :key="packName + '::' + p" class="page-row">
            <td class="lpm-cell-pad-left" :style="{ paddingLeft: ((depth + 1) * 2) + 'em' }">{{ p }}</td>
            <td></td>
            <td>
              <cdx-text-input
                :model-value="renames[packName + '::' + p]"
                placeholder="rename"
                aria-label="Rename page"
                @update:model-value="val => updateRename(p, val)"
              />
            </td>
            <td>{{ finalName(p) }}</td>
            <td class="lpm-status-cell">
              <template v-if="includedPageMap[p]">
                <span v-if="!pageStatus(p).collides" class="lpm-status-included" title="Ready (no collision)">✓</span>
                <span v-else class="lpm-status-warning" title="Title already exists">⚠</span>
              </template>
            </td>
          </tr>

          <!-- nested packs (from tree and dependencies, de-duped) -->
          <template v-for="childId in childPacks" :key="childId">
            <lpm-pack-node
              :pack-id="childId"
              :nodes="nodes"
              :depth="depth + 1"
              :expanded="expanded"
              :selected-packs="selectedPacks"
              :selected-pages="selectedPages"
              :prefixes="prefixes"
              :renames="renames"
              :collisions="collisions"
              :check-title-exists="checkTitleExists"
              @update:selectedPacks="$emit('update:selectedPacks', $event)"
              @update:selectedPages="$emit('update:selectedPages', $event)"
              @update:prefixes="$emit('update:prefixes', $event)"
              @update:renames="$emit('update:renames', $event)"
            />
          </template>
        </template>
      `
    }
  }
};
</script>

<style scoped>
.lpm-tree { overflow-x: auto; }
.lpm-tree-table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }

.lpm-tree-table th,
.lpm-tree-table td {
  padding: 0.4rem 0.6rem;
  text-align: left;
  border-bottom: 1px solid var(--border-color, #ddd);
  vertical-align: middle;
}

.pack-row { background-color: var(--pack-row-bg, #f9f9f9); }
.page-row:hover { background-color: var(--page-hover-bg, #fafafa); }
.lpm-cell-pad-left { padding-left: 2em; font-style: italic; }

.lpm-caret {
  margin-right: 0.4rem;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 0.85rem;
  line-height: 1;
}

.lpm-status-cell { white-space: nowrap; }
.lpm-status-included { color: var(--included-color, #16a34a); margin-right: 6px; }
.lpm-status-warning { color: var(--warning-color, #d97706); }
</style>
