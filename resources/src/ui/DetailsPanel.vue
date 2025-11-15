<template>
  <div class="details-panel">
    <StateSyncModal
      v-model="stateSyncModal.visible"
      :message="stateSyncModal.message"
      :differences="stateSyncModal.differences"
      :reconcile-commands="stateSyncModal.reconcileCommands"
      :attempting-reconcile="stateSyncModal.attemptingReconcile"
      :reconcile-message="stateSyncModal.reconcileMessage"
      @sync="syncFrontendWithBackend"
      @cancel="closeStateSyncModal"
      @reconcile="reconcileAndReapply"
    />

    <div class="panel-header">
      <h3>{{ $t('labkipackmanager-details-title') }}</h3>
      <div v-if="selectedPacks.length > 0" class="pack-count-badge">
        {{ selectedPacks.length }} {{ selectedPacks.length === 1 ? 'pack' : 'packs' }}
      </div>
    </div>

    <div class="panel-content">
      <!-- Warnings Section -->
      <div v-if="store.warnings.length > 0" class="warnings-section">
        <div class="section-header">
          <span class="section-icon">⚠️</span>
          <h4>{{ $t('labkipackmanager-warnings') }}</h4>
        </div>
        <div class="warnings-list">
          <cdx-message
            v-for="(warning, index) in store.warnings"
            :key="index"
            type="warning"
            :inline="true"
          >
            {{ warning }}
          </cdx-message>
        </div>
      </div>

      <!-- Selected Packs Section -->
      <div v-if="selectedPacks.length > 0" class="selected-packs-section">
        <div class="section-header">
          <span class="section-icon">📦</span>
          <h4>{{ $t('labkipackmanager-selected-packs') }}</h4>
        </div>
        <div class="packs-grid">
          <div
            v-for="pack in selectedPacks"
            :key="pack.name"
            class="pack-card"
            :style="{
              position: 'relative',
              padding: '12px 14px',
              paddingLeft: packStatuses[pack.name] ? '20px' : '14px',
              background:
                packStatuses[pack.name] === 'running'
                  ? 'linear-gradient(135deg, #f0f5ff 0%, #ffffff 100%)'
                  : packStatuses[pack.name] === 'complete'
                    ? 'linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%)'
                    : packStatuses[pack.name] === 'failed'
                      ? 'linear-gradient(135deg, #fff5f5 0%, #ffffff 100%)'
                      : packStatuses[pack.name] === 'pending'
                        ? '#fafbfc'
                        : 'white',
              border: '2px solid #eaecf0',
              borderLeft:
                packStatuses[pack.name] === 'running'
                  ? '4px solid #3366cc'
                  : packStatuses[pack.name] === 'complete'
                    ? '4px solid #14866d'
                    : packStatuses[pack.name] === 'failed'
                      ? '4px solid #d33'
                      : packStatuses[pack.name] === 'pending'
                        ? '4px solid #c8ccd1'
                        : '2px solid #eaecf0',
              borderRadius: '10px',
              boxShadow: '0 2px 6px rgba(0, 0, 0, 0.08)',
              marginBottom: '10px',
              transition: 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
              overflow: 'hidden',
            }"
          >
            <div
              class="pack-header"
              :style="{
                display: 'flex',
                alignItems: 'flex-start',
                justifyContent: 'space-between',
                gap: '10px',
                marginBottom: '10px',
              }"
            >
              <div
                class="pack-title-section"
                :style="{
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: '10px',
                  flex: '1',
                }"
              >
                <div class="pack-icon" :style="{ fontSize: '1.6em', lineHeight: '1' }">📦</div>
                <div
                  class="pack-name-wrapper"
                  :style="{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: '6px',
                    minWidth: '0',
                  }"
                >
                  <strong
                    class="pack-name"
                    :style="{
                      fontSize: '1em',
                      color: '#202122',
                      wordBreak: 'break-word',
                    }"
                    >{{ pack.name }}</strong
                  >
                  <div
                    class="pack-meta-row"
                    :style="{
                      display: 'flex',
                      alignItems: 'center',
                      gap: '6px',
                      flexWrap: 'wrap',
                    }"
                  >
                    <span
                      v-if="pack.state.auto_selected_reason"
                      class="pack-badge auto"
                      :title="pack.state.auto_selected_reason"
                      :style="{
                        fontSize: '0.7em',
                        padding: '3px 8px',
                        borderRadius: '5px',
                        fontWeight: '600',
                        background: 'linear-gradient(135deg, #fef6e7 0%, #fff8e1 100%)',
                        color: '#ac6600',
                        border: '1px solid #f0d86f',
                        whiteSpace: 'nowrap',
                      }"
                    >
                      🤖 Auto
                    </span>
                    <span
                      v-else
                      class="pack-badge manual"
                      :style="{
                        fontSize: '0.7em',
                        padding: '3px 8px',
                        borderRadius: '5px',
                        fontWeight: '600',
                        background: 'linear-gradient(135deg, #eaf3ff 0%, #f0f6ff 100%)',
                        color: '#36c',
                        border: '1px solid #a8c9f0',
                        whiteSpace: 'nowrap',
                      }"
                    >
                      ✋ Manual
                    </span>
                    <span
                      class="action-badge"
                      :style="{
                        fontSize: '0.7em',
                        padding: '3px 10px',
                        borderRadius: '5px',
                        fontWeight: '700',
                        textTransform: 'uppercase',
                        letterSpacing: '0.3px',
                        whiteSpace: 'nowrap',
                        background:
                          pack.state.action === 'install'
                            ? 'linear-gradient(135deg, #d5fdf0 0%, #e8fff8 100%)'
                            : pack.state.action === 'update'
                              ? 'linear-gradient(135deg, #fff4e5 0%, #fff8ed 100%)'
                              : pack.state.action === 'remove'
                                ? 'linear-gradient(135deg, #fee7e6 0%, #fff0ef 100%)'
                                : '#f8f9fa',
                        color:
                          pack.state.action === 'install'
                            ? '#14866d'
                            : pack.state.action === 'update'
                              ? '#ac6600'
                              : pack.state.action === 'remove'
                                ? '#d33'
                                : '#54595d',
                        border:
                          pack.state.action === 'install'
                            ? '1px solid #7fd4bf'
                            : pack.state.action === 'update'
                              ? '1px solid #f0c77e'
                              : pack.state.action === 'remove'
                                ? '1px solid #faa'
                                : '1px solid #c8ccd1',
                      }"
                    >
                      {{ getActionIcon(pack.state.action) }} {{ pack.state.action }}
                    </span>
                  </div>
                </div>
              </div>

              <!-- Operation Status Badge -->
              <div
                v-if="packStatuses[pack.name]"
                class="operation-status"
                :style="{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '6px',
                  padding: '5px 12px',
                  borderRadius: '16px',
                  fontSize: '0.8em',
                  fontWeight: '700',
                  whiteSpace: 'nowrap',
                  flexShrink: '0',
                  background:
                    packStatuses[pack.name] === 'pending'
                      ? 'linear-gradient(135deg, #f8f9fa 0%, #eaecf0 100%)'
                      : packStatuses[pack.name] === 'running'
                        ? 'linear-gradient(135deg, #3366cc 0%, #447ff5 100%)'
                        : packStatuses[pack.name] === 'complete'
                          ? 'linear-gradient(135deg, #14866d 0%, #00af89 100%)'
                          : 'linear-gradient(135deg, #d33 0%, #ff4444 100%)',
                  color: packStatuses[pack.name] === 'pending' ? '#54595d' : 'white',
                  boxShadow: '0 2px 8px rgba(0, 0, 0, 0.15)',
                  animation:
                    packStatuses[pack.name] === 'running'
                      ? 'pulse 2s ease-in-out infinite'
                      : 'none',
                }"
              >
                <span v-if="packStatuses[pack.name] === 'pending'" :style="{ fontSize: '1.2em' }"
                  >⏳</span
                >
                <span
                  v-else-if="packStatuses[pack.name] === 'running'"
                  class="status-icon spinning"
                  :style="{ fontSize: '1.2em' }"
                  >⚙️</span
                >
                <span
                  v-else-if="packStatuses[pack.name] === 'complete'"
                  :style="{ fontSize: '1.2em' }"
                  >✅</span
                >
                <span
                  v-else-if="packStatuses[pack.name] === 'failed'"
                  :style="{ fontSize: '1.2em' }"
                  >❌</span
                >
                <span class="status-text" :style="{ fontWeight: '700' }">{{
                  getStatusText(packStatuses[pack.name])
                }}</span>
              </div>
            </div>

            <div
              class="pack-info-grid"
              :style="{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(110px, 1fr))',
                gap: '8px',
                paddingTop: '10px',
                marginTop: '2px',
                borderTop: '1px solid #eaecf0',
              }"
            >
              <div
                v-if="pack.state.target_version"
                class="info-item"
                :style="{
                  display: 'flex',
                  flexDirection: 'column',
                  gap: '4px',
                  padding: '6px 10px',
                  background: '#f8f9fa',
                  borderRadius: '6px',
                  border: '1px solid #eaecf0',
                }"
              >
                <span
                  class="info-label"
                  :style="{
                    fontSize: '0.65em',
                    color: '#72777d',
                    fontWeight: '700',
                    textTransform: 'uppercase',
                    letterSpacing: '0.4px',
                  }"
                  >Version</span
                >
                <span
                  class="info-value"
                  :style="{
                    fontSize: '0.88em',
                    color: '#202122',
                    fontWeight: '600',
                  }"
                  >{{ pack.state.target_version }}</span
                >
              </div>
              <div
                v-if="pack.state.prefix"
                class="info-item"
                :style="{
                  display: 'flex',
                  flexDirection: 'column',
                  gap: '4px',
                  padding: '6px 10px',
                  background: '#f8f9fa',
                  borderRadius: '6px',
                  border: '1px solid #eaecf0',
                }"
              >
                <span
                  class="info-label"
                  :style="{
                    fontSize: '0.65em',
                    color: '#72777d',
                    fontWeight: '700',
                    textTransform: 'uppercase',
                    letterSpacing: '0.4px',
                  }"
                  >Prefix</span
                >
                <span
                  class="info-value code"
                  :style="{
                    fontSize: '0.85em',
                    color: '#202122',
                    fontWeight: '500',
                    fontFamily: 'Monaco, Menlo, Consolas, monospace',
                    background: 'white',
                    padding: '3px 6px',
                    borderRadius: '3px',
                    border: '1px solid #c8ccd1',
                  }"
                  >{{ pack.state.prefix }}</span
                >
              </div>
              <div
                v-if="pack.pageCount > 0"
                class="info-item"
                :style="{
                  display: 'flex',
                  flexDirection: 'column',
                  gap: '6px',
                  padding: '6px 10px',
                  background: '#f8f9fa',
                  borderRadius: '6px',
                  border: '1px solid #eaecf0',
                  gridColumn: pack.state.prefix ? 'span 1' : 'span 2',
                }"
              >
                <span
                  class="info-label"
                  :style="{
                    fontSize: '0.65em',
                    color: '#72777d',
                    fontWeight: '700',
                    textTransform: 'uppercase',
                    letterSpacing: '0.4px',
                  }"
                  >Pages ({{ pack.pageCount }})</span
                >
                <div
                  class="pages-list"
                  :style="{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: '3px',
                  }"
                >
                  <div
                    v-for="(pageState, pageName) in pack.state.pages"
                    :key="pageName"
                    :style="{
                      fontSize: '0.75em',
                      color: '#202122',
                      padding: '2px 4px',
                      background: 'white',
                      borderRadius: '3px',
                      border: '1px solid #eaecf0',
                      fontFamily: 'Monaco, Menlo, Consolas, monospace',
                      overflow: 'hidden',
                      textOverflow: 'ellipsis',
                      whiteSpace: 'nowrap',
                    }"
                    :title="`${pageName} → ${pageState.final_title}`"
                  >
                    <span :style="{ color: '#72777d' }">📄</span>
                    {{ pageState.final_title || pageName }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="empty-state">
        <div class="empty-icon">📋</div>
        <p class="empty-text">{{ $t('labkipackmanager-no-packs-selected') }}</p>
        <p class="empty-hint">Select packs from the hierarchy tree to see details here</p>
      </div>

      <!-- Action Buttons -->
      <div class="actions-section">
        <div class="action-buttons">
          <cdx-button
            action="progressive"
            weight="primary"
            :disabled="store.busy || selectedPacks.length === 0"
            @click="onApply"
          >
            ✓ {{ $t('labkipackmanager-apply') }}
          </cdx-button>

          <cdx-button action="destructive" weight="quiet" :disabled="store.busy" @click="onClear">
            ✕ {{ $t('labkipackmanager-clear') }}
          </cdx-button>
        </div>

        <!-- Operation Status -->
        <cdx-message v-if="operationMessage" type="success" :inline="true">
          {{ operationMessage }}
        </cdx-message>

        <cdx-message v-if="errorMessage" type="error" :inline="true">
          {{ errorMessage }}
        </cdx-message>

        <!-- State Hash (Debug) -->
        <div v-if="store.stateHash" class="state-info">
          <small
            >{{ $t('labkipackmanager-state-hash') }}: <code>{{ store.stateHash }}</code></small
          >
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, reactive } from 'vue';
import { CdxButton, CdxMessage } from '@wikimedia/codex';
import { store } from '../state/store';
import { packsAction, pollOperation } from '../api/endpoints';
import { mergeDiff } from '../state/merge';
import StateSyncModal from './StateSyncModal.vue';
import {
  type FieldDifference,
  type PacksActionCommand,
  PacksActionResponse,
  StateDifference,
  OperationStatus,
  type PacksState,
} from '../state/types';

