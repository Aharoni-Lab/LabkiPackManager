<template>
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
        <tbody v-if="data?.hierarchy?.packs?.length" id="lpm-tree-body">
          <template v-for="pack in data.hierarchy.packs" :key="pack.name">
            <tr class="pack-row">
              <td><strong>{{ pack.name }}</strong></td>
              <td><cdx-checkbox :model-value="selectedPacks[pack.name]" placeholder="" @update:model-value="val => updateSelectedPack(pack.name, val)" /></td>
              <td><cdx-text-input :model-value="prefixes[pack.name]" placeholder="prefix" @update:model-value="val => updatePrefix(pack.name, val)" /></td>
              <td></td>
            </tr>
            <tr v-for="page in (pack.pages || [])" :key="page.name" class="page-row">
              <td class="lpm-cell-pad-left">{{ page.name }}</td>
              <td><cdx-checkbox :model-value="selectedPages[pageKey(pack, page)]" placeholder="" @update:model-value="val => updateSelectedPage(pack, page, val)" /></td>
              <td><cdx-text-input :model-value="renames[pageKey(pack, page)]" placeholder="rename" @update:model-value="val => updateRename(pack, page, val)" /></td>
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
</template>

<script>
export default {
  name: 'LpmTree',
  props: {
    data: { type: Object, default: null },
    selectedPacks: { type: Object, required: true },
    selectedPages: { type: Object, required: true },
    prefixes: { type: Object, required: true },
    renames: { type: Object, required: true }
  },
  emits: ['update:selectedPacks', 'update:selectedPages', 'update:prefixes', 'update:renames'],
  methods: {
    pageKey(pack, page) { return `${pack.name}::${page.name}`; },
    finalName(pack, page) {
      const key = this.pageKey(pack, page);
      const rename = this.renames[key];
      return rename && rename.trim() ? rename.trim() : page.name;
    },
    updateSelectedPack(packName, val) {
      this.$emit('update:selectedPacks', { ...this.selectedPacks, [packName]: val });
    },
    updatePrefix(packName, val) {
      this.$emit('update:prefixes', { ...this.prefixes, [packName]: val });
    },
    updateSelectedPage(pack, page, val) {
      const key = this.pageKey(pack, page);
      this.$emit('update:selectedPages', { ...this.selectedPages, [key]: val });
    },
    updateRename(pack, page, val) {
      const key = this.pageKey(pack, page);
      this.$emit('update:renames', { ...this.renames, [key]: val });
    }
  }
};
</script>


