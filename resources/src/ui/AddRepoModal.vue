<template>
  <cdx-dialog
    v-model:open="isOpen"
    :title="$t('labkipackmanager-add-repo-title')"
    :primary-action="{
      label: $t('labkipackmanager-add-repo-submit'),
      actionType: 'progressive',
      disabled: busy || !isValid
    }"
    :default-action="{
      label: $t('labkipackmanager-cancel')
    }"
    @primary="onSubmit"
    @default="onCancel"
  >
    <div class="add-repo-form">
      <cdx-field
        :is-fieldset="false"
      >
        <template #label>{{ $t('labkipackmanager-repo-url-label') }}</template>
        <cdx-text-input
          v-model="repoUrl"
          :placeholder="$t('labkipackmanager-repo-url-placeholder')"
          :disabled="busy"
          @update:model-value="validateUrl"
        />
        <template v-if="urlError" #help-text>
          <span class="error">{{ urlError }}</span>
        </template>
      </cdx-field>

      <cdx-field
        :is-fieldset="false"
      >
        <template #label>{{ $t('labkipackmanager-default-ref-label') }}</template>
        <cdx-text-input
          v-model="defaultRef"
          :placeholder="$t('labkipackmanager-default-ref-placeholder')"
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
  modelValue: Boolean
});

const emit = defineEmits(['update:modelValue', 'added']);

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
});

const repoUrl = ref('');
const defaultRef = ref('main');
const urlError = ref('');
const error = ref('');
const busy = ref(false);

const isValid = computed(() => {
  return repoUrl.value.trim() !== '' && defaultRef.value.trim() !== '' && !urlError.value;
});

function validateUrl() {
  urlError.value = '';
  const url = repoUrl.value.trim();
  
  if (!url) {
    return;
  }

  // Basic URL validation
  try {
    new URL(url);
    if (!url.match(/^(https?|git|ssh):\/\//)) {
      urlError.value = mw.msg('labkipackmanager-error-invalid-protocol');
    }
  } catch {
    urlError.value = mw.msg('labkipackmanager-error-invalid-url');
  }
}

async function onSubmit() {
  if (!isValid.value || busy.value) {
    return;
  }

  busy.value = true;
  error.value = '';

  try {
    await reposAdd(repoUrl.value.trim(), defaultRef.value.trim());
    emit('added', repoUrl.value.trim());
    onCancel(); // Close and reset
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    busy.value = false;
  }
}

function onCancel() {
  repoUrl.value = '';
  defaultRef.value = 'main';
  urlError.value = '';
  error.value = '';
  isOpen.value = false;
}

// Helper for i18n
function $t(key) {
  return mw.msg(key);
}
</script>

<style scoped>
.add-repo-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.error {
  color: #d33;
}
</style>

