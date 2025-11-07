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
        <span class="warning-icon">⚠️</span>
        <div class="warning-copy">
          <strong :style="diffStyles.warningHeading">Heads up</strong>
          <span :style="diffStyles.warningText">Syncing with the backend will discard the pending changes listed below.</span>
        </div>
      </div>

      <div v-if="diffList.length > 0" class="diff-summary" :style="diffStyles.summary">
        {{ diffList.length === 1 ? '1 pack needs review.' : diffList.length + ' packs need review.' }}
      </div>

      <div class="diff-container">
        <div v-if="diffList.length === 0" class="diff-empty">
          No detailed differences were detected, but hashes still differ. Consider syncing with the backend.
        </div>
        <div v-else class="diff-list">
          <div v-for="pack in diffList" :key="pack.packName" class="diff-pack">
            <div class="diff-pack-header">
              <h4 class="diff-pack-name">{{ pack.packName }}</h4>
              <span class="diff-chip">{{ pack.changeCount === 1 ? '1 change' : pack.changeCount + ' changes' }}</span>
            </div>

            <div v-if="pack.fieldChanges.length" class="diff-section">
            <div class="diff-section-title">Pack fields</div>
              <div
                v-for="field in pack.fieldChanges"
                :key="field.name"
                class="diff-row"
              >
                <span class="diff-label" :style="diffStyles.label">{{ formatFieldName(field.name) }}</span>
                <span class="diff-value diff-client" :style="[diffStyles.valueBase, diffStyles.valueClient]">
                  <span class="diff-pill diff-pill-old" :style="[diffStyles.pillBase, diffStyles.pillOld]">Was</span>
                  <span class="diff-text" :style="diffStyles.valueText">{{ formatDifferenceValue(field.client) }}</span>
                </span>
                <span class="diff-arrow" :style="diffStyles.arrow">→</span>
                <span class="diff-value diff-server" :style="[diffStyles.valueBase, diffStyles.valueServer]">
                  <span class="diff-pill diff-pill-new" :style="[diffStyles.pillBase, diffStyles.pillNew]">Now</span>
                  <span class="diff-text" :style="diffStyles.valueText">{{ formatDifferenceValue(field.server) }}</span>
                </span>
              </div>
            </div>

            <div
              v-for="page in pack.pageChanges"
              :key="page.pageName"
              class="diff-section"
            >
              <div class="diff-section-title">
                Page: <span class="diff-page-name">{{ page.pageName }}</span>
              </div>
              <div
                v-for="pageField in page.fields"
                :key="pageField.name"
                class="diff-row"
              >
                <span class="diff-label" :style="diffStyles.label">{{ formatFieldName(pageField.name) }}</span>
                <span class="diff-value diff-client" :style="[diffStyles.valueBase, diffStyles.valueClient]">
                  <span class="diff-pill diff-pill-old" :style="[diffStyles.pillBase, diffStyles.pillOld]">Was</span>
                  <span class="diff-text" :style="diffStyles.valueText">{{ formatDifferenceValue(pageField.client) }}</span>
                </span>
                <span class="diff-arrow" :style="diffStyles.arrow">→</span>
                <span class="diff-value diff-server" :style="[diffStyles.valueBase, diffStyles.valueServer]">
                  <span class="diff-pill diff-pill-new" :style="[diffStyles.pillBase, diffStyles.pillNew]">Now</span>
                  <span class="diff-text" :style="diffStyles.valueText">{{ formatDifferenceValue(pageField.server) }}</span>
                </span>
              </div>
            </div>
          </div>
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
      const fieldChanges = Object.entries(packDiff?.fields ?? {})
        .map(([name, values]) => ({
          name,
          client: values?.client,
          server: values?.server,
        }))
        .filter((entry) => !isValuesEqual(entry.client, entry.server));

      const pageChanges = Object.entries(packDiff?.pages ?? {})
        .map(([pageName, pageDiff]) => {
          const pageFields = Object.entries(pageDiff ?? {})
            .map(([name, values]) => ({
              name,
              client: values?.client,
              server: values?.server,
            }))
            .filter((entry) => !isValuesEqual(entry.client, entry.server));

          return {
            pageName,
            fields: pageFields,
          };
        })
        .filter((page) => page.fields.length > 0);

      const changeCount =
        fieldChanges.length +
        pageChanges.reduce((total, page) => total + page.fields.length, 0);

      if (changeCount === 0) {
        return null;
      }

      return { packName, fieldChanges, pageChanges, changeCount };
    })
    .filter((entry) => entry !== null);
});

