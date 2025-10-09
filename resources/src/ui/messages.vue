<template>
  <div class="lpm-row lpm-row-messages" v-if="messages.length">
    <div class="lpm-messages">
      <cdx-message
        v-for="m in messages"
        :key="m.id"
        :type="m.type"
        dismissible
        @dismiss="$emit('dismiss', m.id)"
      >
        {{ m.text }}
      </cdx-message>
    </div>
  </div>
</template>

<script>
export default {
  name: 'LpmMessages',
  props: { messages: { type: Array, required: true } }
};
</script>


