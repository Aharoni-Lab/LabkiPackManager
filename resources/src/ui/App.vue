<template>
  <div class="labki-pack-manager-app">
    <header class="app-header">
      <h1>{{ $t('labkipackmanager-special-title') }}</h1>
      <p class="app-description">{{ $t('labkipackmanager-special-description') }}</p>
    </header>
    
    <div v-if="store.busy" class="loading-overlay">
      <div class="loading-spinner">
        {{ $t('labkipackmanager-loading') }}...
      </div>
    </div>
    
    <main class="app-main">
      <repo-ref-selector />
      
      <mermaid-graph
        v-if="store.repoUrl && store.ref"
        :mermaid-src="store.mermaidSrc"
      />
      
      <hierarchy-tree
        v-if="store.repoUrl && store.ref"
        :hierarchy="store.hierarchy"
      />
      
      <details-panel
        v-if="store.repoUrl && store.ref"
      />
    </main>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue';
import { store } from '../state/store';
import RepoRefSelector from './RepoRefSelector.vue';
import MermaidGraph from './MermaidGraph.vue';
import HierarchyTree from './HierarchyTree.vue';
import DetailsPanel from './DetailsPanel.vue';

onMounted(() => {
  console.log('Labki Pack Manager initialized');
});

// Helper for i18n
function $t(key) {
  return mw.msg(key);
}
</script>

<style scoped>
.labki-pack-manager-app {
  max-width: 1400px;
  margin: 0 auto;
  padding: 20px;
  position: relative;
}

.app-header {
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 2px solid #eaecf0;
}

.app-header h1 {
  margin: 0 0 8px 0;
  font-size: 2em;
  font-weight: 600;
}

.app-description {
  margin: 0;
  color: #54595d;
  font-size: 1.05em;
}

.loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(255, 255, 255, 0.8);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.loading-spinner {
  padding: 24px 48px;
  background: white;
  border: 2px solid #36c;
  border-radius: 8px;
  font-size: 1.2em;
  color: #36c;
  font-weight: 600;
}

.app-main {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
</style>

