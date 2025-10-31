<template>
  <div class="tree-node" :class="{ 'is-pack': node.type === 'pack', 'is-page': node.type === 'page' }">
    <div class="node-header" :style="{ paddingLeft: `${depth * 24}px` }">
      <button
        v-if="node.children && node.children.length > 0"
        class="expand-button"
        @click="toggleExpanded"
      >
        {{ expanded ? '▼' : '▶' }}
      </button>
      <span v-else class="expand-spacer"></span>
      
      <div class="node-content">
        <div class="node-label">
          <span class="node-icon">{{ node.type === 'pack' ? '📦' : '📄' }}</span>
          <strong>{{ node.label }}</strong>
          <span v-if="node.version" class="node-version">v{{ node.version }}</span>
          <span v-if="packState" class="node-status" :class="statusClass">
            {{ statusText }}
          </span>
        </div>
        
        <div v-if="node.description" class="node-description">
          {{ node.description }}
        </div>
        
        <div v-if="node.depends_on && node.depends_on.length > 0" class="node-depends">
          <small>{{ $t('labkipackmanager-depends-on') }}: {{ node.depends_on.join(', ') }}</small>
        </div>
        
        <div v-if="node.type === 'pack'" class="node-actions">
          <cdx-button
            v-if="!packState?.selected && !packState?.auto_selected"
            size="small"
            action="progressive"
            @click="onSelect"
          >
            {{ $t('labkipackmanager-select') }}
          </cdx-button>
          <cdx-button
            v-if="packState?.selected || packState?.auto_selected"
            size="small"
            action="destructive"
            @click="onDeselect"
          >
            {{ $t('labkipackmanager-deselect') }}
          </cdx-button>
        </div>
      </div>
    </div>
    
    <div v-if="expanded && node.children" class="node-children">
      <tree-node
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :depth="depth + 1"
        @select-pack="$emit('select-pack', $event)"
        @deselect-pack="$emit('deselect-pack', $event)"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { CdxButton } from '@wikimedia/codex';
import { store } from '../state/store';

const props = defineProps({
  node: Object,
  depth: Number
});

const emit = defineEmits(['select-pack', 'deselect-pack']);

const expanded = ref(props.depth < 2); // Auto-expand first 2 levels

const packState = computed(() => {
  if (props.node.type !== 'pack') {
    return null;
  }
  return store.packs[props.node.label] || null;
});

const statusClass = computed(() => {
  if (!packState.value) return '';
  
  if (packState.value.auto_selected) return 'status-auto';
  if (packState.value.selected) return 'status-selected';
  return '';
});

const statusText = computed(() => {
  if (!packState.value) return '';
  
  if (packState.value.auto_selected) {
    return `(${$t('labkipackmanager-auto-selected')})`;
  }
  if (packState.value.selected) {
    return `(${$t('labkipackmanager-selected')})`;
  }
  return '';
});

function toggleExpanded() {
  expanded.value = !expanded.value;
}

function onSelect() {
  emit('select-pack', props.node.label);
}

function onDeselect() {
  emit('deselect-pack', props.node.label);
}

// Helper for i18n
function $t(key) {
  return mw.msg(key);
}
</script>

<style scoped>
.tree-node {
  margin: 4px 0;
}

.node-header {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.expand-button {
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px 8px;
  font-size: 12px;
  color: #72777d;
  min-width: 24px;
}

.expand-button:hover {
  color: #202122;
}

.expand-spacer {
  display: inline-block;
  min-width: 24px;
}

.node-content {
  flex: 1;
  min-width: 0;
}

.node-label {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}

.node-icon {
  font-size: 16px;
}

.node-version {
  font-size: 0.85em;
  color: #72777d;
  font-weight: normal;
}

.node-status {
  font-size: 0.85em;
  font-weight: normal;
  padding: 2px 8px;
  border-radius: 4px;
}

.status-selected {
  background: #eaf3ff;
  color: #36c;
}

.status-auto {
  background: #fef6e7;
  color: #ac6600;
}

.node-description {
  font-size: 0.9em;
  color: #54595d;
  margin-bottom: 4px;
}

.node-depends {
  font-size: 0.85em;
  color: #72777d;
  margin-bottom: 8px;
}

.node-actions {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}

.node-children {
  margin-left: 12px;
  border-left: 1px solid #eaecf0;
}

.is-pack {
  padding: 8px 0;
}

.is-pack:hover {
  background: #f8f9fa;
}

.is-page .node-label {
  color: #54595d;
}
</style>

