<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore, type MiningEntry } from '@/stores/ledger'
import { useAuthStore } from '@/stores/auth'
import { useFormatters } from '@/composables/useFormatters'
import { useMiningPricing } from '@/composables/useMiningPricing'
import MiningStatsCards from './MiningStatsCards.vue'
import MiningEntriesTable from './MiningEntriesTable.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const props = defineProps<{
  selectedDays: number
}>()

const emit = defineEmits<{
  sync: []
}>()

const { t } = useI18n()
const ledgerStore = useLedgerStore()
const authStore = useAuthStore()
const { formatDateTime } = useFormatters()

// Structure selection for mining prices
interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
}

const savedStructure = localStorage.getItem('miningSelectedStructure')
const initialStructure = savedStructure ? JSON.parse(savedStructure) : { id: null, name: '' }
const selectedStructure = ref<{ id: number | null; name: string }>(initialStructure)
const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

watch(structureSearchQuery, (query) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (!query || query.length < 2) {
    structureSearchResults.value = []
    return
  }
  searchTimeout = setTimeout(() => searchStructures(query), 300)
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
  selectedStructure.value = { id: structure.id, name: structure.name }
  localStorage.setItem('miningSelectedStructure', JSON.stringify(selectedStructure.value))
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureDropdown.value = false
}

function clearStructure() {
  selectedStructure.value = { id: null, name: '' }
  localStorage.removeItem('miningSelectedStructure')
  structureSearchQuery.value = ''
  structureSearchResults.value = []
}

function onStructureInputBlur() {
  setTimeout(() => { showStructureDropdown.value = false }, 200)
}

const isSyncingStructure = ref(false)

