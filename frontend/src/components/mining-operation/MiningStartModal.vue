<script setup lang="ts">
import { onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { apiRequest } from '@/services/api'

interface SolarSystemResult {
  solarSystemId: number
  solarSystemName: string
  security: number
  regionName: string
}

const MIN_QUERY_LENGTH = 2
const SEARCH_DEBOUNCE_MS = 300

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
const fieldId = useId()

const query = ref('')
const results = ref<SolarSystemResult[]>([])
const selected = ref<SolarSystemResult | null>(null)
const searchFailure = ref<string | null>(null)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

function cancelPendingSearch(): void {
  if (searchTimeout !== null) clearTimeout(searchTimeout)
  searchTimeout = null
}

async function search(text: string): Promise<void> {
  searchFailure.value = null
  try {
    results.value = await apiRequest<SolarSystemResult[]>(`/sde/solar-systems?q=${encodeURIComponent(text)}`)
  } catch (cause) {
    results.value = []
    searchFailure.value = cause instanceof Error ? cause.message : String(cause)
  }
}

function submit(): void {
  emit('submit', selected.value === null ? null : selected.value.solarSystemId)
}

function pick(system: SolarSystemResult): void {
  selected.value = system
  query.value = ''
  results.value = []
}

watch(query, (text) => {
  cancelPendingSearch()
  if (text.length < MIN_QUERY_LENGTH) {
    results.value = []
    return
  }
  searchTimeout = setTimeout(() => search(text), SEARCH_DEBOUNCE_MS)
})

watch(() => props.show, (show) => {
  if (!show) return
  cancelPendingSearch()
  query.value = ''
  results.value = []
  selected.value = null
  searchFailure.value = null
})

onBeforeUnmount(cancelPendingSearch)
</script>

<template>
  <BaseModal :show="show" :title="t('miningOperation.start.title', { label })" panel-class="max-w-lg border-slate-700" @close="emit('close')">
    <template #title="{ titleId }">
      <h2 :id="titleId" class="truncate px-6 pt-6 font-display text-xl font-bold text-slate-100" :title="label">{{ t('miningOperation.start.title', { label }) }}</h2>
    </template>

    <form class="space-y-4 p-6" @submit.prevent="submit">
      <div>
        <label :for="`${fieldId}-system`" class="mb-1 block text-sm font-medium text-slate-300">{{ t('miningOperation.start.system') }}</label>
        <p v-if="selected !== null" class="mb-2 font-mono text-sm text-cyan-200">
          <span class="truncate" :title="selected.regionName">{{ selected.solarSystemName }}</span>
        </p>
        <p v-else class="mb-2 text-sm text-slate-400">{{ t('miningOperation.start.systemNone') }}</p>
        <input
          :id="`${fieldId}-system`"
          v-model="query"
          name="solarSystem"
          type="text"
          autocomplete="off"
          class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100"
        />
        <ul v-if="results.length > 0" class="mt-1 max-h-48 overflow-y-auto rounded-md border border-slate-700 bg-slate-950">
          <li v-for="system in results" :key="system.solarSystemId">
            <button
              type="button"
              class="block w-full truncate px-3 py-1.5 text-left text-sm text-slate-200 hover:bg-slate-800"
              :title="`${system.regionName} · ${system.security.toFixed(1)}`"
              @click="pick(system)"
            >{{ system.solarSystemName }}</button>
          </li>
        </ul>
        <p v-if="searchFailure !== null" class="mt-1 text-xs text-red-400" role="alert">{{ searchFailure }}</p>
      </div>

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
