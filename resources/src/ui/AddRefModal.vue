<template>
  <cdx-dialog
    v-model:open="isOpen"
    :title="$t('labkipackmanager-add-ref-title')"
    :primary-action="{
      label: $t('labkipackmanager-add-ref-submit'),
      actionType: 'progressive',
      disabled: busy || !isValid,
    }"
    :default-action="{
      label: $t('labkipackmanager-cancel'),
    }"
    @primary="onSubmit"
    @default="onCancel"
  >
    <div class="add-ref-form">
      <p class="repo-info">
        <strong>{{ $t('labkipackmanager-repository') }}:</strong> {{ repoUrl }}
      </p>

      <cdx-field :is-fieldset="false">
        <template #label>{{ $t('labkipackmanager-ref-name-label') }}</template>
        <cdx-text-input
          v-model="refName"
          :placeholder="$t('labkipackmanager-ref-name-placeholder')"
          :disabled="busy"
        />
      </cdx-field>

      <cdx-message v-if="statusMessage && !error" type="notice" :inline="true">
        {{ statusMessage }}
      </cdx-message>

      <cdx-message v-if="error" type="error" :inline="true">
        {{ error }}
      </cdx-message>
    </div>
  </cdx-dialog>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { CdxDialog, CdxField, CdxTextInput, CdxMessage } from '@wikimedia/codex';
import { reposAdd, pollOperation } from '../api/endpoints';

const props = defineProps({
  modelValue: Boolean,
  repoUrl: String,
});

const emit = defineEmits(['update:modelValue', 'added']);

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
});

const refName = ref('');
const error = ref('');
const busy = ref(false);
const statusMessage = ref('');

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
    const refNameValue = refName.value.trim();

    // Step 1: Queue the job
    statusMessage.value = 'Queueing ref initialization...';
    const response = await reposAdd(props.repoUrl, refNameValue);

    console.log('[AddRefModal] reposAdd response:', response);
    console.log('[AddRefModal] Response keys:', Object.keys(response || {}));
    console.log('[AddRefModal] operation_id:', response?.operation_id);
    console.log('[AddRefModal] labkiReposAdd:', response?.labkiReposAdd);

    // Step 2: If we got an operation_id, wait for the background job to complete
    if (response?.operation_id) {
      console.log(`[AddRefModal] Polling operation ${response.operation_id}...`);

      // Poll with status updates
      await pollOperation(response.operation_id, 60, 1000, (status) => {
        // Update modal message based on operation status
        if (status.message) {
          statusMessage.value = status.message;
        } else if (status.status === 'queued') {
          statusMessage.value = 'Waiting for job to start...';
        } else if (status.status === 'running') {
          statusMessage.value = `Creating worktree... (${status.progress || 0}%)`;
        }
      });

      console.log('[AddRefModal] Operation completed successfully');
      statusMessage.value = 'Ref initialized successfully!';
    } else {
      // Ref already existed
      console.log('[AddRefModal] No operation_id, ref may already exist');
      statusMessage.value = 'Ref already exists';
    }

    // Step 3: Notify parent that everything is ready
    emit('added', {
      refName: refNameValue,
      operationId: response?.operation_id || null,
    });

    // Close after a brief moment to show success message
    await new Promise((resolve) => setTimeout(resolve, 500));
    onCancel();
  } catch (e) {
    console.error('[AddRefModal] Error adding ref:', e);
    error.value = e instanceof Error ? e.message : String(e);
    statusMessage.value = '';
  } finally {
    busy.value = false;
  }
}

function onCancel() {
  refName.value = '';
  error.value = '';
  statusMessage.value = '';
  isOpen.value = false;
}

// Helper for i18n
function $t(key: string) {
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
