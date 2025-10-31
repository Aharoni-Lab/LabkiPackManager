<template>
  <div class="mermaid-graph-container">
    <h3>{{ $t('labkipackmanager-graph-title') }}</h3>
    
    <div v-if="!hasMermaid" class="no-mermaid-message">
      <cdx-message type="warning" :inline="true">
        {{ $t('labkipackmanager-mermaid-not-installed') }}
      </cdx-message>
    </div>
    
    <div v-else-if="mermaidSrc" class="mermaid-wrapper">
      <pre class="mermaid">{{ mermaidSrc }}</pre>
    </div>
    
    <div v-else class="no-graph-message">
      <p>{{ $t('labkipackmanager-no-graph-loaded') }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch, nextTick } from 'vue';
import { CdxMessage } from '@wikimedia/codex';

const props = defineProps({
  mermaidSrc: String
});

const hasMermaid = ref(false);

onMounted(() => {
  checkMermaidAvailability();
});

watch(() => props.mermaidSrc, async () => {
  if (hasMermaid.value && props.mermaidSrc) {
    // Wait for Vue to update the DOM
    await nextTick();
    // Then trigger Mermaid to render the new content
    triggerMermaidRender();
  }
});

function checkMermaidAvailability() {
  // Check if Mermaid extension module is available
  const state = mw.loader.getState('ext.mermaid');
  hasMermaid.value = state === 'ready' || state === 'loaded' || state === 'loading';
  
  if (!hasMermaid.value) {
    console.warn('Mermaid extension is not available');
  } else {
    console.log('[MermaidGraph] Mermaid extension is available, state:', state);
  }
}

function triggerMermaidRender() {
  console.log('[MermaidGraph] Triggering Mermaid render');
  
  // Try to access the mermaid global if available
  if (typeof window !== 'undefined' && window.mermaid) {
    try {
      // Use contentLoaded if available, otherwise use run
      if (typeof window.mermaid.contentLoaded === 'function') {
        window.mermaid.contentLoaded();
      } else if (typeof window.mermaid.run === 'function') {
        window.mermaid.run();
      } else {
        console.warn('[MermaidGraph] No suitable mermaid render method found');
      }
    } catch (e) {
      console.warn('[MermaidGraph] Failed to trigger mermaid render:', e);
    }
  } else {
    console.warn('[MermaidGraph] window.mermaid not available');
  }
}

// Helper for i18n
function $t(key) {
  return mw.msg(key);
}
</script>

<style scoped>
.mermaid-graph-container {
  margin: 20px 0;
  padding: 20px;
  background: #f8f9fa;
  border-radius: 8px;
}

h3 {
  margin-top: 0;
  margin-bottom: 16px;
  font-size: 1.2em;
}

.no-mermaid-message,
.no-graph-message {
  padding: 16px;
  text-align: center;
}

.no-graph-message p {
  margin: 0;
  color: #72777d;
}

.ext-mermaid {
  margin: 16px 0;
  min-height: 200px;
}
</style>

