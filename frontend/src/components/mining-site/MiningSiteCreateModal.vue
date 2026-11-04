<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningSolarSystemSearch, { type SolarSystemResult } from '@/components/mining-operation/MiningSolarSystemSearch.vue'
import { useMiningSiteStore } from '@/stores/miningSite'

const LABEL_MAX_LENGTH = 100

const props = defineProps<{ show: boolean; operationId?: string }>()
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const router = useRouter()
const store = useMiningSiteStore()

const label = ref('')
const system = ref<SolarSystemResult | null>(null)
const failure = ref<string | null>(null)
const submitting = ref(false)

async function submit(): Promise<void> {
  const trimmedLabel = label.value.trim()
  if (trimmedLabel === '') {
    failure.value = t('miningSite.create.labelRequired')
    return
  }
  failure.value = null
  submitting.value = true
  try {
    const created = await store.create({ label: trimmedLabel, solarSystemId: system.value === null ? null : system.value.solarSystemId })
    emit('close')
    await router.push({ name: 'mining-site-detail', params: { id: created.id } })
  } catch (cause) {
    failure.value = cause instanceof Error ? cause.message : String(cause)
  } finally {
    submitting.value = false
  }
}

watch(() => props.show, (show) => {
  if (!show) return
  label.value = ''
  system.value = null
  failure.value = null
})
</script>

<template>
  <BaseModal :show="show" :title="t('miningSite.create.title')" panel-class="max-w-lg border-slate-700" @close="emit('close')">
    <template #title="{ titleId }">
      <h2 :id="titleId" class="truncate px-6 pt-6 font-display text-xl font-bold text-slate-100">{{ t('miningSite.create.title') }}</h2>
    </template>

    <form class="space-y-4 p-6" data-testid="create-form" @submit.prevent="submit">
      <div>
        <label for="mining-site-label" class="mb-1 block text-sm font-medium text-slate-300">{{ t('miningSite.create.label') }}</label>
        <input
          id="mining-site-label"
          v-model="label"
          name="label"
          type="text"
          :maxlength="LABEL_MAX_LENGTH"
          autocomplete="off"
          class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100"
        />
      </div>

      <MiningSolarSystemSearch v-model="system" :label="t('miningSite.create.system')" :none-label="t('miningSite.create.systemNone')" />

      <p v-if="failure !== null" class="text-sm text-red-400" role="alert" data-testid="create-error">{{ failure }}</p>

      <div class="flex justify-end gap-3">
        <button type="button" class="rounded-md border border-slate-700 px-4 py-2 text-sm text-slate-300 hover:bg-slate-800" @click="emit('close')">{{ t('common.actions.cancel') }}</button>
        <button type="submit" :disabled="submitting" class="flex items-center gap-2 rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50">
          <LoadingSpinner v-if="submitting" size="sm" />
          {{ t('miningSite.create.submit') }}
        </button>
      </div>
    </form>
  </BaseModal>
</template>
