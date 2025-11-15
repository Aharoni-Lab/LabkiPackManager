<template>
  <div class="repo-ref-selector">
    <h2>{{ $t('labkipackmanager-select-source') }}</h2>

    <div class="selector-row" style="display: flex; gap: 24px; flex-wrap: nowrap">
      <div class="selector-field" style="flex: 1 1 0; min-width: 0">
        <cdx-field>
          <template #label>{{ $t('labkipackmanager-repo-selector-label') }}</template>
          <cdx-select
            v-model:selected="selectedRepoUrl"
            :menu-items="repoMenuItems"
            :disabled="store.busy"
            @update:selected="onRepoSelected"
          />
        </cdx-field>
      </div>

      <div class="selector-field" style="flex: 1 1 0; min-width: 0">
        <cdx-field>
          <template #label>{{ $t('labkipackmanager-ref-selector-label') }}</template>
          <cdx-select
            v-model:selected="selectedRefName"
            :menu-items="refMenuItems"
            :disabled="store.busy || !selectedRepoUrl"
            @update:selected="onRefSelected"
          />
        </cdx-field>
      </div>
    </div>

    <div v-if="selectedRepoUrl && selectedRefName" class="current-selection">
      <strong>{{ $t('labkipackmanager-current-selection') }}:</strong>
      {{ selectedRepoUrl }} @ {{ selectedRefName }}
    </div>

    <div v-if="selectedRepoUrl" class="sync-section">
      <cdx-button
        action="progressive"
        weight="quiet"
        :disabled="store.busy || syncInProgress"
        @click="onSync"
      >
        🔄 {{ $t('labkipackmanager-sync-from-remote') }}
      </cdx-button>
    </div>

    <cdx-message v-if="syncMessage" type="success" :inline="true">
      {{ syncMessage }}
    </cdx-message>

    <cdx-message v-if="error" type="error" :inline="true">
      {{ error }}
    </cdx-message>

    <add-repo-modal v-model="showAddRepoModal" @added="onRepoAdded" />

    <add-ref-modal v-model="showAddRefModal" :repo-url="selectedRepoUrl" @added="onRefAdded" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { CdxField, CdxSelect, CdxMessage, CdxButton } from '@wikimedia/codex';
import { store } from '../state/store';
import {
  reposList,
  reposSync,
  graphGet,
  hierarchyGet,
  packsAction,
  pollOperation,
} from '../api/endpoints';
import AddRepoModal from './AddRepoModal.vue';
import AddRefModal from './AddRefModal.vue';
import { type PackGraph } from '../state/types';

const ADD_REPO_VALUE = '__add_new_repo__';
const ADD_REF_VALUE = '__add_new_ref__';

const selectedRepoUrl = ref('');
const selectedRefName = ref('');
const showAddRepoModal = ref(false);
const showAddRefModal = ref(false);
const error = ref('');
const syncMessage = ref('');
const syncInProgress = ref(false);

const repoMenuItems = computed(() => {
  console.log('Computing repoMenuItems, store.repos:', store.repos);

  if (!store.repos || store.repos.length === 0) {
    return [
      {
        label: 'Loading...',
        value: '',
        disabled: true,
      },
    ];
  }

  const items = store.repos.map((repo) => ({
    label: repo.repo_url,
    value: repo.repo_url,
    disabled: false,
  }));

  // Add separator and "Add new" option
  items.push({
    label: '─────────────',
    value: '__separator__',
    disabled: true,
  });
  items.push({
    label: $t('labkipackmanager-add-repo-option'),
    value: ADD_REPO_VALUE,
    disabled: false,
  });

  return items;
});

const refMenuItems = computed(() => {
  if (!store.selectedRepo) {
    return [];
  }

  const items = store.selectedRepo.refs.map((ref) => ({
    label: `${ref.ref_name} ${ref.is_default ? '(default)' : ''}`,
    value: ref.ref,
    disabled: false,
  }));

  // Add separator and "Add new" option
  items.push({
    label: '─────────────',
    value: '__separator__',
    disabled: true,
  });
  items.push({
    label: $t('labkipackmanager-add-ref-option'),
    value: ADD_REF_VALUE,
    disabled: false,
  });

  return items;
});

onMounted(async () => {
  console.log('RepoRefSelector mounted, store:', store);
  await loadRepos();
});

