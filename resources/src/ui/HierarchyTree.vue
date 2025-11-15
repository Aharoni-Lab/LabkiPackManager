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

      <div class="tree-nodes labki-tree">
        <tree-node
          v-for="node in hierarchy.root_nodes"
          :key="node.id"
          :node="node"
          :depth="0"
          @set-pack-action="onSetPackAction"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { store } from '../state/store';
import { packsAction } from '../api/endpoints';
import { mergeDiff } from '../state/merge';
import TreeNode from './TreeNode.vue';
import {
  type PacksActionCommandName,
  type SetPackActionData,
  type PacksActionDataMap,
} from '../state/types';

defineProps({
  hierarchy: {
    type: Object,
    required: true,
  },
});

async function onSetPackAction(payload: SetPackActionData ){
  // Send set_pack_action command to backend
  await sendCommand('set_pack_action', {
    pack_name: payload.pack_name,
    action: payload.action,
  });
}

async function sendCommand<T extends PacksActionCommandName>(command: T, data: PacksActionDataMap[T]) {
  if (store.busy) return;

  try {
    store.busy = true;

    console.log(`[sendCommand] Sending ${command}:`, data);
    const response = await packsAction({
      command,
      repo_url: store.repoUrl,
      ref: store.ref,
      data,
    });

    console.log(`[sendCommand] Response diff:`, response.diff);
    console.log(
      `[sendCommand] Pack state before merge:`,
      data.pack_name ? store.packs[data.pack_name] : 'N/A',
    );

    // Merge diff into store
    mergeDiff(store.packs, response.diff);
    store.stateHash = response.state_hash;
    store.warnings = response.warnings;

    console.log(
      `[sendCommand] Pack state after merge:`,
      data.pack_name ? store.packs[data.pack_name] : 'N/A',
    );
  } catch (e) {
    console.error('Command failed:', e);
    // You might want to emit an error event here
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
.hierarchy-tree-container {
  padding: 20px;
  background: white;
  border: 1px solid #c8ccd1;
  border-radius: 8px;
  margin-bottom: 20px;
  width: 100%;
  max-width: 100%;
  display: flex;
  flex-direction: column;
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
  width: 100%;
  display: flex;
  flex-direction: column;
}
</style>
