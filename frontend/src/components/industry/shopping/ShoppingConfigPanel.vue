<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { authFetch, safeJsonParse } from '@/services/api'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import type { SyncProgress } from '@/stores/sync'

interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
  solarSystemId: number | null
}

defineProps<{
  selectedStructure: { id: number | null; name: string }
  defaultStructureName: string
  transportCostPerM3: number
  pastedStock: string
  parsedStockCount: number
  stockAnalysisLoading: boolean
  shoppingSyncing: boolean
  marketStructureProgress: SyncProgress | null
}>()

const emit = defineEmits<{
  'update:selectedStructure': [value: { id: number | null; name: string }]
  'update:transportCostPerM3': [value: number]
  'update:pastedStock': [value: string]
  syncStructure: []
  recalculate: []
  analyzeStock: []
  confirmClearStock: []
}>()

const { t } = useI18n()
const authStore = useAuthStore()

const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
let structureSearchTimeout: ReturnType<typeof setTimeout> | null = null

watch(structureSearchQuery, (query) => {
  if (structureSearchTimeout) clearTimeout(structureSearchTimeout)
  if (query.length < 3) {
    structureSearchResults.value = []
    return
  }
  structureSearchTimeout = setTimeout(() => {
    searchStructures(query)
  }, 300)
})

async function searchStructures(query: string) {
  isSearchingStructures.value = true
  try {
    const response = await authFetch(`/api/shopping-list/search-structures?q=${encodeURIComponent(query)}`, {
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    if (response.ok) {
      const data = await safeJsonParse<{ results: StructureSearchResult[] }>(response)
      structureSearchResults.value = data.results
      showStructureDropdown.value = true
    }
  } catch (e) {
    console.error('Structure search failed:', e)
  } finally {
    isSearchingStructures.value = false
  }
}

function selectStructure(structure: StructureSearchResult) {
  emit('update:selectedStructure', { id: structure.id, name: structure.name })
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureDropdown.value = false
}

function clearStructure() {
  emit('update:selectedStructure', { id: null, name: '' })
  structureSearchQuery.value = ''
  structureSearchResults.value = []
}

function onStructureInputBlur() {
  setTimeout(() => {
    showStructureDropdown.value = false
  }, 200)
}
</script>

<template>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <!-- Col 1: Structure selector -->
    <div class="eve-card p-4 flex flex-col">
      <label class="block text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.shoppingTab.marketStructure') }}</label>
      <div class="relative">
        <input
          v-model="structureSearchQuery"
          type="text"
          :placeholder="selectedStructure.name || defaultStructureName"
          :class="[
            'w-full bg-slate-800 border rounded-sm pl-3 pr-8 py-1.5 text-sm focus:outline-hidden',
            selectedStructure.id
              ? 'border-cyan-500/50 text-cyan-400 placeholder-cyan-400'
              : 'border-slate-600 text-slate-200 placeholder-slate-400 focus:border-cyan-500/50'
          ]"
          @focus="showStructureDropdown = true"
          @blur="onStructureInputBlur"
        />
        <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center">
          <LoadingSpinner v-if="isSearchingStructures" size="sm" class="text-cyan-400" />
          <button
            v-else-if="selectedStructure.id || structureSearchQuery"
            @mousedown.prevent="clearStructure"
            class="text-slate-400 hover:text-slate-200"
            :title="t('industry.shoppingTab.resetToDefault')"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <!-- Dropdown -->
        <div
          v-if="showStructureDropdown && (structureSearchResults.length > 0 || (structureSearchQuery.length >= 3 && !isSearchingStructures))"
          class="absolute z-50 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-48 overflow-y-auto"
        >
          <button
            v-for="struct in structureSearchResults"
            :key="struct.id"
            @mousedown.prevent="selectStructure(struct)"
            class="w-full px-3 py-2 text-left text-sm text-slate-200 hover:bg-slate-700/50 transition-colors border-b border-slate-700/50 last:border-0"
          >
            <div class="truncate">{{ struct.name }}</div>
          </button>
          <div
            v-if="structureSearchQuery.length >= 3 && structureSearchResults.length === 0 && !isSearchingStructures"
            class="px-3 py-2 text-slate-400 text-sm"
          >
            {{ t('industry.shoppingTab.noStructureFound') }}
          </div>
        </div>
      </div>
      <button
        @click="emit('syncStructure')"
        :disabled="shoppingSyncing || (marketStructureProgress?.status === 'started' || marketStructureProgress?.status === 'in_progress')"
        class="mt-auto w-full px-3 py-1.5 bg-cyan-500/20 border border-cyan-500/50 text-cyan-400 rounded-sm text-xs font-medium disabled:opacity-50 flex items-center justify-center gap-1.5 hover:bg-cyan-500/30 transition-colors"
      >
        <LoadingSpinner v-if="shoppingSyncing" size="xs+" />
        {{ t('common.actions.sync') }}
      </button>
    </div>

    <!-- Col 2: Transport cost -->
    <div class="eve-card p-4 flex flex-col">
      <label class="block text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.shoppingTab.transportCost') }}</label>
      <div class="flex items-center gap-1.5">
        <input
          :value="transportCostPerM3"
          @input="emit('update:transportCostPerM3', Number(($event.target as HTMLInputElement).value))"
          type="number"
          min="0"
          step="100"
          class="w-full px-2 py-1.5 bg-slate-800 border border-slate-600 rounded-sm text-slate-200 text-sm font-mono focus:outline-hidden focus:border-cyan-500"
        />
        <span class="text-xs text-slate-500 whitespace-nowrap">ISK/m3</span>
      </div>
      <button
        @click="emit('recalculate')"
        class="mt-auto w-full px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 rounded-sm text-white text-xs font-medium"
      >
        {{ t('industry.shoppingTab.recalculate') }}
      </button>
    </div>

    <!-- Col 3: Stock rapide -->
    <div class="eve-card p-4 flex flex-col">
      <label class="block text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.shoppingTab.quickStock') }}</label>
      <textarea
        :value="pastedStock"
        @input="emit('update:pastedStock', ($event.target as HTMLTextAreaElement).value)"
        :placeholder="t('industry.shoppingTab.pasteInventory')"
        class="w-full h-12 bg-slate-800 border border-slate-700 rounded-sm p-2 text-xs font-mono text-slate-200 placeholder-slate-500 focus:outline-hidden focus:border-cyan-500 resize-none"
      />
      <div class="flex gap-2 mt-auto">
        <button
          @click="emit('analyzeStock')"
          :disabled="stockAnalysisLoading"
          class="flex-1 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 rounded-sm text-white text-xs font-medium"
        >
          {{ t('industry.shoppingTab.apply') }}
        </button>
        <button
          v-if="parsedStockCount > 0"
          @click="emit('confirmClearStock')"
          class="px-3 py-1.5 text-red-400 hover:bg-red-500/10 border border-red-500/30 rounded-sm text-xs"
        >
          {{ t('common.actions.clear') }}
        </button>
      </div>
    </div>
  </div>
</template>
