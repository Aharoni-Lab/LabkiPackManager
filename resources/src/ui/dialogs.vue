<template>
  <div>
    <cdx-dialog
      v-model:open="showImportConfirm"
      title="Confirm Import"
    >
      <p>Import selected packs and pages?</p>
      <template #footer>
        <cdx-button @click="$emit('confirm-import')">Confirm</cdx-button>
        <cdx-button @click="$emit('close-import')">Cancel</cdx-button>
      </template>
    </cdx-dialog>

    <cdx-dialog
      v-model:open="showUpdateConfirm"
      title="Confirm Update"
    >
      <p>Update existing pages from the selected repository?</p>
      <template #footer>
        <cdx-button @click="$emit('confirm-update')">Confirm</cdx-button>
        <cdx-button @click="$emit('close-update')">Cancel</cdx-button>
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
  }
};
</script>


