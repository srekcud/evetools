<script lang="ts">
export interface SolarSystemResult {
  solarSystemId: number
  solarSystemName: string
  security: number
  regionName: string
}
</script>

<script setup lang="ts">
import { onBeforeUnmount, ref, useId, watch } from 'vue'
import { apiRequest } from '@/services/api'

const MIN_QUERY_LENGTH = 2
const SEARCH_DEBOUNCE_MS = 300

defineProps<{
  label: string
  noneLabel: string
}>()

const selected = defineModel<SolarSystemResult | null>({ required: true })

const fieldId = useId()

const query = ref('')
const results = ref<SolarSystemResult[]>([])
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

watch(selected, (system) => {
  if (system !== null) return
  cancelPendingSearch()
  query.value = ''
  results.value = []
  searchFailure.value = null
})

onBeforeUnmount(cancelPendingSearch)
</script>

<template>
  <div>
    <label :for="`${fieldId}-system`" class="mb-1 block text-sm font-medium text-slate-300">{{ label }}</label>
    <p v-if="selected !== null" class="mb-2 font-mono text-sm text-cyan-200">
      <span class="truncate" :title="selected.regionName">{{ selected.solarSystemName }}</span>
    </p>
    <p v-else class="mb-2 text-sm text-slate-400">{{ noneLabel }}</p>
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
</template>
