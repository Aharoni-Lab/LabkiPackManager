<template>
  <div v-if="normalizedMessages.length" class="lpm-row lpm-row-messages">
    <div class="lpm-messages" role="region" aria-label="System messages">
      <cdx-message
        v-for="m in normalizedMessages"
        :key="m.id"
        :type="m.type"
        dismissible
        class="lpm-message-item"
        @dismiss="$emit('dismiss', m.id)"
      >
        {{ m.text }}
      </cdx-message>
    </div>
  </div>
</template>

<script>
/**
 * LpmMessages – Transient Notification Stack
 * ------------------------------------------------------------
 * Displays user-facing messages (success, error, info, warning)
 * using Codex <cdx-message> components, with compatibility for
 * older Codex builds that lack an "info" icon.
 */

export default {
  name: 'LpmMessages',

  props: {
    messages: {
      type: Array,
      required: true,
      default: () => [],
    }
  },

  emits: ['dismiss'],

  computed: {
    normalizedMessages() {
      // Detect whether Codex supports the "info" variant (MW 1.45+)
      const hasInfoIcon =
        typeof window !== 'undefined' &&
        window.Codex &&
        window.Codex.icons &&
        'cdx-icon-info' in window.Codex.icons;

      const validTypes = ['success', 'warning', 'error', 'notice', 'info'];

      return this.messages.map(m => {
        const baseType = validTypes.includes(m.type) ? m.type : 'notice';
        const safeType = baseType === 'info' && !hasInfoIcon ? 'notice' : baseType;

        return {
          id: m.id ?? Math.floor(Math.random() * 1e6),
          text: m.text ?? '',
          type: safeType
        };
      });
    }
  }
};
</script>

<style scoped>
.lpm-row-messages {
  margin-top: 1rem;
}

.lpm-messages {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.lpm-message-item {
  transition: opacity 0.3s ease;
}
</style>
