<template>
  <div class="lpm-row lpm-row-toolbar" role="toolbar" aria-label="Repository controls">
    <!-- Repository selector -->
    <cdx-select
      id="lpm-repo-select"
      aria-label="Repository selector"
      :menu-items="repoMenuItems"
      :selected="activeRepo"
      :disabled="isLoadingRepo"
      placeholder="Select a content repository…"
      @update:selected="$emit('update:activeRepo', $event)"
      class="lpm-toolbar-select"
    />

    <!-- Load button -->
    <cdx-button
      appearance="primary"
      :disabled="isLoadingRepo || !hasActiveRepo"
      @click="$emit('load')"
      class="lpm-toolbar-btn"
    >
      <template v-if="isLoadingRepo">
        <span aria-busy="true" aria-live="polite">Loading…</span>
      </template>
      <template v-else>Load</template>
    </cdx-button>

    <!-- Refresh button -->
    <cdx-button
      appearance="quiet"
      :disabled="isRefreshing || !hasActiveRepo"
      @click="$emit('refresh')"
      class="lpm-toolbar-btn"
    >
      <template v-if="isRefreshing">
        <span aria-busy="true" aria-live="polite">Refreshing…</span>
      </template>
      <template v-else>Refresh</template>
    </cdx-button>
  </div>
</template>

<script>
/**
 * LpmToolbar – Repository Control Row
 * ------------------------------------------------------------
 * Provides the top toolbar with repository selector,
 * "Load" and "Refresh" controls.
 *
 * Props:
 *   - repoMenuItems: Menu items for Codex select dropdown.
 *   - activeRepo: Currently selected repository URL/key.
 *   - isLoadingRepo: Whether a repository is being loaded.
 *   - isRefreshing: Whether a repository is being refreshed.
 *   - hasActiveRepo: Whether a valid activeRepo exists.
 *
 * Emits:
 *   - update:activeRepo (string)
 *   - load
 *   - refresh
 */

export default {
  name: 'LpmToolbar',

  props: {
    /** Menu items for repository selector: [{ label, value }] */
    repoMenuItems: {
      type: Array,
      required: true,
      validator(items) {
        return Array.isArray(items) && items.every(i => i.label && i.value);
      }
    },

    /** Currently selected repository URL or key. */
    activeRepo: {
      type: String,
      default: null
    },

    /** Whether repository is being loaded (disables controls). */
    isLoadingRepo: {
      type: Boolean,
      default: false
    },

    /** Whether repository manifest is being refreshed. */
    isRefreshing: {
      type: Boolean,
      default: false
    },

    /** Whether a repository is selected and available. */
    hasActiveRepo: {
      type: Boolean,
      required: true
    }
  },

  emits: ['update:activeRepo', 'load', 'refresh']
};
</script>

<style scoped>
.lpm-row-toolbar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 0;
  flex-wrap: wrap;
}

.lpm-toolbar-select {
  min-width: 260px;
}

.lpm-toolbar-btn {
  flex-shrink: 0;
}
</style>
