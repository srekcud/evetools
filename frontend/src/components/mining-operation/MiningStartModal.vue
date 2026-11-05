<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningSolarSystemSearch, { type SolarSystemResult } from './MiningSolarSystemSearch.vue'

const props = defineProps<{
  show: boolean
  label: string
  submitting: boolean
}>()

const emit = defineEmits<{
  close: []
  submit: [solarSystemId: number | null]
}>()

const { t } = useI18n()

const selected = ref<SolarSystemResult | null>(null)

function submit(): void {
  emit('submit', selected.value === null ? null : selected.value.solarSystemId)
}

watch(() => props.show, (show) => {
  if (show) selected.value = null
})
</script>

<template>
  <BaseModal :show="show" :title="t('miningOperation.start.title', { label })" panel-class="max-w-lg border-slate-700" @close="emit('close')">
    <template #title="{ titleId }">
      <h2 :id="titleId" class="truncate px-6 pt-6 font-display text-xl font-bold text-slate-100" :title="label">{{ t('miningOperation.start.title', { label }) }}</h2>
    </template>

    <form class="space-y-4 p-6" @submit.prevent="submit">
      <MiningSolarSystemSearch v-model="selected" :label="t('miningOperation.start.system')" :none-label="t('miningOperation.start.systemNone')" />

      <p class="text-xs text-slate-400">{{ t('miningOperation.start.hint') }}</p>

      <div class="flex justify-end gap-3">
        <button type="button" class="rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800" @click="emit('close')">{{ t('common.actions.cancel') }}</button>
        <button type="button" :disabled="submitting" class="flex items-center gap-2 rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50" @click="submit">
          <LoadingSpinner v-if="submitting" size="sm" />
          {{ t('miningOperation.start.submit') }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
