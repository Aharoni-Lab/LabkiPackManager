<template>
  <div class="lpm-dialogs">
    <!-- Import confirmation dialog -->
    <cdx-dialog
      v-model:open="localImportOpen"
      :title="importTitle"
    >
      <p>{{ importMessage }}</p>

      <template #footer>
        <cdx-button appearance="primary" @click="confirmImport">
          Confirm
        </cdx-button>
        <cdx-button appearance="quiet" @click="closeImport">
          Cancel
        </cdx-button>
      </template>
    </cdx-dialog>

    <!-- Update confirmation dialog -->
    <cdx-dialog
      v-model:open="localUpdateOpen"
      :title="updateTitle"
    >
      <p>{{ updateMessage }}</p>

      <template #footer>
        <cdx-button appearance="primary" @click="confirmUpdate">
          Confirm
        </cdx-button>
        <cdx-button appearance="quiet" @click="closeUpdate">
          Cancel
        </cdx-button>
      </template>
    </cdx-dialog>
  </div>
</template>

<script>
/**
 * LpmDialogs – Confirmation Dialogs for Import / Update
 * ------------------------------------------------------------
 * Displays Codex dialogs for confirming user actions.
 * Uses local v-model proxies to manage open state with
 * parent-driven props, enabling smooth transitions.
 */

export default {
  name: 'LpmDialogs',

  props: {
    /** Whether the import dialog is open. */
    showImportConfirm: { type: Boolean, required: true },

    /** Whether the update dialog is open. */
    showUpdateConfirm: { type: Boolean, required: true },

    /** Optional override text for import dialog title. */
    importTitle: {
      type: String,
      default: 'Confirm Import'
    },

    /** Optional override text for update dialog title. */
    updateTitle: {
      type: String,
      default: 'Confirm Update'
    },

    /** Optional i18n message for import dialog body. */
    importMessage: {
      type: String,
      default: 'Import selected packs and pages?'
    },

    /** Optional i18n message for update dialog body. */
    updateMessage: {
      type: String,
      default: 'Update existing pages from the selected repository?'
    }
  },

  emits: [
    'confirm-import',
    'close-import',
    'confirm-update',
    'close-update',
    'update:showImportConfirm',
    'update:showUpdateConfirm'
  ],

  data() {
    return {
      /** Local proxy states for v-model bindings. */
      localImportOpen: this.showImportConfirm,
      localUpdateOpen: this.showUpdateConfirm
    };
  },

  watch: {
    // Keep local open states in sync with parent props
    showImportConfirm(val) {
      this.localImportOpen = val;
    },
    showUpdateConfirm(val) {
      this.localUpdateOpen = val;
    },

    // Emit back to parent on local state changes (Codex close events)
    localImportOpen(val) {
      this.$emit('update:showImportConfirm', val);
    },
    localUpdateOpen(val) {
      this.$emit('update:showUpdateConfirm', val);
    }
  },

  methods: {
    /** Emit confirm and close events for import dialog. */
    confirmImport() {
      this.$emit('confirm-import');
      this.$emit('update:showImportConfirm', false);
    },
    closeImport() {
      this.$emit('close-import');
      this.$emit('update:showImportConfirm', false);
    },

    /** Emit confirm and close events for update dialog. */
    confirmUpdate() {
      this.$emit('confirm-update');
      this.$emit('update:showUpdateConfirm', false);
    },
    closeUpdate() {
      this.$emit('close-update');
      this.$emit('update:showUpdateConfirm', false);
    }
  }
};
</script>

<style scoped>
.lpm-dialogs {
  /* Minor layout separation for better readability */
  display: contents;
}
</style>
