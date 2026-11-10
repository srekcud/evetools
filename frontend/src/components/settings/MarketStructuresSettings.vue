<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import { useStructuresStore } from '@/stores/industry/structures'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

interface MarketStructure {
  id: number
  name: string
}

interface StructureSearchResult {
  locationId: number
  locationName: string
  solarSystemName: string | null
}

const { t } = useI18n()
const industryStructuresStore = useStructuresStore()

const marketStructures = ref<MarketStructure[]>([])
const loadingStructures = ref(false)
const savingStructure = ref(false)
const structureSaveSuccess = ref(false)
const loadFailed = ref(false)

const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureSearchDropdown = ref(false)
let structureSearchTimeout: number | undefined

function onStructureSearchInput(): void {
  clearTimeout(structureSearchTimeout)
  if (structureSearchQuery.value.length < 3) {
    structureSearchResults.value = []
    showStructureSearchDropdown.value = false
    return
  }
  structureSearchTimeout = window.setTimeout(async () => {
    isSearchingStructures.value = true
    const result = await industryStructuresStore.searchStructures(structureSearchQuery.value)
    structureSearchResults.value = result.structures.map(s => ({
      locationId: s.locationId,
      locationName: s.locationName,
      solarSystemName: s.solarSystemName,
    }))
    showStructureSearchDropdown.value = result.structures.length > 0
    isSearchingStructures.value = false
  }, 500)
}

function selectStructureSearchResult(result: StructureSearchResult): void {
  if (!marketStructures.value.some(s => s.id === result.locationId)) {
    marketStructures.value.push({ id: result.locationId, name: result.locationName })
    saveMarketStructures()
  }
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureSearchDropdown.value = false
}

function hideStructureDropdownDelayed(): void {
  window.setTimeout(() => {
    showStructureSearchDropdown.value = false
  }, 200)
}

function removeMarketStructure(id: number): void {
  marketStructures.value = marketStructures.value.filter(s => s.id !== id)
  saveMarketStructures()
}

async function loadMarketSettings(): Promise<void> {
  loadingStructures.value = true
  loadFailed.value = false
  try {
    const settings = await apiRequest<{
      preferredMarketStructureId: number | null
      preferredMarketStructureName: string | null
      marketStructures: MarketStructure[]
    }>('/me/settings')
    if (settings.marketStructures?.length > 0) {
      marketStructures.value = settings.marketStructures
    } else if (settings.preferredMarketStructureId) {
      marketStructures.value = [{ id: settings.preferredMarketStructureId, name: settings.preferredMarketStructureName ?? 'Unknown' }]
    }
  } catch {
    loadFailed.value = true
  } finally {
    loadingStructures.value = false
  }
}

async function saveMarketStructures(): Promise<void> {
  savingStructure.value = true
  structureSaveSuccess.value = false
  try {
    await apiRequest('/me/settings', {
      method: 'PATCH',
      body: JSON.stringify({ marketStructures: marketStructures.value }),
    })
    structureSaveSuccess.value = true
    setTimeout(() => { structureSaveSuccess.value = false }, 2000)
  } catch {
    await loadMarketSettings()
  } finally {
    savingStructure.value = false
  }
}

onMounted(loadMarketSettings)

onUnmounted(() => {
  clearTimeout(structureSearchTimeout)
})
</script>

<template>
  <div>
    <h4 class="text-sm font-medium text-slate-400 uppercase tracking-wider mb-2">{{ t('settings.marketStructure') }}</h4>
    <p class="text-xs text-slate-500 mb-3">{{ t('settings.marketStructureDesc') }}</p>
    <div v-if="loadingStructures" class="flex items-center gap-2 text-sm text-slate-500 py-2">
      <LoadingSpinner size="sm" class="text-cyan-500" />
      {{ t('settings.marketStructureLoading') }}
    </div>
    <div v-else>
      <p v-if="loadFailed" role="alert" class="text-xs text-red-300 mb-3">{{ t('settings.marketStructureLoadError') }}</p>
      <div v-if="marketStructures.length > 0" class="space-y-1.5 mb-3">
        <div
          v-for="struct in marketStructures"
          :key="struct.id"
          class="flex items-center justify-between bg-cyan-900/20 border border-cyan-700/50 rounded-lg px-3 py-2"
        >
          <div class="flex items-center gap-2 min-w-0">
            <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
            <span class="text-sm text-cyan-300 truncate">{{ struct.name }}</span>
          </div>
          <button
            @click="removeMarketStructure(struct.id)"
            class="p-1 text-slate-400 hover:text-red-400 hover:bg-slate-700 rounded-sm shrink-0 ml-2"
            :title="t('common.actions.delete')"
            :aria-label="t('common.actions.delete')"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <div class="relative">
        <input
          v-model="structureSearchQuery"
          type="text"
          :aria-label="t('industry.structures.searchStructure')"
          :placeholder="t('industry.structures.searchStructure')"
          @input="onStructureSearchInput"
          @focus="showStructureSearchDropdown = structureSearchResults.length > 0"
          @blur="hideStructureDropdownDelayed"
          class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 text-sm focus:outline-none focus:border-cyan-500/50 disabled:opacity-50"
          :disabled="savingStructure"
        />
        <div v-if="isSearchingStructures" class="absolute right-3 top-1/2 -translate-y-1/2">
          <LoadingSpinner size="sm" class="text-slate-400" />
        </div>

        <div
          v-if="showStructureSearchDropdown && structureSearchResults.length > 0"
          class="absolute z-10 mt-1 w-full bg-slate-800 border border-slate-600 rounded-lg shadow-lg max-h-48 overflow-y-auto"
        >
          <button
            v-for="result in structureSearchResults"
            :key="result.locationId"
            @mousedown.prevent="selectStructureSearchResult(result)"
            class="w-full px-3 py-2 hover:bg-slate-700 text-left"
          >
            <div class="text-sm text-slate-200">{{ result.locationName }}</div>
            <div v-if="result.solarSystemName" class="text-xs text-slate-500">{{ result.solarSystemName }}</div>
          </button>
        </div>
      </div>
    </div>
    <Transition name="fade">
      <p v-if="structureSaveSuccess" class="mt-2 text-xs text-emerald-400">
        {{ t('settings.marketStructureSaved') }}
      </p>
    </Transition>
  </div>
</template>
