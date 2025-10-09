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
          </tr>
        </thead>

        <!-- Main pack/page listing -->
        <tbody v-if="hasData" id="lpm-tree-body">
          <template v-for="pack in data.hierarchy.packs" :key="pack.name">
            <!-- Pack row -->
            <tr class="pack-row">
              <td><strong>{{ pack.name }}</strong></td>
              <td>
                <cdx-checkbox
                  :model-value="selectedPacks[pack.name]"
                  :aria-label="`Select pack ${pack.name}`"
                  @update:model-value="val => updateSelectedPack(pack.name, val)"
                />
              </td>
              <td>
                <cdx-text-input
                  :model-value="prefixes[pack.name]"
                  placeholder="prefix"
                  aria-label="Prefix"
                  @update:model-value="val => updatePrefix(pack.name, val)"
                />
              </td>
              <td></td>
            </tr>

            <!-- Page rows -->
            <tr
              v-for="page in pack.pages || []"
              :key="pageKey(pack, page)"
              class="page-row"
            >
              <td class="lpm-cell-pad-left">{{ page.name }}</td>
              <td>
                <cdx-checkbox
                  :model-value="selectedPages[pageKey(pack, page)]"
                  :aria-label="`Select page ${page.name}`"
                  @update:model-value="val => updateSelectedPage(pack, page, val)"
                />
              </td>
              <td>
                <cdx-text-input
                  :model-value="renames[pageKey(pack, page)]"
                  placeholder="rename"
                  aria-label="Rename page"
                  @update:model-value="val => updateRename(pack, page, val)"
                />
              </td>
              <td>{{ finalName(pack, page) }}</td>
            </tr>
          </template>
        </tbody>

        <!-- Fallback when no data -->
        <tbody v-else>
          <tr>
            <td colspan="4"><em>No data loaded.</em></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
/**
 * LpmTree – Pack/Page Selection Table
 * ------------------------------------------------------------
 * Displays hierarchical pack and page data from a manifest,
 * allowing selection, prefixing, and renaming of pages.
 *
 * Props:
 *   - data: Manifest data containing hierarchy.packs[]
 *   - selectedPacks, selectedPages: reactive selection maps
 *   - prefixes, renames: reactive text maps
 *
 * Emits:
 *   - update:selectedPacks
 *   - update:selectedPages
 *   - update:prefixes
 *   - update:renames
 */

export default {
  name: 'LpmTree',

  props: {
    /** Full manifest data object (may be null). */
    data: { type: Object, default: null },

    /** Map of packName → boolean for selection state. */
    selectedPacks: { type: Object, required: true },

    /** Map of "pack::page" → boolean for selection state. */
    selectedPages: { type: Object, required: true },

    /** Map of packName → prefix string. */
    prefixes: { type: Object, required: true },

    /** Map of "pack::page" → renamed page string. */
    renames: { type: Object, required: true }
  },

  emits: [
    'update:selectedPacks',
    'update:selectedPages',
    'update:prefixes',
    'update:renames'
  ],

  computed: {
    /** Whether valid pack data is present for rendering. */
    hasData() {
      return (
        this.data?.hierarchy?.packs &&
        Array.isArray(this.data.hierarchy.packs) &&
        this.data.hierarchy.packs.length > 0
      );
    }
  },

  methods: {
    /** Compute composite key for a pack/page pair. */
    pageKey(pack, page) {
      return `${pack.name}::${page.name}`;
    },

    /** Compute the final page name considering rename overrides. */
    finalName(pack, page) {
      const key = this.pageKey(pack, page);
      const rename = this.renames[key];
      return rename?.trim() || page.name;
    },

    /** Emit updated pack selection map. */
    updateSelectedPack(packName, val) {
      this.$emit('update:selectedPacks', {
        ...this.selectedPacks,
        [packName]: val
      });
    },

    /** Emit updated prefix map. */
    updatePrefix(packName, val) {
      this.$emit('update:prefixes', {
        ...this.prefixes,
        [packName]: val
      });
    },

    /** Emit updated page selection map. */
    updateSelectedPage(pack, page, val) {
      const key = this.pageKey(pack, page);
      this.$emit('update:selectedPages', {
        ...this.selectedPages,
        [key]: val
      });
    },

    /** Emit updated rename map. */
    updateRename(pack, page, val) {
      const key = this.pageKey(pack, page);
      this.$emit('update:renames', {
        ...this.renames,
        [key]: val
      });
    }
  }
};
</script>

<style scoped>
.lpm-tree {
  overflow-x: auto;
}

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
}

.pack-row {
  background-color: var(--pack-row-bg, #f9f9f9);
}

.page-row:hover {
  background-color: var(--page-hover-bg, #fafafa);
}

.lpm-cell-pad-left {
  padding-left: 2em;
  font-style: italic;
}
</style>