async function loadRepos() {
  try {
    console.log('[loadRepos] Starting...');
    store.busy = true;
    error.value = '';
    console.log('[loadRepos] Calling reposList API...');
    const response = await reposList();
    console.log('[loadRepos] API response received:', response);

    if (!response) {
      throw new Error('No response from reposList API');
    }

    if (!response.repos) {
      throw new Error(
        'Invalid response structure - no repos field. Response: ' + JSON.stringify(response),
      );
    }

    console.log('[loadRepos] Setting store.repos:', response.repos);
    store.repos = response.repos;
    console.log('[loadRepos] store.repos is now:', store.repos);
    console.log('[loadRepos] store.repos.length:', store.repos.length);

    // Auto-select first repo if available
    if (store.repos.length > 0 && !selectedRepoUrl.value) {
      console.log('[loadRepos] Auto-selecting first repo:', store.repos[0].repo_url);
      await selectRepo(store.repos[0].repo_url);
    }
  } catch (e) {
    console.error('[loadRepos] Error:', e);
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    store.busy = false;
    console.log('[loadRepos] Finished. store.busy:', store.busy, 'store.repos:', store.repos);
  }
}

async function onRepoSelected(value: string) {
  if (value === ADD_REPO_VALUE) {
    showAddRepoModal.value = true;
    // Reset selection
    selectedRepoUrl.value = store.repoUrl;
    return;
  }

  if (typeof value === 'string' && value && value !== '__separator__') {
    await selectRepo(value);
  }
}

