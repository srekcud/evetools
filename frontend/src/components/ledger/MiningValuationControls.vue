<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useAuthStore } from '@/stores/auth'
import type { SelectedStructure } from '@/composables/useMiningValuationSettings'

interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
}

const SEARCH_DEBOUNCE_MS = 300
const DROPDOWN_CLOSE_DELAY_MS = 200
const MIN_QUERY_LENGTH = 2

const props = defineProps<{
  structure: SelectedStructure
  exportTax: number
}>()

const emit = defineEmits<{
  select: [structure: SelectedStructure]
  clear: []
  'update:exportTax': [value: number]
}>()

const { t } = useI18n()
const authStore = useAuthStore()

const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
const isSyncingStructure = ref(false)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

watch(structureSearchQuery, (query) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (!query || query.length < MIN_QUERY_LENGTH) {
    structureSearchResults.value = []
    return
  }
  searchTimeout = setTimeout(() => searchStructures(query), SEARCH_DEBOUNCE_MS)
})

async function searchStructures(query: string) {
  isSearchingStructures.value = true
  try {
    const response = await fetch(`/api/shopping-list/search-structures?q=${encodeURIComponent(query)}`, {
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Accept': 'application/json',
      },
    })
    if (response.ok) {
      const data = await response.json() as { results: StructureSearchResult[] }
      structureSearchResults.value = data.results
      showStructureDropdown.value = true
    }
  } catch (e) {
    console.error('Failed to search structures:', e)
  } finally {
    isSearchingStructures.value = false
  }
}

function selectStructure(structure: StructureSearchResult) {
  emit('select', { id: structure.id, name: structure.name })
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureDropdown.value = false
}

function clearStructure() {
  emit('clear')
  structureSearchQuery.value = ''
  structureSearchResults.value = []
}

function onStructureInputBlur() {
  setTimeout(() => { showStructureDropdown.value = false }, DROPDOWN_CLOSE_DELAY_MS)
}

async function syncStructureMarket() {
  if (!props.structure.id) return
  isSyncingStructure.value = true
  try {
    const response = await fetch('/api/shopping-list/sync-structure-market', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${authStore.token}`,
        'Accept': 'application/json',
      },
      body: JSON.stringify({ structureId: props.structure.id }),
    })
    if (!response.ok) throw new Error('Sync failed')
  } catch (e) {
    console.error('Failed to sync structure market:', e)
  } finally {
    isSyncingStructure.value = false
  }
}

function onExportTaxInput(event: Event) {
  const value = parseFloat((event.target as HTMLInputElement).value)
  if (!isNaN(value)) emit('update:exportTax', value)
}

function onExportTaxBlur(event: Event) {
  (event.target as HTMLInputElement).value = props.exportTax.toFixed(0)
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
    <div class="flex items-center gap-1">
      <span class="text-xs text-slate-500">{{ t('ledger.mining.exportTax') }} :</span>
      <input
        type="number"
        :value="exportTax.toFixed(0)"
        min="0"
        step="100"
        class="w-20 bg-slate-800/50 border border-slate-600 rounded-lg px-2 py-2 text-sm text-slate-200 text-right focus:outline-hidden focus:border-cyan-500/50"
        :title="t('ledger.mining.exportTaxTooltip')"
        @change="onExportTaxInput"
        @blur="onExportTaxBlur"
      />
      <span class="text-xs text-slate-500">ISK/m3</span>
    </div>

    <div class="flex min-w-0 items-center gap-2">
      <span class="text-xs text-slate-500">{{ t('ledger.mining.structureLabel') }} :</span>
      <div class="relative min-w-0">
        <input
          v-model="structureSearchQuery"
          type="text"
          :aria-label="t('ledger.mining.structureLabel')"
          :placeholder="structure.id ? structure.name : 'C-J6MT - 1st Taj Mahgoon (defaut)'"
          :title="structure.id ? structure.name : undefined"
          :class="[
            'w-full max-w-80 sm:w-80 rounded-lg pl-3 pr-10 py-2 text-sm truncate focus:outline-hidden',
            structure.id
              ? 'bg-slate-800/50 border-2 border-cyan-500/50 text-cyan-400 placeholder-cyan-400'
              : 'bg-slate-800/50 border border-slate-600 text-slate-200 placeholder-slate-400 focus:border-cyan-500/50'
          ]"
          @focus="showStructureDropdown = true"
          @blur="onStructureInputBlur"
        />
        <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center">
          <LoadingSpinner v-if="isSearchingStructures" size="sm" class="text-cyan-400" />
          <button
            v-else-if="structure.id || structureSearchQuery"
            class="text-slate-400 hover:text-slate-200"
            :title="t('industry.shoppingTab.resetToDefault')"
            :aria-label="t('industry.shoppingTab.resetToDefault')"
            @mousedown.prevent="clearStructure"
          >
            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div
          v-if="showStructureDropdown && structureSearchResults.length > 0"
          class="absolute z-50 top-full left-0 right-0 mt-1 bg-slate-800 border border-slate-600 rounded-lg shadow-xl max-h-60 overflow-y-auto"
        >
          <button
            v-for="result in structureSearchResults"
            :key="result.id"
            class="w-full px-3 py-2 text-left text-sm text-slate-200 hover:bg-slate-700/50 first:rounded-t-lg last:rounded-b-lg"
            @mousedown.prevent="selectStructure(result)"
          >
            {{ result.name }}
          </button>
        </div>
      </div>
      <button
        v-if="structure.id"
        :disabled="isSyncingStructure"
        class="px-2 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-300 text-sm disabled:opacity-50"
        :title="t('ledger.mining.syncStructure')"
        :aria-label="t('ledger.mining.syncStructure')"
        @click="syncStructureMarket"
      >
        <svg aria-hidden="true" :class="['w-4 h-4', isSyncingStructure ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
      </button>
    </div>
  </div>
</template>
