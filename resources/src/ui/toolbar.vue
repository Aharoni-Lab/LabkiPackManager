<template>
  <div class="lpm-row lpm-row-toolbar">
    <cdx-select
      id="lpm-repo-select"
      aria-label="Repository"
      :menu-items="repoMenuItems"
      :selected="activeRepo"
      placeholder="Select a content repository…"
      @update:selected="$emit('update:activeRepo', $event)"
    />
    <cdx-button :disabled="isLoadingRepo || !hasActiveRepo" @click="$emit('load')">
      <template v-if="isLoadingRepo">Loading…</template>
      <template v-else>Load</template>
    </cdx-button>
    <cdx-button :disabled="isRefreshing || !hasActiveRepo" @click="$emit('refresh')">
      <template v-if="isRefreshing">Refreshing…</template>
      <template v-else>Refresh</template>
    </cdx-button>
  </div>
  
</template>

<script>
export default {
  name: 'LpmToolbar',
  props: {
    repoMenuItems: { type: Array, required: true },
    activeRepo: { type: String, default: null },
    isLoadingRepo: { type: Boolean, default: false },
    isRefreshing: { type: Boolean, default: false },
    hasActiveRepo: { type: Boolean, required: true }
  },
  emits: ['update:activeRepo', 'load', 'refresh']
};
</script>


