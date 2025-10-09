<template>
  <div>
    <cdx-dialog
      v-model:open="localShowImport"
      title="Confirm Import"
    >
      <p>Import selected packs and pages?</p>
      <template #footer>
        <cdx-button @click="onConfirmImport">Confirm</cdx-button>
        <cdx-button @click="onCloseImport">Cancel</cdx-button>
      </template>
    </cdx-dialog>

    <cdx-dialog
      v-model:open="localShowUpdate"
      title="Confirm Update"
    >
      <p>Update existing pages from the selected repository?</p>
      <template #footer>
        <cdx-button @click="onConfirmUpdate">Confirm</cdx-button>
        <cdx-button @click="onCloseUpdate">Cancel</cdx-button>
      </template>
    </cdx-dialog>
  </div>
</template>

<script>
export default {
  name: 'LpmDialogs',
  props: {
    showImportConfirm: { type: Boolean, required: true },
    showUpdateConfirm: { type: Boolean, required: true }
  },
  emits: ['confirm-import', 'close-import', 'confirm-update', 'close-update', 'update:showImportConfirm', 'update:showUpdateConfirm'],
  data() {
    return {
      localShowImport: this.showImportConfirm,
      localShowUpdate: this.showUpdateConfirm
    };
  },
  watch: {
    showImportConfirm(val) { this.localShowImport = val; },
    localShowImport(val) { this.$emit('update:showImportConfirm', val); },
    showUpdateConfirm(val) { this.localShowUpdate = val; },
    localShowUpdate(val) { this.$emit('update:showUpdateConfirm', val); }
  },
  methods: {
    onConfirmImport() { this.$emit('confirm-import'); },
    onCloseImport() { this.$emit('close-import'); },
    onConfirmUpdate() { this.$emit('confirm-update'); },
    onCloseUpdate() { this.$emit('close-update'); }
  }
};
</script>


