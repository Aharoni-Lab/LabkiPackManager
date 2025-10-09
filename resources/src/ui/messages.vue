<template>
  <!-- Render message stack only if there are messages -->
  <div v-if="messages?.length" class="lpm-row lpm-row-messages">
    <div class="lpm-messages" role="region" aria-label="System messages">
      <cdx-message
        v-for="m in messages"
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
 * using Codex <cdx-message> components.
 *
 * Props:
 *   - messages: Array of { id, type, text }
 *
 * Emits:
 *   - 'dismiss' (id: number): Fired when a message is closed.
 */

export default {
  name: 'LpmMessages',

  props: {
    /**
     * Array of message objects to display.
     * Each message: { id: number, type: string, text: string }
     */
    messages: {
      type: Array,
      required: true,
      validator(val) {
        return val.every(
          m =>
            typeof m.id === 'number' &&
            typeof m.type === 'string' &&
            typeof m.text === 'string'
        );
      }
    }
  },

  emits: ['dismiss']
};
</script>

<style scoped>
/* Layout container for the message region */
.lpm-row-messages {
  margin-top: 1rem;
}

/* Stack messages vertically with spacing */
.lpm-messages {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

/* Optional: subtle fade-in for new messages */
.lpm-message-item {
  transition: opacity 0.3s ease;
}
</style>
