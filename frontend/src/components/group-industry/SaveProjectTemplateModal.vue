<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useProjectTemplatesStore } from '@/stores/group-industry/templates'

const props = defineProps<{
  show: boolean
  projectId: string
  defaultName: string
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const { t } = useI18n()
const store = useProjectTemplatesStore()
const nameFieldId = useId()

const name = ref(props.defaultName)
const sharedWithCorporation = ref(false)
const isSaving = ref(false)
const errorMessage = ref<string | null>(null)

const trimmedName = computed(() => name.value.trim())

watch(() => props.show, (isShown) => {
  if (!isShown) return
  name.value = props.defaultName
  sharedWithCorporation.value = false
  errorMessage.value = null
})

async function submit(): Promise<void> {
  if (trimmedName.value === '' || isSaving.value) return
  isSaving.value = true
  errorMessage.value = null
  try {
    await store.saveTemplate({
      projectId: props.projectId,
      name: trimmedName.value,
      sharedWithCorporation: sharedWithCorporation.value,
    })
    emit('saved')
  } catch (cause) {
    errorMessage.value = cause instanceof Error ? cause.message : 'Failed to save project template'
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <BaseModal
    :show="show"
    :title="t('groupIndustry.templates.saveTitle')"
    panel-class="border-slate-700 max-w-md"
    @close="emit('close')"
  >
    <template #title="{ titleId }">
      <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
        <div>
          <h2 :id="titleId" class="text-lg font-bold text-slate-100">{{ t('groupIndustry.templates.saveTitle') }}</h2>
          <p class="text-sm text-slate-500 mt-0.5">{{ t('groupIndustry.templates.saveSubtitle') }}</p>
        </div>
        <button
          type="button"
          class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
          :title="t('common.actions.close')"
          :aria-label="t('common.actions.close')"
          @click="emit('close')"
        >
          <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </template>

    <div class="px-6 py-5 space-y-4">
      <div>
        <label :for="nameFieldId" class="text-sm font-medium text-slate-300 mb-1.5 block">{{ t('groupIndustry.templates.nameLabel') }}</label>
        <input
          :id="nameFieldId"
          v-model="name"
          data-testid="template-name-input"
          type="text"
          maxlength="255"
          :placeholder="t('groupIndustry.templates.namePlaceholder')"
          class="form-input w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 placeholder-slate-500"
          @keydown.enter="submit"
        />
      </div>

      <label class="flex items-start gap-3 cursor-pointer">
        <input
          v-model="sharedWithCorporation"
          data-testid="template-share-checkbox"
          type="checkbox"
          class="mt-0.5 w-4 h-4 rounded border-slate-600 bg-slate-800 text-cyan-500"
        />
        <span>
          <span class="text-sm text-slate-200 block">{{ t('groupIndustry.templates.shareLabel') }}</span>
          <span class="text-xs text-slate-500 block">{{ t('groupIndustry.templates.shareHint') }}</span>
        </span>
      </label>

      <p v-if="errorMessage" role="alert" class="text-sm text-red-400">{{ errorMessage }}</p>
    </div>

    <div class="px-6 pb-6 flex items-center gap-3">
      <button
        type="button"
        class="flex-1 px-4 py-2.5 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-700 transition-colors"
        @click="emit('close')"
      >{{ t('common.actions.cancel') }}</button>
      <button
        type="button"
        data-testid="template-save-submit"
        :disabled="trimmedName === '' || isSaving"
        class="flex-1 px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-700 disabled:text-slate-500 rounded-lg text-white text-sm font-medium transition-colors flex items-center justify-center gap-2"
        @click="submit"
      >
        <LoadingSpinner v-if="isSaving" size="sm" />
        {{ t('groupIndustry.templates.saveButton') }}
      </button>
    </div>
  </BaseModal>
</template>
