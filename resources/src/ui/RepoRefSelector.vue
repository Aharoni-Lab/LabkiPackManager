<template>
  <div class="repo-ref-selector">
    <h2>{{ $t('labkipackmanager-select-source') }}</h2>
    
    <div class="selector-row">
      <cdx-field class="selector-field">
        <template #label>{{ $t('labkipackmanager-repo-selector-label') }}</template>
        <cdx-select
          v-model:selected="selectedRepoUrl"
          :menu-items="repoMenuItems"
          :disabled="store.busy"
          @update:selected="onRepoSelected"
        />
      </cdx-field>
      
      <cdx-field class="selector-field">
        <template #label>{{ $t('labkipackmanager-ref-selector-label') }}</template>
        <cdx-select
          v-model:selected="selectedRefName"
          :menu-items="refMenuItems"
          :disabled="store.busy || !selectedRepoUrl"
          @update:selected="onRefSelected"
        />
      </cdx-field>
    </div>
    
    <div v-if="selectedRepoUrl && selectedRefName" class="current-selection">
      <strong>{{ $t('labkipackmanager-current-selection') }}:</strong>
      {{ selectedRepoUrl }} @ {{ selectedRefName }}
    </div>
    
    <cdx-message v-if="error" type="error" :inline="true">
      {{ error }}
    </cdx-message>
    
    <add-repo-modal
      v-model="showAddRepoModal"
      @added="onRepoAdded"
    />
    
    <add-ref-modal
      v-model="showAddRefModal"
      :repo-url="selectedRepoUrl"
      @added="onRefAdded"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { CdxField, CdxSelect, CdxMessage } from '@wikimedia/codex';
import { store } from '../state/store';
import { reposList, graphGet, hierarchyGet, packsAction } from '../api/endpoints';
import AddRepoModal from './AddRepoModal.vue';
import AddRefModal from './AddRefModal.vue';

const ADD_REPO_VALUE = '__add_new_repo__';
const ADD_REF_VALUE = '__add_new_ref__';

const selectedRepoUrl = ref('');
const selectedRefName = ref('');
const showAddRepoModal = ref(false);
const showAddRefModal = ref(false);
const error = ref('');

const repoMenuItems = computed(()  => {
  console.log('Computing repoMenuItems, store.repos:', store.repos);
  
  if (!store.repos || store.repos.length === 0) {
    return [{
      label: 'Loading...',
      value: '',
      disabled: true
    }];
  }
  
  const items = store.repos.map(repo => ({
    label: repo.repo_url,
    value: repo.repo_url,
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
  });
  
  return items;
});

const refMenuItems = computed(()  => {
  if (!store.selectedRepo) {
    return [];
  }
  
  const items = store.selectedRepo.refs.map(ref => ({
    label: `${ref.ref_name} ${ref.is_default ? '(default)' : ''}`,
    value: ref.ref,
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
      throw new Error('Invalid response structure - no repos field. Response: ' + JSON.stringify(response));
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

async function onRepoSelected(value) {
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

async function selectRepo(url) {
  try {
    store.busy = true;
    error.value = '';
    
    selectedRepoUrl.value = url;
    store.repoUrl = url;
    
    // Find repo object
    const repo = store.repos.find(r => r.repo_url === url);
    if (!repo) {
      throw new Error($t('labkipackmanager-error-repo-not-found'));
    }
    
    store.selectedRepo = repo;
    
    // Auto-select default ref
    const defaultRefObj = repo.refs.find(r => r.is_default);
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

async function onRefSelected(value) {
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

async function selectRef(refName) {
  try {
    store.busy = true;
    error.value = '';
    
    selectedRefName.value = refName;
    store.ref = refName;
    
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

async function onRepoAdded(repoUrl) {
  await loadRepos();
  await selectRepo(repoUrl);
}

async function onRefAdded(refName) {
  // Reload the current repo to get updated refs
  if (selectedRepoUrl.value) {
    await loadRepos();
    await selectRef(refName);
  }
}

function buildMermaidFromGraph(graph) {
  const lines = ['graph TD'];
  
  // Create node definitions first
  const nodes = new Set();
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
  for (const node of nodes) {
    lines.push(`  ${escapeNodeName(node)}["${node}"]`);
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

function escapeNodeName(name) {
  // Replace spaces and special chars with underscores
  return name.replace(/[^a-zA-Z0-9_]/g, '_');
}

// Helper for i18n
function $t(key) {
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
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
  align-items: flex-start;
  width: 100%;
}

.selector-field {
  flex: 1;
  min-width: 0;
  width: 50%;
  flex-shrink: 0;
}

.current-selection {
  padding: 12px;
  background: #eaf3ff;
  border-radius: 4px;
  font-size: 0.95em;
  margin-bottom: 16px;
}

@media (max-width: 768px) {
  .selector-row {
    flex-direction: column;
  }
}
</style>

