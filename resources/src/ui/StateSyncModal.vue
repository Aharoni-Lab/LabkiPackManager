<template>
  <cdx-dialog
    v-model:open="isOpen"
    :title="$t('labkipackmanager-state-out-of-sync-title') || 'State out of sync'"
    :primary-action="primaryAction"
    :default-action="defaultAction"
    @primary="onPrimary"
    @default="onDefault"
  >
    <div class="state-sync-modal-content">
      <p class="state-sync-intro">{{ message }}</p>

      <div class="modal-warning">
        <strong>Heads up:</strong> syncing with the backend will discard the pending changes listed below.
      </div>

      <div class="diff-container">
        <div v-if="diffList.length === 0" class="diff-empty">
          No detailed differences were detected, but hashes still differ. Consider syncing with the backend.
        </div>
        <div v-else class="diff-list">
          <div v-for="pack in diffList" :key="pack.packName" class="diff-pack">
            <h4>{{ pack.packName }}</h4>

            <div v-if="pack.fields.length" class="diff-section">
              <div class="diff-section-title">Pack fields</div>
              <div v-for="field in pack.fields" :key="field.name" class="diff-row">
                <span class="diff-label">{{ field.name }}</span>
                <span class="diff-client">{{ formatDifferenceValue(field.client) }}</span>
                <span class="diff-arrow">→</span>
                <span class="diff-server">{{ formatDifferenceValue(field.server) }}</span>
              </div>
            </div>

            <div v-for="page in pack.pages" :key="page.pageName" class="diff-section">
              <div class="diff-section-title">Page: {{ page.pageName }}</div>
              <div v-for="pageField in page.fields" :key="pageField.name" class="diff-row">
                <span class="diff-label">{{ pageField.name }}</span>
                <span class="diff-client">{{ formatDifferenceValue(pageField.client) }}</span>
                <span class="diff-arrow">→</span>
                <span class="diff-server">{{ formatDifferenceValue(pageField.server) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="reconcile-summary">
        <div v-if="reconcileCommands.length > 0">
          <strong>We can try to reapply {{ reconcileCommands.length }} change(s):</strong>
          <ul>
            <li v-for="(command, index) in reconcileCommands" :key="index">
              {{ command.command }}
              <template v-if="command.data && command.data.pack_name"> — {{ command.data.pack_name }}</template>
              <template v-if="command.data && command.data.page_name"> / {{ command.data.page_name }}</template>
            </li>
          </ul>
        </div>
        <div v-else>
          No automatic reconciliation steps were detected.
        </div>
      </div>

      <div class="modal-actions">
        <cdx-button
          weight="normal"
          :disabled="attemptingReconcile || reconcileCommands.length === 0"
          @click="onReconcile"
        >
          Try to reconcile & reapply
        </cdx-button>
      </div>

      <div v-if="reconcileMessage" class="modal-status">
        {{ reconcileMessage }}
      </div>
    </div>
  </cdx-dialog>
</template>

<script setup>
import { computed } from 'vue';
import { CdxDialog, CdxButton } from '@wikimedia/codex';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  message: { type: String, default: '' },
  differences: { type: Object, default: () => ({}) },
  reconcileCommands: { type: Array, default: () => [] },
  attemptingReconcile: { type: Boolean, default: false },
  reconcileMessage: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'sync', 'cancel', 'reconcile']);

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
});

const diffList = computed(() => {
  if (!props.differences || typeof props.differences !== 'object') {
    return [];
  }

  return Object.entries(props.differences)
    .map(([packName, packDiff]) => {
      const fields = Object.entries(packDiff?.fields ?? {}).map(([name, values]) => ({
        name,
        client: values?.client,
        server: values?.server,
      }));

      const pages = Object.entries(packDiff?.pages ?? {})
        .map(([pageName, pageDiff]) => {
          const pageFields = Object.entries(pageDiff ?? {}).map(([name, values]) => ({
            name,
            client: values?.client,
            server: values?.server,
          }));
          return {
            pageName,
            fields: pageFields,
          };
        })
        .filter((page) => page.fields.length > 0);

      if (fields.length === 0 && pages.length === 0) {
        return null;
      }

      return { packName, fields, pages };
    })
    .filter((entry) => entry !== null);
});

const primaryAction = computed(() => ({
  label: $t('labkipackmanager-sync-frontend-with-backend') || 'Sync frontend with backend',
  actionType: 'progressive',
  disabled: props.attemptingReconcile,
}));

const defaultAction = computed(() => ({
  label: $t('labkipackmanager-cancel') || 'Cancel',
  disabled: props.attemptingReconcile,
}));

function onPrimary() {
  emit('sync');
}

function onDefault() {
  isOpen.value = false;
  emit('cancel');
}

function onReconcile() {
  if (!props.attemptingReconcile && props.reconcileCommands.length > 0) {
    emit('reconcile');
  }
}

function formatDifferenceValue(value) {
  if (value === null || value === undefined) {
    return '(empty)';
  }
  if (typeof value === 'string') {
    return value.trim() === '' ? '(empty string)' : value;
  }
  if (typeof value === 'boolean' || typeof value === 'number') {
    return String(value);
  }
  try {
    return JSON.stringify(value);
  } catch (e) {
    return String(value);
  }
}

function $t(key) {
  return mw.msg(key);
}
</script>

<style scoped>
.state-sync-modal-content {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-height: 70vh;
  overflow-y: auto;
}

.state-sync-intro {
  margin: 0;
  color: #54595d;
  line-height: 1.4;
}

.modal-warning {
  background: #fff4e5;
  border: 1px solid #f0c77e;
  color: #6b4b16;
  padding: 12px;
  border-radius: 8px;
  font-size: 0.9em;
}

.diff-container {
  background: #f8f9fa;
  border-radius: 8px;
  border: 1px solid #eaecf0;
  padding: 12px;
  max-height: 40vh;
  overflow-y: auto;
}

.diff-empty {
  color: #54595d;
  font-style: italic;
}

.diff-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.diff-pack h4 {
  margin: 0 0 6px 0;
  font-size: 1em;
  color: #202122;
}

.diff-section {
  margin-bottom: 8px;
  padding-left: 8px;
  border-left: 3px solid #c8ccd1;
}

.diff-section-title {
  font-size: 0.85em;
  font-weight: 700;
  text-transform: uppercase;
  color: #72777d;
  margin-bottom: 4px;
}

.diff-row {
  display: grid;
  grid-template-columns: 110px 1fr 20px 1fr;
  gap: 6px;
  padding: 4px 0;
  align-items: center;
  font-size: 0.9em;
}

.diff-label {
  font-weight: 600;
  color: #202122;
}

.diff-client {
  color: #ac6600;
  font-family: 'Menlo', 'Monaco', 'Consolas', monospace;
  word-break: break-word;
}

.diff-server {
  color: #14866d;
  font-family: 'Menlo', 'Monaco', 'Consolas', monospace;
  word-break: break-word;
}

.diff-arrow {
  text-align: center;
  color: #54595d;
}

.reconcile-summary {
  background: #f5f8ff;
  border: 1px solid #a8c9f0;
  border-radius: 8px;
  padding: 12px;
  font-size: 0.9em;
  color: #202122;
}

.reconcile-summary ul {
  margin: 6px 0 0 16px;
  padding: 0;
}

.modal-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
}

.modal-status {
  font-size: 0.9em;
  color: #202122;
  background: #eef2ff;
  border: 1px solid #a8c9f0;
  border-radius: 8px;
  padding: 10px;
}
</style>


