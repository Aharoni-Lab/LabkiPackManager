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
        <tbody id="lpm-tree-body" v-if="data?.hierarchy?.packs?.length">
          <template v-for="pack in data.hierarchy.packs" :key="pack.name">
            <tr class="pack-row">
              <td><strong>{{ pack.name }}</strong></td>
              <td><cdx-checkbox v-model="selectedPacks[pack.name]" /></td>
              <td><cdx-text-input v-model="prefixes[pack.name]" placeholder="prefix" /></td>
              <td></td>
            </tr>
            <tr class="page-row" v-for="page in (pack.pages || [])" :key="page.name">
              <td class="lpm-cell-pad-left">{{ page.name }}</td>
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
  methods: {
    pageKey(pack, page) { return `${pack.name}::${page.name}`; },
    finalName(pack, page) {
      const key = this.pageKey(pack, page);
      const rename = this.renames[key];
      return rename && rename.trim() ? rename.trim() : page.name;
    }
  }
};
</script>


