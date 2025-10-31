<template>
  <div class="hierarchy-tree-container">
    <h3>{{ $t('labkipackmanager-hierarchy-title') }}</h3>
    
    <div v-if="!hierarchy" class="no-hierarchy">
      <p>{{ $t('labkipackmanager-no-hierarchy-loaded') }}</p>
    </div>
    
    <div v-else class="tree-content">
      <div class="tree-stats">
        <span>{{ $t('labkipackmanager-pack-count') }}: {{ hierarchy.meta.pack_count }}</span>
        <span>{{ $t('labkipackmanager-page-count') }}: {{ hierarchy.meta.page_count }}</span>
      </div>
      
      <div class="tree-nodes">
        <tree-node
          v-for="node in hierarchy.root_nodes"
          :key="node.id"
          :node="node"
          :depth="0"
          @select-pack="onSelectPack"
          @deselect-pack="onDeselectPack"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { store } from '../state/store';
import { packsAction } from '../api/endpoints';
import { mergeDiff } from '../state/merge';
import TreeNode from './TreeNode.vue';

defineProps({
  hierarchy: Object
});

async function onSelectPack(packName) {
  await sendCommand('select_pack', { pack_name: packName });
}

async function onDeselectPack(packName) {
  await sendCommand('deselect_pack', { pack_name: packName });
}

async function sendCommand(command, data) {
  if (store.busy) return;
  
  try {
    store.busy = true;
    
    const response = await packsAction({
      command,
      repo_url: store.repoUrl,
      ref: store.ref,
      data,
    });
    
    // Merge diff into store
    mergeDiff(store.packs, response.diff);
    store.stateHash = response.state_hash;
    store.warnings = response.warnings;
  } catch (e) {
    console.error('Command failed:', e);
    // You might want to emit an error event here
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
.hierarchy-tree-container {
  padding: 20px;
  background: white;
  border: 1px solid #c8ccd1;
  border-radius: 8px;
  margin-bottom: 20px;
}

h3 {
  margin-top: 0;
  margin-bottom: 16px;
  font-size: 1.2em;
}

.no-hierarchy {
  padding: 16px;
  text-align: center;
  color: #72777d;
}

.no-hierarchy p {
  margin: 0;
}

.tree-stats {
  display: flex;
  gap: 24px;
  padding: 12px;
  background: #f8f9fa;
  border-radius: 4px;
  margin-bottom: 16px;
  font-size: 0.9em;
}

.tree-nodes {
  margin-top: 16px;
}
</style>