async function selectRepo(url: string) {
  try {
    store.busy = true;
    error.value = '';

    selectedRepoUrl.value = url;
    store.repoUrl = url;

    // Find repo object
    const repo = store.repos.find((r) => r.repo_url === url);
    if (!repo) {
      throw new Error($t('labkipackmanager-error-repo-not-found'));
    }

    store.selectedRepo = repo;

    // Auto-select default ref
    const defaultRefObj = repo.refs.find((r) => r.is_default);
    const defaultRef = defaultRefObj?.ref || repo.refs[0]?.ref;

    if (defaultRef) {
      await selectRef(defaultRef);
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    store.busy = false;
  }
}

async function onRefSelected(value: string) {
  if (value === ADD_REF_VALUE) {
    showAddRefModal.value = true;
    // Reset selection
    selectedRefName.value = store.ref;
    return;
  }

  if (typeof value === 'string' && value && value !== '__separator__') {
    await selectRef(value);
  }
}

async function selectRef(refName: string) {
  try {
    store.busy = true;
    error.value = '';

    selectedRefName.value = refName;
    store.ref = refName;

    // IMPORTANT: Reset packs state before init
    // The init command on backend returns full state, and frontend must start empty
    store.packs = {};
    store.warnings = [];

    // Fetch graph and hierarchy
    const [graphResponse, hierarchyResponse] = await Promise.all([
      graphGet(store.repoUrl, store.ref),
      hierarchyGet(store.repoUrl, store.ref),
    ]);

    // Build mermaid source from graph
    store.mermaidSrc = buildMermaidFromGraph(graphResponse.graph);
    store.hierarchy = hierarchyResponse.hierarchy;

    // Initialize pack state
    const initResponse = await packsAction({
      command: 'init',
      repo_url: store.repoUrl,
      ref: store.ref,
      data: {},
    });

    // On init, diff contains full state
    store.packs = initResponse.diff;
    store.stateHash = initResponse.state_hash;
    store.warnings = initResponse.warnings;
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    store.busy = false;
  }
}

async function onRepoAdded(eventData: { repoUrl: string }) {
  // The repo was added and the modal already waited for completion
  // Now we just need to reload and select it
  try {
    store.busy = true;
    error.value = '';

    const { repoUrl } = eventData;

    console.log(`[onRepoAdded] Repo '${repoUrl}' initialized, reloading repos and selecting...`);

    // Reload repos to get the updated repo list
    await loadRepos();

    // Select the newly added repo
    await selectRepo(repoUrl);

    console.log('[onRepoAdded] Repo selected successfully');
  } catch (e) {
    console.error('[onRepoAdded] Error:', e);
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    store.busy = false;
  }
}

async function onRefAdded(eventData: { refName: string }) {
  // The ref was added and the modal already waited for completion
  // Now we just need to reload and select it
  try {
    store.busy = true;
    error.value = '';

    const { refName } = eventData;

    console.log(`[onRefAdded] Ref '${refName}' initialized, reloading repos and selecting...`);

    // Reload repos to get the updated ref list
    await loadRepos();

    // Re-select the current repo to refresh store.selectedRepo with the new ref
    // This is needed because store.selectedRepo still points to the old repo object
    if (selectedRepoUrl.value) {
      await selectRepo(selectedRepoUrl.value);
    }

    // Select the newly added ref
    await selectRef(refName);

    console.log('[onRefAdded] Ref selected successfully');
  } catch (e) {
    console.error('[onRefAdded] Error:', e);
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    store.busy = false;
  }
}

async function onSync() {
  if (store.busy || syncInProgress.value) return;

  try {
    syncInProgress.value = true;
    syncMessage.value = '';
    error.value = '';

    console.log(`[onSync] Syncing repository ${selectedRepoUrl.value}`);

    // Step 1: Queue sync operation
    syncMessage.value = 'Syncing from remote repository...';
    const response = await reposSync(selectedRepoUrl.value);

    console.log('[onSync] Sync response:', response);

    // Step 2: If we got an operation_id, poll for completion
    if (response.operation_id) {
      const operationId = response.operation_id;
      console.log(`[onSync] Polling operation ${operationId}...`);

      // Poll with status updates
      await pollOperation(
        operationId,
        120, // 2 minutes max
        1000,
        (status) => {
          // Update message based on operation status
          if (status.message) {
            syncMessage.value = status.message;
          } else if (status.status === 'queued') {
            syncMessage.value = 'Waiting for sync to start...';
          } else if (status.status === 'running') {
            syncMessage.value = `Syncing... (${status.progress || 0}%)`;
          }
        },
      );

      console.log('[onSync] Sync completed successfully');
      syncMessage.value = 'Repository synced successfully! Reloading...';

      // Reload the current ref to get latest data
      if (selectedRefName.value) {
        await selectRef(selectedRefName.value);
      }

      syncMessage.value = 'Repository synced and refreshed!';

      // Clear message after delay
      setTimeout(() => {
        syncMessage.value = '';
      }, 3000);
    } else {
      syncMessage.value = 'Repository synced!';
      setTimeout(() => {
        syncMessage.value = '';
      }, 3000);
    }
  } catch (e) {
    console.error('[onSync] Error:', e);
    error.value = e instanceof Error ? e.message : String(e);
    syncMessage.value = '';
  } finally {
    syncInProgress.value = false;
  }
}

function buildMermaidFromGraph(graph: PackGraph) {
  const lines = ['graph TD'];

  // Create node definitions first
  const nodes = new Set<string>();
  const edges = [];

  // Collect all nodes from edges
  for (const edge of graph.containsEdges || []) {
    nodes.add(edge.from);
    nodes.add(edge.to);
    edges.push({ from: edge.from, to: edge.to, type: 'contains' });
  }

  for (const edge of graph.dependsEdges || []) {
    nodes.add(edge.from);
    nodes.add(edge.to);
    edges.push({ from: edge.from, to: edge.to, type: 'depends' });
  }

  // Add node definitions with labels
  // Backend now provides prefixed names like "pack:People" and "page:People"
  for (const node of nodes) {
    const nodeId = escapeNodeName(node);
    // Extract the display name and icon from the prefixed node name
    const [prefix, ...nameParts] = node.split(':');
    const displayName = nameParts.join(':'); // rejoin in case name has colons
    const icon = prefix === 'page' ? '📄' : '📦';
    lines.push(`  ${nodeId}["${icon} ${displayName}"]`);
  }

  // Add edges
  for (const edge of edges) {
    const fromNode = escapeNodeName(edge.from);
    const toNode = escapeNodeName(edge.to);
    if (edge.type === 'depends') {
      lines.push(`  ${fromNode} -.-> ${toNode}`);
    } else {
      lines.push(`  ${fromNode} --> ${toNode}`);
    }
  }

  const mermaidSrc = lines.join('\n');
  console.log('[buildMermaidFromGraph] Generated Mermaid syntax:\n', mermaidSrc);
  return mermaidSrc;
}

function escapeNodeName(name: string) {
  // Replace spaces and special chars with underscores
  return name.replace(/[^a-zA-Z0-9_]/g, '_');
}

// Helper for i18n
function $t(key: string) {
  return mw.msg(key);
}
</script>

<style scoped>
.repo-ref-selector {
  padding: 20px;
  background: white;
  border: 1px solid #c8ccd1;
  border-radius: 8px;
  margin-bottom: 20px;
}

h2 {
  margin-top: 0;
  margin-bottom: 16px;
  font-size: 1.4em;
}

.selector-row {
  display: flex !important;
  flex-wrap: nowrap !important;
  gap: 24px;
  align-items: flex-start;
  width: 100%;
}

.selector-field {
  flex: 1 1 0 !important;
  min-width: 0 !important;
  width: auto !important;
}

/* Make the cdx-field fill its wrapper but not force line breaks */
.selector-field :deep(.cdx-field) {
  width: 100% !important;
  margin: 0 !important;
  display: block !important;
}

.current-selection {
  padding: 12px;
  background: #eaf3ff;
  border-radius: 4px;
  font-size: 0.95em;
  margin-bottom: 12px;
}

.sync-section {
  margin-bottom: 16px;
}

.sync-section :deep(.cdx-button) {
  border-radius: 6px;
  font-weight: 600;
  transition: all 0.2s ease;
}

.sync-section :deep(.cdx-button:hover:not(:disabled)) {
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

@media (max-width: 768px) {
  .selector-row {
    flex-direction: column;
  }
}
</style>