const operationMessage = ref('');
const errorMessage = ref('');
const packStatuses = ref({} as Record<string, OperationStatus>); // Track status of each pack during apply

const stateSyncModal = reactive({
  visible: false,
  message: '',
  differences: {},
  serverPacks: {} as PacksState,
  serverHash: '',
  reconcileCommands: [] as PacksActionCommand[],
  clientSnapshot: {},
  attemptingReconcile: false,
  reconcileMessage: '',
});

const selectedPacks = computed(() => {
  const packs = [];

  for (const [name, state] of Object.entries(store.packs)) {
    // Show packs that have any action set (not 'unchanged')
    // This includes install, update, and remove actions
    if (state.action && state.action !== 'unchanged') {
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

    // Initialize all selected packs as 'pending'
    packStatuses.value = {};
    for (const pack of selectedPacks.value) {
      packStatuses.value[pack.name] = 'pending';
    }

    // Step 1: Send apply command (queues background job)
    operationMessage.value = 'Queueing pack operations...';

    const response = await packsAction({
      command: 'apply',
      repo_url: store.repoUrl,
      ref: store.ref,
      data: {
        // Send current pack state with all the actions user has marked
        packs: store.packs,
        // Send state hash so backend can verify states are in sync
        state_hash: store.stateHash,
      },
    });

    console.log('[onApply] Apply response:', response);

    if (!response.ok) {
      if (response.error === 'state_out_of_sync') {
        handleStateOutOfSync(response);
        operationMessage.value = 'State out of sync detected. Review differences below.';
        markAllStatuses('failed');
        return;
      }
      throw new Error(response.message || 'Apply command failed');
    }

    // Merge diff (session state is cleared by backend)
    if (response.diff) {
      mergeDiff(store.packs, response.diff);
    } else {
      // When backend returns no diff (should not happen), ensure packs are cleared
      for (const key of Object.keys(store.packs)) delete store.packs[key];
    }
    if (response.state_hash) {
      store.stateHash = response.state_hash;
    }
    store.warnings = response.warnings ?? [];

    // Step 2: If we got an operation_id, poll for completion
    if (response.operation?.operation_id) {
      const operationId = response.operation.operation_id;
      console.log(`[onApply] Polling operation ${operationId}...`);

      // Poll with status updates
      await pollOperation(
        operationId,
        120, // 2 minutes max (pack operations can be slower)
        1000,
        (status) => {
          // Update message based on operation status
          if (status.message) {
            operationMessage.value = status.message;

            // Parse message to update pack statuses
            updatePackStatusFromMessage(status.message);
          } else if (status.status === 'queued') {
            operationMessage.value = 'Waiting for job to start...';
          } else if (status.status === 'running') {
            operationMessage.value = `Applying packs... (${status.progress || 0}%)`;
          }
        },
      );

      console.log('[onApply] Operation completed successfully');

      // Mark all remaining as complete
      for (const packName in packStatuses.value) {
        if (packStatuses.value[packName] !== 'failed') {
          packStatuses.value[packName] = 'complete';
        }
      }

      operationMessage.value = 'All packs applied successfully!';

      // Clear statuses after a delay
      setTimeout(() => {
        packStatuses.value = {};
      }, 3000);
    } else {
      // No operation queued (maybe no changes?)
      operationMessage.value = $t('labkipackmanager-apply-success');
      packStatuses.value = {};
    }
  } catch (e) {
    console.error('[onApply] Error:', e);
    errorMessage.value = e instanceof Error ? e.message : String(e);
    operationMessage.value = '';

    // Mark all as failed
    markAllStatuses('failed');
  } finally {
    store.busy = false;
  }
}

function markAllStatuses(status: string) {
  for (const packName in packStatuses.value) {
    packStatuses.value[packName] = status;
  }
}

function handleStateOutOfSync(response: PacksActionResponse) {
  const rawCommands = Array.isArray(response.reconcile_commands) ? response.reconcile_commands : [];
  const cleanedDifferences = sanitizeDifferences(response.differences, rawCommands);

  stateSyncModal.visible = true;
  stateSyncModal.message = response.message || 'Frontend and backend pack states are out of sync.';
  stateSyncModal.differences = cleanedDifferences;
  stateSyncModal.serverPacks = response.server_packs
    ? deepClone(response.server_packs)
    : ({} as PacksState);
  stateSyncModal.serverHash = response.state_hash || '';
  stateSyncModal.reconcileCommands = filterReconcileCommands(rawCommands, cleanedDifferences);
  stateSyncModal.clientSnapshot = deepClone(store.packs);
  stateSyncModal.attemptingReconcile = false;
  stateSyncModal.reconcileMessage = '';
}

function replaceStorePacks(newPacks: PacksState) {
  const target = store.packs;
  for (const key of Object.keys(target)) {
    delete target[key];
  }
  for (const [packName, packState] of Object.entries(newPacks)) {
    target[packName] = packState;
  }
}

function syncFrontendWithBackend() {
  replaceStorePacks(stateSyncModal.serverPacks || {});
  if (stateSyncModal.serverHash) {
    store.stateHash = stateSyncModal.serverHash;
  }
  stateSyncModal.visible = false;
  stateSyncModal.reconcileMessage = '';
  operationMessage.value = 'Synced with backend state. Review selections before applying again.';
}

function closeStateSyncModal() {
  stateSyncModal.visible = false;
  stateSyncModal.reconcileMessage = '';
}

async function reconcileAndReapply() {
  if (stateSyncModal.attemptingReconcile) {
    return;
  }

  try {
    stateSyncModal.attemptingReconcile = true;
    stateSyncModal.reconcileMessage = '';

    if (stateSyncModal.reconcileCommands.length === 0) {
      stateSyncModal.reconcileMessage =
        'No automatic reconciliation steps are available. Please sync with the backend instead.';
      stateSyncModal.attemptingReconcile = false;
      return;
    }

    for (const command of stateSyncModal.reconcileCommands) {
      const response = await packsAction({
        command: command.command,
        repo_url: store.repoUrl,
        ref: store.ref,
        data: command.data,
      });

      if (!response.ok) {
        throw new Error(response.message || `Failed to execute ${command.command}`);
      }

      if (response.diff) {
        mergeDiff(store.packs, response.diff);
      }
      if (response.state_hash) {
        store.stateHash = response.state_hash;
      }
      if (response.warnings) {
        store.warnings = response.warnings;
      }
    }

    stateSyncModal.reconcileMessage =
      'Differences reapplied successfully. Attempting apply again...';
    stateSyncModal.attemptingReconcile = false;
    stateSyncModal.visible = false;

    await onApply();
  } catch (err) {
    console.error('[reconcileAndReapply] Error:', err);
    stateSyncModal.reconcileMessage = err instanceof Error ? err.message : String(err);
    stateSyncModal.attemptingReconcile = false;
  }
}

function deepClone<T>(value: T): T {
  if (value === undefined) {
    return value;
  }
  return JSON.parse(JSON.stringify(value)) as T;
}

function sanitizeDifferences(differences: StateDifference, commands: PacksActionCommand[] = []) {
  const result: StateDifference = {};
  if (!differences || typeof differences !== 'object') {
    return result;
  }

  const allowedPacks = new Set(
    commands
      .map((command) => command?.data?.pack_name)
      .filter((packName) => typeof packName === 'string' && packName !== ''),
  );
  const restrictToAllowed = allowedPacks.size > 0;

  for (const [packName, packDiff] of Object.entries(differences)) {
    if (restrictToAllowed && !allowedPacks.has(packName)) {
      continue;
    }

    const fields = packDiff?.fields ?? {};
    const pages = packDiff?.pages ?? {};

    const fieldEntries = Object.entries(fields ?? {});

    const filteredPages: Record<string, Record<string, FieldDifference>> = {};
    for (const [pageName, pageDiff] of Object.entries(pages ?? {})) {
      const pageFields = Object.entries(pageDiff ?? {});
      if (pageFields.length > 0) {
        filteredPages[pageName] = pageDiff;
      }
    }

    if (fieldEntries.length > 0 || Object.keys(filteredPages).length > 0) {
      result[packName] = {
        ...(fieldEntries.length > 0 ? { fields } : {}),
        ...(Object.keys(filteredPages).length > 0 ? { pages: filteredPages } : {}),
      };
    }
  }

  return result;
}

function filterReconcileCommands(commands: PacksActionCommand[], differences: StateDifference) {
  const packs = new Set(Object.keys(differences));
  return commands.filter((command) => {
    const packName = command?.data?.pack_name;
    if (!packName) {
      return true;
    }
    return packs.has(packName);
  });
}

function updatePackStatusFromMessage(message: string) {
  // Parse backend messages to update pack statuses
  // Messages like: "Installing pack: People", "Removing pack: Miniscopes", etc.

  const installMatch = message.match(/Installing pack[:\s]+(.+)/i);
  const updateMatch = message.match(/Updating pack[:\s]+(.+)/i);
  const removeMatch = message.match(/Removing pack[:\s]+(.+)/i);
  const completeMatch = message.match(/Completed pack[:\s]+(.+)/i);
  const match = installMatch
    ? installMatch
    : updateMatch
      ? updateMatch
      : removeMatch
        ? removeMatch
        : null;

  if (match !== null) {
    const packName = match[1].trim();
    if (packStatuses.value[packName] !== undefined) {
      packStatuses.value[packName] = 'running';
    }
  } else if (completeMatch) {
    const packName = completeMatch[1].trim();
    if (packStatuses.value[packName] !== undefined) {
      packStatuses.value[packName] = 'complete';
    }
  }
}

function getStatusText(status) {
  switch (status) {
    case 'pending':
      return 'Pending';
    case 'running':
      return 'In Progress';
    case 'complete':
      return 'Complete';
    case 'failed':
      return 'Failed';
    default:
      return '';
  }
}

function getActionIcon(action) {
  switch (action) {
    case 'install':
      return '⬇️';
    case 'update':
      return '🔄';
    case 'remove':
      return '🗑️';
    default:
      return '';
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

    // Reset state before clear - like init, clear returns full initial state
    store.packs = {};
    store.warnings = [];

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
function $t(key: string) {
  return mw.msg(key);
}
</script>

<style scoped>
/*
  ============================================================================
  DETAILS PANEL - CARD-BASED DESIGN WITH INLINE STYLES
  ============================================================================
  
  Following the same pattern as TreeNode.vue:
  
  ⚠️ INLINE STYLES ARE REQUIRED for layout and visual styling!
  
  WHY: MediaWiki/Codex CSS has extremely high specificity that overrides
       our stylesheet classes. Inline styles have highest specificity.
  
  - Pack cards use inline :style="" bindings for all visual properties
  - CSS classes here only provide base structure and animations
  - Any layout-critical styling MUST be in :style="" bindings
  
  ============================================================================
*/

.details-panel {
  padding: 20px;
  background: white;
  border: 1px solid #c8ccd1;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 2px solid #eaecf0;
}

.panel-header h3 {
  margin: 0;
  font-size: 1.3em;
  font-weight: 600;
  color: #202122;
}

.pack-count-badge {
  padding: 6px 14px;
  background: linear-gradient(135deg, #3366cc 0%, #447ff5 100%);
  color: white;
  border-radius: 20px;
  font-size: 0.85em;
  font-weight: 600;
  box-shadow: 0 2px 4px rgba(51, 102, 204, 0.2);
}

.panel-content {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* ==================== SECTION HEADERS ==================== */

.section-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.section-icon {
  font-size: 1.4em;
  line-height: 1;
}

.section-header h4 {
  margin: 0;
  font-size: 1.1em;
  font-weight: 600;
  color: #202122;
}

/* ==================== WARNINGS SECTION ==================== */

.warnings-section {
  animation: slideIn 0.3s ease;
}

.warnings-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* ==================== PACK CARDS ==================== */
/* Most styling is inline (see template), CSS here is for fallback/animations only */

.packs-grid {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.pack-card:hover {
  box-shadow:
    0 6px 20px rgba(0, 0, 0, 0.15),
    0 2px 6px rgba(0, 0, 0, 0.1) !important;
  transform: translateY(-3px);
}

.status-icon {
  font-size: 1.1em;
  line-height: 1;
}

.status-icon.spinning {
  display: inline-block;
  animation: spin 2s linear infinite;
}

.status-text {
  font-weight: 600;
}

/* ==================== EMPTY STATE ==================== */

.empty-state {
  text-align: center;
  padding: 40px 20px;
  background: linear-gradient(135deg, #fafbfc 0%, #f8f9fa 100%);
  border-radius: 8px;
  border: 2px dashed #c8ccd1;
}

.empty-icon {
  font-size: 3em;
  margin-bottom: 10px;
  opacity: 0.4;
}

.empty-text {
  margin: 0 0 8px 0;
  font-size: 1.1em;
  font-weight: 600;
  color: #54595d;
}

.empty-hint {
  margin: 0;
  font-size: 0.9em;
  color: #72777d;
}

/* ==================== ACTIONS SECTION ==================== */

.actions-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-top: 16px;
  border-top: 2px solid #eaecf0;
}

.action-buttons {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.action-buttons :deep(.cdx-button) {
  border-radius: 8px;
  font-weight: 600;
  padding: 10px 20px;
  transition: all 0.2s ease;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
}

.action-buttons :deep(.cdx-button:hover:not(:disabled)) {
  transform: translateY(-1px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.action-buttons :deep(.cdx-button:active:not(:disabled)) {
  transform: translateY(0);
}

.state-info {
  padding: 10px 14px;
  background: linear-gradient(135deg, #f8f9fa 0%, #eaecf0 100%);
  border-radius: 6px;
  border: 1px solid #c8ccd1;
}

.state-info small {
  color: #54595d;
  font-size: 0.85em;
}

.state-info code {
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
  font-size: 0.9em;
  color: #202122;
  background: white;
  padding: 2px 6px;
  border-radius: 3px;
  border: 1px solid #c8ccd1;
}

/* ==================== ANIMATIONS ==================== */

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

@keyframes pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.8;
  }
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ==================== RESPONSIVE ==================== */

@media (max-width: 768px) {
  .pack-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .operation-status {
    align-self: flex-start;
  }

  .pack-info-grid {
    grid-template-columns: 1fr;
  }

  .action-buttons {
    flex-direction: column;
  }

  .action-buttons :deep(.cdx-button) {
    width: 100%;
  }

  .panel-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }

  .pack-count-badge {
    align-self: flex-start;
  }
}
</style>