async function syncStructureMarket() {
  if (!selectedStructure.value.id) return
  isSyncingStructure.value = true
  try {
    const response = await fetch('/api/shopping-list/sync-structure-market', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${authStore.token}`,
        'Accept': 'application/json',
      },
      body: JSON.stringify({ structureId: selectedStructure.value.id }),
    })
    if (!response.ok) throw new Error('Sync failed')
    await fetchMiningData()
  } catch (e) {
    console.error('Failed to sync structure market:', e)
  } finally {
    isSyncingStructure.value = false
  }
}

const selectedStructureId = computed(() => selectedStructure.value.id)

// Reprocessing yield
const reprocessYield = ref(parseFloat(localStorage.getItem('miningReprocessYield') || '78'))

function onYieldInput(event: Event) {
  const input = event.target as HTMLInputElement
  const value = parseFloat(input.value)
  if (!isNaN(value)) {
    reprocessYield.value = Math.max(0, Math.min(100, value))
    localStorage.setItem('miningReprocessYield', reprocessYield.value.toString())
  }
}

function onYieldBlur(event: Event) {
  const input = event.target as HTMLInputElement
  input.value = reprocessYield.value.toFixed(2)
}

// Export tax
const exportTax = ref(parseFloat(localStorage.getItem('miningExportTax') || '1200'))

function onExportTaxInput(event: Event) {
  const input = event.target as HTMLInputElement
  const value = parseFloat(input.value)
  if (!isNaN(value)) {
    exportTax.value = Math.max(0, value)
    localStorage.setItem('miningExportTax', exportTax.value.toString())
  }
}

function onExportTaxBlur(event: Event) {
  const input = event.target as HTMLInputElement
  input.value = exportTax.value.toFixed(0)
}

// Store data
const miningEntries = computed(() => ledgerStore.miningEntries)
const miningStats = computed(() => ledgerStore.miningStats)
const settings = computed(() => ledgerStore.settings)
const isSyncing = computed(() => ledgerStore.isSyncing)

// Pricing composable
const { miningByUsage } = useMiningPricing(miningEntries, reprocessYield)

// Derived stats
const activeDays = computed(() => {
  const uniqueDays = new Set(miningEntries.value.map(e => e.date.split('T')[0]))
  return uniqueDays.size
})

const iskPerActiveDay = computed(() => {
  if (activeDays.value === 0) return 0
  const backendTotal = miningStats.value?.totals.totalValue ?? 0
  return backendTotal / activeDays.value
})

const mainSystem = computed(() => {
  const systemCounts: Record<string, { name: string; value: number }> = {}
  for (const entry of miningEntries.value) {
    if (!systemCounts[entry.solarSystemId]) {
      systemCounts[entry.solarSystemId] = { name: entry.solarSystemName, value: 0 }
    }
    systemCounts[entry.solarSystemId].value += entry.totalValue || 0
  }

  let maxSystem = { name: '-', value: 0, percent: 0 }
  for (const sys of Object.values(systemCounts)) {
    if (sys.value > maxSystem.value) {
      maxSystem = { ...sys, percent: 0 }
    }
  }

  const backendTotal = miningStats.value?.totals.totalValue ?? 0
  if (backendTotal > 0) {
    maxSystem.percent = (maxSystem.value / backendTotal) * 100
  }

  return maxSystem
})

// Table ref for page reset
const tableRef = ref<InstanceType<typeof MiningEntriesTable> | null>(null)

// Fetch mining data
async function fetchMiningData() {
  await Promise.all([
    ledgerStore.fetchMiningEntries(props.selectedDays, undefined, undefined, selectedStructureId.value, reprocessYield.value, exportTax.value),
    ledgerStore.fetchMiningStats(props.selectedDays),
  ])
}

// Sync mining
async function syncMining() {
  try {
    await ledgerStore.syncMining()
    emit('sync')
  } catch (e) {
    console.error('Sync failed:', e)
  }
}

// Usage update
async function handleUpdateUsage(entryId: string, usage: MiningEntry['usage']) {
  try {
    await ledgerStore.updateMiningEntryUsage(entryId, usage)
  } catch (e) {
    console.error('Failed to update entry usage:', e)
  }
}

// Watch for structure changes
watch(selectedStructure, async () => {
  await fetchMiningData()
}, { deep: true })

// Watch for yield changes (debounced)
let yieldTimeout: ReturnType<typeof setTimeout> | null = null
watch(reprocessYield, () => {
  if (yieldTimeout) clearTimeout(yieldTimeout)
  yieldTimeout = setTimeout(() => fetchMiningData(), 500)
})

// Watch for export tax changes (debounced)
let exportTaxTimeout: ReturnType<typeof setTimeout> | null = null
watch(exportTax, () => {
  if (exportTaxTimeout) clearTimeout(exportTaxTimeout)
  exportTaxTimeout = setTimeout(() => fetchMiningData(), 500)
})

// Watch for days changes
watch(() => props.selectedDays, () => fetchMiningData())

// Reset page when entries change
watch(miningEntries, () => {
  tableRef.value?.resetPage()
})

// Initial fetch
fetchMiningData()

// Expose for parent
defineExpose({
  refresh: fetchMiningData,
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header with sync and structure selector -->
    <div class="flex items-center justify-between">
      <div>
        <p class="text-slate-400">{{ t('ledger.mining.subtitle') }}</p>
        <p v-if="settings?.lastMiningSyncAt" class="text-xs text-slate-500 mt-1">
          {{ t('ledger.mining.lastSync') }} {{ formatDateTime(settings.lastMiningSyncAt) }}
        </p>
        <p v-else class="text-xs text-amber-500 mt-1">
          {{ t('ledger.mining.noSync') }}
        </p>
      </div>
      <div class="flex items-center gap-3">
        <!-- Export tax input -->
        <div class="flex items-center gap-1">
          <span class="text-xs text-slate-500">{{ t('ledger.mining.exportTax') }} :</span>
          <input
            type="number"
            :value="exportTax.toFixed(0)"
            @change="onExportTaxInput"
            @blur="onExportTaxBlur"
            min="0"
            step="100"
            class="w-20 bg-slate-800/50 border border-slate-600 rounded-lg px-2 py-2 text-sm text-slate-200 text-right focus:outline-hidden focus:border-cyan-500/50"
            :title="t('ledger.mining.exportTaxTooltip')"
          />
          <span class="text-xs text-slate-500">ISK/m3</span>
        </div>

        <!-- Reprocess yield input -->
        <div class="flex items-center gap-1">
          <span class="text-xs text-slate-500">{{ t('ledger.mining.yield') }} :</span>
          <input
            type="number"
            :value="reprocessYield.toFixed(2)"
            @change="onYieldInput"
            @blur="onYieldBlur"
            min="0"
            max="100"
            step="0.01"
            class="w-20 bg-slate-800/50 border border-slate-600 rounded-lg px-2 py-2 text-sm text-slate-200 text-right focus:outline-hidden focus:border-cyan-500/50"
          />
          <span class="text-xs text-slate-500">%</span>
        </div>

        <!-- Structure selector -->
        <div class="flex items-center gap-2">
          <span class="text-xs text-slate-500">{{ t('ledger.mining.structureLabel') }} :</span>
          <div class="relative">
            <input
              v-model="structureSearchQuery"
              type="text"
              :placeholder="selectedStructure.id ? selectedStructure.name : 'C-J6MT - 1st Taj Mahgoon (defaut)'"
              @focus="showStructureDropdown = true"
              @blur="onStructureInputBlur"
              :class="[
                'w-80 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-hidden',
                selectedStructure.id
                  ? 'bg-slate-800/50 border-2 border-cyan-500/50 text-cyan-400 placeholder-cyan-400'
                  : 'bg-slate-800/50 border border-slate-600 text-slate-200 placeholder-slate-400 focus:border-cyan-500/50'
              ]"
            />
            <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center">
              <LoadingSpinner v-if="isSearchingStructures" size="sm" class="text-cyan-400" />
              <button
                v-else-if="selectedStructure.id || structureSearchQuery"
                @mousedown.prevent="clearStructure"
                class="text-slate-400 hover:text-slate-200"
                title="Revenir au defaut"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <!-- Structure search dropdown -->
            <div
              v-if="showStructureDropdown && structureSearchResults.length > 0"
              class="absolute z-50 top-full left-0 right-0 mt-1 bg-slate-800 border border-slate-600 rounded-lg shadow-xl max-h-60 overflow-y-auto"
            >
              <button
                v-for="structure in structureSearchResults"
                :key="structure.id"
                @mousedown.prevent="selectStructure(structure)"
                class="w-full px-3 py-2 text-left text-sm text-slate-200 hover:bg-slate-700/50 first:rounded-t-lg last:rounded-b-lg"
              >
                {{ structure.name }}
              </button>
            </div>
          </div>
          <!-- Sync structure button -->
          <button
            v-if="selectedStructure.id"
            @click="syncStructureMarket"
            :disabled="isSyncingStructure"
            class="px-2 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-300 text-sm disabled:opacity-50"
            title="Synchroniser les prix de la structure"
          >
            <svg :class="['w-4 h-4', isSyncingStructure ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
          </button>
        </div>
        <button
          @click="syncMining"
          :disabled="isSyncing"
          class="px-4 py-2 bg-amber-600 hover:bg-amber-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50"
        >
          <svg :class="['w-4 h-4', isSyncing ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ isSyncing ? t('common.actions.syncing') : t('common.actions.refresh') }}
        </button>
      </div>
    </div>

    <!-- Stats Cards -->
    <MiningStatsCards
      :mining-by-usage="miningByUsage"
      :isk-per-active-day="iskPerActiveDay"
      :active-days="activeDays"
      :mining-stats="miningStats"
      :main-system="mainSystem"
    />

    <!-- Entries Table -->
    <MiningEntriesTable
      ref="tableRef"
      :entries="miningEntries"
      :selected-structure-id="selectedStructureId"
      :reprocess-yield="reprocessYield"
      @update-usage="handleUpdateUsage"
    />
  </div>
</template>