const diffStyles = Object.freeze({
  summary: {
    fontSize: '0.95em',
    fontWeight: 600,
    color: '#202122',
    background: '#eef6ff',
    border: '1px solid #cde3ff',
    borderRadius: '8px',
    padding: '10px 12px',
  },
  warningHeading: {
    fontWeight: 700,
    display: 'block',
    marginBottom: '2px',
  },
  warningText: {
    lineHeight: '1.4',
  },
  label: {
    fontWeight: 600,
    color: '#202122',
    textTransform: 'capitalize',
    display: 'inline-flex',
    alignItems: 'center',
    gap: '4px',
    minWidth: '0',
  },
  arrow: {
    color: '#5e6a76',
    fontSize: '1.2em',
    fontWeight: 600,
  },
  valueBase: {
    display: 'inline-flex',
    alignItems: 'center',
    gap: '8px',
    padding: '6px 10px',
    borderRadius: '8px',
    fontFamily: "'Menlo', 'Monaco', 'Consolas', monospace",
    wordBreak: 'break-word',
  },
  valueClient: {
    background: '#ffe6e2',
    color: '#b42318',
    border: '1px solid #f5b5ac',
  },
  valueServer: {
    background: '#e6f6ec',
    color: '#11743a',
    border: '1px solid #9fdcc2',
  },
  valueText: {
    whiteSpace: 'pre-wrap',
  },
  pillBase: {
    fontSize: '0.7em',
    fontWeight: 700,
    textTransform: 'uppercase',
    padding: '2px 6px',
    borderRadius: '999px',
    letterSpacing: '0.5px',
  },
  pillOld: {
    background: 'rgba(180, 35, 24, 0.12)',
    color: '#b42318',
  },
  pillNew: {
    background: 'rgba(17, 116, 58, 0.12)',
    color: '#11743a',
  },
});

function isValuesEqual(a, b) {
  if (a === b) {
    return true;
  }

  if (
    (a === undefined && b === null) ||
    (b === undefined && a === null)
  ) {
    return true;
  }

  if (typeof a === 'object' && typeof b === 'object') {
    try {
      return JSON.stringify(a) === JSON.stringify(b);
    } catch (e) {
      return false;
    }
  }

  return false;
}

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

function formatFieldName(name) {
  if (!name) {
    return '';
  }
  return name
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
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
  display: flex;
  align-items: flex-start;
  gap: 12px;
  background: #fff8ed;
  border: 1px solid #f7d9a8;
  color: #6b4b16;
  padding: 12px 16px;
  border-radius: 10px;
  font-size: 0.92em;
}

.warning-icon {
  font-size: 1.4em;
  line-height: 1;
  margin-top: 2px;
}

.warning-copy {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.diff-summary {
  font-size: 0.95em;
  font-weight: 600;
  color: #202122;
  background: #eef6ff;
  border: 1px solid #cde3ff;
  border-radius: 8px;
  padding: 10px 12px;
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

.diff-pack {
  border: 1px solid #dfe3e8;
  border-radius: 10px;
  padding: 14px 16px;
  background: white;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.diff-pack-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
}

.diff-pack-name {
  margin: 0;
  font-size: 1.05em;
  font-weight: 700;
  color: #202122;
  display: flex;
  align-items: center;
  gap: 6px;
}

.diff-chip {
  background: #edf2ff;
  color: #3246d3;
  border-radius: 999px;
  padding: 2px 10px;
  font-size: 0.75em;
  font-weight: 600;
  white-space: nowrap;
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

.diff-page-name {
  color: #202122;
}

.diff-row {
  display: grid;
  grid-template-columns: 130px 1fr 20px 1fr;
  gap: 8px;
  padding: 6px 0;
  align-items: center;
  font-size: 0.9em;
}

.diff-label {
  font-weight: 600;
  color: #202122;
  text-transform: capitalize;
}

.diff-label::after {
  content: ':';
  margin-left: 4px;
}

.diff-value {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-radius: 8px;
  font-family: 'Menlo', 'Monaco', 'Consolas', monospace;
  word-break: break-word;
}

.diff-value.diff-client {
  background: #ffe6e2;
  color: #b42318;
  border: 1px solid #f5b5ac;
}

.diff-value.diff-server {
  background: #e6f6ec;
  color: #11743a;
  border: 1px solid #9fdcc2;
}

.diff-pill {
  font-size: 0.7em;
  font-weight: 700;
  text-transform: uppercase;
  padding: 2px 6px;
  border-radius: 999px;
  letter-spacing: 0.5px;
}

.diff-pill-old {
  background: rgba(171, 31, 38, 0.12);
  color: #ab1f26;
}

.diff-pill-new {
  background: rgba(11, 96, 59, 0.12);
  color: #0b603b;
}

.diff-text {
  white-space: pre-wrap;
}

.diff-arrow {
  text-align: center;
  color: #54595d;
  font-size: 1.2em;
}

.modal-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
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


