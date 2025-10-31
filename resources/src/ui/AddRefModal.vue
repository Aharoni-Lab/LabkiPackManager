<template>
  <cdx-dialog
    v-model:open="isOpen"
    :title="$t('labkipackmanager-add-ref-title')"
    :primary-action="{
      label: $t('labkipackmanager-add-ref-submit'),
      actionType: 'progressive',
      disabled: busy || !isValid
    }"
    :default-action="{
      label: $t('labkipackmanager-cancel')
    }"
    @primary="onSubmit"
    @default="onCancel"
  >
    <div class="add-ref-form">
      <p class="repo-info">
        <strong>{{ $t('labkipackmanager-repository') }}:</strong> {{ repoUrl }}
      </p>

      <cdx-field
        :is-fieldset="false"
      >
        <template #label>{{ $t('labkipackmanager-ref-name-label') }}</template>
        <cdx-text-input
          v-model="refName"
          :placeholder="$t('labkipackmanager-ref-name-placeholder')"
          :disabled="busy"
        />
      </cdx-field>

      <cdx-message
        v-if="error"
        type="error"
        :inline="true"
      >
        {{ error }}
      </cdx-message>
    </div>
  </cdx-dialog>
</template>

<script setup>
import { ref, computed } from 'vue';
import { CdxDialog, CdxField, CdxTextInput, CdxMessage } from '@wikimedia/codex';
import { reposAdd } from '../api/endpoints';

const props = defineProps({
  modelValue: Boolean,
  repoUrl: String
});

const emit = defineEmits(['update:modelValue', 'added']);

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
});

const refName = ref('');
const error = ref('');
const busy = ref(false);

const isValid = computed(() => {
  return refName.value.trim() !== '';
});

async function onSubmit() {
  if (!isValid.value || busy.value) {
    return;
  }

  busy.value = true;
  error.value = '';

  try {
    // Add the same repo with a new ref
    await reposAdd(props.repoUrl, refName.value.trim());
    emit('added', refName.value.trim());
    onCancel(); // Close and reset
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    busy.value = false;
  }
}

function onCancel() {
  refName.value = '';
  error.value = '';
  isOpen.value = false;
}

// Helper for i18n
function $t(key) {
  return mw.msg(key);
}
</script>

<style scoped>
.add-ref-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.repo-info {
  padding: 8px 12px;
  background: #f8f9fa;
  border-radius: 4px;
  margin: 0;
}
</style>

