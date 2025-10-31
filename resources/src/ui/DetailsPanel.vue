<template>
  <div class="details-panel">
    <h3>{{ $t('labkipackmanager-details-title') }}</h3>
    
    <div class="panel-content">
      <!-- Warnings Section -->
      <div v-if="store.warnings.length > 0" class="warnings-section">
        <h4>{{ $t('labkipackmanager-warnings') }}</h4>
        <cdx-message
          v-for="(warning, index) in store.warnings"
          :key="index"
          type="warning"
          :inline="true"
        >
          {{ warning }}
        </cdx-message>
      </div>
      
      <!-- Selected Packs Section -->
      <div v-if="selectedPacks.length > 0" class="selected-packs-section">
        <h4>{{ $t('labkipackmanager-selected-packs') }}</h4>
        <div class="packs-list">
          <div
            v-for="pack in selectedPacks"
            :key="pack.name"
            class="pack-item"
          >
            <div class="pack-header">
              <strong>{{ pack.name }}</strong>
              <span v-if="pack.state.auto_selected" class="pack-badge auto">
                {{ $t('labkipackmanager-auto-selected') }}
              </span>
              <span v-else class="pack-badge manual">
                {{ $t('labkipackmanager-manually-selected') }}
              </span>
            </div>
            <div class="pack-details">
              <div v-if="pack.state.action">
                <small>{{ $t('labkipackmanager-action') }}: {{ pack.state.action }}</small>
              </div>
              <div v-if="pack.state.target_version">
                <small>{{ $t('labkipackmanager-version') }}: {{ pack.state.target_version }}</small>
              </div>
              <div v-if="pack.state.prefix">
                <small>{{ $t('labkipackmanager-prefix') }}: {{ pack.state.prefix }}</small>
              </div>
              <div v-if="pack.pageCount > 0">
                <small>{{ $t('labkipackmanager-pages') }}: {{ pack.pageCount }}</small>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <div v-else class="no-selection">
        <p>{{ $t('labkipackmanager-no-packs-selected') }}</p>
      </div>
      
      <!-- State Hash (Debug) -->
      <div v-if="store.stateHash" class="state-info">
        <small>{{ $t('labkipackmanager-state-hash') }}: <code>{{ store.stateHash }}</code></small>
      </div>
      
      <!-- Action Buttons -->
      <div class="action-buttons">
        <cdx-button
          action="progressive"
          weight="primary"
          :disabled="store.busy || selectedPacks.length === 0"
          @click="onApply"
        >
          {{ $t('labkipackmanager-apply') }}
        </cdx-button>
        
        <cdx-button
          action="default"
          :disabled="store.busy"
          @click="onRefresh"
        >
          {{ $t('labkipackmanager-refresh') }}
        </cdx-button>
        
        <cdx-button
          action="destructive"
          weight="quiet"
          :disabled="store.busy"
          @click="onClear"
        >
          {{ $t('labkipackmanager-clear') }}
        </cdx-button>
      </div>
      
      <!-- Operation Status -->
      <cdx-message
        v-if="operationMessage"
        type="success"
        :inline="true"
      >
        {{ operationMessage }}
      </cdx-message>
      
      <cdx-message
        v-if="errorMessage"
        type="error"
        :inline="true"
      >
        {{ errorMessage }}
      </cdx-message>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { CdxButton, CdxMessage } from '@wikimedia/codex';
import { store } from '../state/store';
import { packsAction } from '../api/endpoints';
import { mergeDiff } from '../state/merge';

const operationMessage = ref('');
const errorMessage = ref('');

const selectedPacks = computed(() => {
  const packs = [];
  
  for (const [name, state] of Object.entries(store.packs)) {
    if (state.selected || state.auto_selected) {
      packs.push({
        name,
        state,
        pageCount: Object.keys(state.pages || {}).length,
      });
    }
  }
  
  return packs;
});

async function onApply() {
  if (store.busy) return;
  
  try {
    store.busy = true;
    operationMessage.value = '';
    errorMessage.value = '';
    
    const response = await packsAction({
      command: 'apply',
      repo_url: store.repoUrl,
      ref: store.ref,
      data: {},
    });
    
    // Merge diff
    mergeDiff(store.packs, response.diff);
    store.stateHash = response.state_hash;
    store.warnings = response.warnings;
    
    // Show operation info if available
    if (response.operation?.operation_id) {
      operationMessage.value = $t('labkipackmanager-apply-success-with-id')
        .replace('$1', response.operation.operation_id);
    } else {
      operationMessage.value = $t('labkipackmanager-apply-success');
    }
  } catch (e) {
    errorMessage.value = e instanceof Error ? e.message : String(e);
  } finally {
    store.busy = false;
  }
}

async function onRefresh() {
  if (store.busy) return;
  
  try {
    store.busy = true;
    operationMessage.value = '';
    errorMessage.value = '';
    
    const response = await packsAction({
      command: 'refresh',
      repo_url: store.repoUrl,
      ref: store.ref,
      data: {},
    });
    
    // Merge diff
    mergeDiff(store.packs, response.diff);
    store.stateHash = response.state_hash;
    store.warnings = response.warnings;
    
    operationMessage.value = $t('labkipackmanager-refresh-success');
  } catch (e) {
    errorMessage.value = e instanceof Error ? e.message : String(e);
  } finally {
    store.busy = false;
  }
}

async function onClear() {
  if (store.busy) return;
  
  if (!confirm($t('labkipackmanager-clear-confirm'))) {
    return;
  }
  
  try {
    store.busy = true;
    operationMessage.value = '';
    errorMessage.value = '';
    
    const response = await packsAction({
      command: 'clear',
      repo_url: store.repoUrl,
      ref: store.ref,
      data: {},
    });
    
    // For Clear, replace the entire packs state (don't merge)
    // This ensures all actions are reset to their initial state
    store.packs = response.diff;
    store.stateHash = response.state_hash;
    store.warnings = response.warnings;
    
    operationMessage.value = $t('labkipackmanager-clear-success');
  } catch (e) {
    errorMessage.value = e instanceof Error ? e.message : String(e);
  } finally {
    store.busy = false;
  }
}

// Helper for i18n
function $t(key) {
  return mw.msg(key);
}
</script>

<style scoped>
.details-panel {
  padding: 20px;
  background: white;
  border: 1px solid #c8ccd1;
  border-radius: 8px;
}

h3 {
  margin-top: 0;
  margin-bottom: 16px;
  font-size: 1.2em;
}

h4 {
  margin-top: 0;
  margin-bottom: 12px;
  font-size: 1em;
  color: #54595d;
}

.panel-content {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.warnings-section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.selected-packs-section {
  /* Styling */
}

.packs-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.pack-item {
  padding: 12px;
  background: #f8f9fa;
  border-radius: 4px;
  border: 1px solid #eaecf0;
}

.pack-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.pack-badge {
  font-size: 0.75em;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: normal;
}

.pack-badge.auto {
  background: #fef6e7;
  color: #ac6600;
}

.pack-badge.manual {
  background: #eaf3ff;
  color: #36c;
}

.pack-details {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: #54595d;
}

.no-selection {
  text-align: center;
  padding: 20px;
  color: #72777d;
}

.no-selection p {
  margin: 0;
}

.state-info {
  padding: 8px;
  background: #f8f9fa;
  border-radius: 4px;
  color: #72777d;
}

.state-info code {
  font-family: monospace;
  font-size: 0.9em;
}

.action-buttons {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}
</style>

