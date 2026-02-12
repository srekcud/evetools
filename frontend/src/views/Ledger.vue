<script setup lang="ts">
import { ref, computed, onMounted, watch, onUnmounted } from 'vue'
import { useLedgerStore, type MiningEntry } from '@/stores/ledger'
import { usePveStore } from '@/stores/pve'
import { useAuthStore } from '@/stores/auth'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import MainLayout from '@/layouts/MainLayout.vue'
import PveTab from '@/components/ledger/PveTab.vue'

const ledgerStore = useLedgerStore()
const pveStore = usePveStore()
const authStore = useAuthStore()
const { formatIskShort, formatIskFull, formatNumber, formatDate, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

// Structure selection for mining prices (like shopping-list)
interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
}

// Load structure from localStorage
const savedStructure = localStorage.getItem('miningSelectedStructure')
const initialStructure = savedStructure ? JSON.parse(savedStructure) : { id: null, name: '' }
const selectedStructure = ref<{ id: number | null; name: string }>(initialStructure)
const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Watch for structure search input
watch(structureSearchQuery, (query) => {
  if (searchTimeout) {
    clearTimeout(searchTimeout)
  }

  if (!query || query.length < 2) {
    structureSearchResults.value = []
    return
  }

  searchTimeout = setTimeout(() => {
    searchStructures(query)
  }, 300)
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
  // Delay to allow click on dropdown
  setTimeout(() => {
    showStructureDropdown.value = false
  }, 200)
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
      body: JSON.stringify({
        structureId: selectedStructure.value.id,
      }),
    })

    if (!response.ok) {
      throw new Error('Sync failed')
    }

    // Refresh mining data after sync
    await fetchMiningData()
  } catch (e) {
    console.error('Failed to sync structure market:', e)
  } finally {
    isSyncingStructure.value = false
  }
}

// Computed for backward compatibility
const selectedStructureId = computed(() => selectedStructure.value.id)

// Reprocessing yield percentage (stored as percentage, e.g., 78.45)
const reprocessYield = ref(parseFloat(localStorage.getItem('miningReprocessYield') || '78'))

// Export tax for Jita prices (ISK/m³)
const exportTax = ref(parseFloat(localStorage.getItem('miningExportTax') || '1200'))

function saveReprocessYield(value: number) {
  // Clamp between 0 and 100
  const clamped = Math.max(0, Math.min(100, value))
  reprocessYield.value = clamped
  localStorage.setItem('miningReprocessYield', clamped.toString())
}

function onYieldInput(event: Event) {
  const input = event.target as HTMLInputElement
  const value = parseFloat(input.value)
  if (!isNaN(value)) {
    saveReprocessYield(value)
  }
}

function onYieldBlur(event: Event) {
  const input = event.target as HTMLInputElement
  // Format to 2 decimal places on blur
  input.value = reprocessYield.value.toFixed(2)
}

function saveExportTax(value: number) {
  const clamped = Math.max(0, value)
  exportTax.value = clamped
  localStorage.setItem('miningExportTax', clamped.toString())
}

function onExportTaxInput(event: Event) {
  const input = event.target as HTMLInputElement
  const value = parseFloat(input.value)
  if (!isNaN(value)) {
    saveExportTax(value)
  }
}

function onExportTaxBlur(event: Event) {
  const input = event.target as HTMLInputElement
  input.value = exportTax.value.toFixed(0)
}

// PVE Tab ref
const pveTabRef = ref<InstanceType<typeof PveTab> | null>(null)

// Tab state
const activeTab = ref<'dashboard' | 'pve' | 'mining' | 'settings'>('dashboard')

// Contextual menu state for mining entries
const activeMenuEntryId = ref<string | null>(null)
const menuPosition = ref({ x: 0, y: 0 })

// Multi-select state
const selectedEntryIds = ref<Set<string>>(new Set())
const isMultiSelectMode = computed(() => selectedEntryIds.value.size > 0)

// Pagination
const currentPage = ref(1)
const itemsPerPage = 20

const totalPages = computed(() => Math.ceil(miningEntries.value.length / itemsPerPage))
const paginatedMiningEntries = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  return miningEntries.value.slice(start, start + itemsPerPage)
})

function goToPage(page: number) {
  if (page >= 1 && page <= totalPages.value) {
    currentPage.value = page
    // Clear selection when changing pages
    clearSelection()
  }
}

function toggleEntrySelection(entryId: string, event?: Event) {
  event?.stopPropagation()
  const newSet = new Set(selectedEntryIds.value)
  if (newSet.has(entryId)) {
    newSet.delete(entryId)
  } else {
    newSet.add(entryId)
  }
  selectedEntryIds.value = newSet
}

function selectAllEntries() {
  // Only select entries on current page
  const pageIds = paginatedMiningEntries.value.map(e => e.id)
  selectedEntryIds.value = new Set(pageIds)
}

function clearSelection() {
  selectedEntryIds.value = new Set()
  closeEntryMenu()
}

function openEntryMenu(entry: MiningEntry, event: MouseEvent) {
  event.preventDefault()
  event.stopPropagation()

  // If clicking on the currently active entry (menu already open), close it and deselect
  if (activeMenuEntryId.value === entry.id) {
    clearSelection()
    return
  }

  // If clicking on a non-selected entry while in multi-select mode, add it to selection
  if (isMultiSelectMode.value && !selectedEntryIds.value.has(entry.id)) {
    toggleEntrySelection(entry.id, event)
    return
  }

  // If not in multi-select mode, select this entry and open menu
  if (!isMultiSelectMode.value) {
    selectedEntryIds.value = new Set([entry.id])
  }

  // Position the menu near the click, ensuring it stays on screen
  const menuWidth = 224 // w-56 = 14rem = 224px
  const menuHeight = 220 // approximate menu height
  const padding = 8

  let x = event.clientX
  let y = event.clientY + padding

  // Ensure menu doesn't go off right edge
  if (x + menuWidth > window.innerWidth - padding) {
    x = window.innerWidth - menuWidth - padding
  }

  // Ensure menu doesn't go off left edge
  if (x < padding) {
    x = padding
  }

  // Ensure menu doesn't go off bottom edge
  if (y + menuHeight > window.innerHeight - padding) {
    y = event.clientY - menuHeight - padding
  }

  // Ensure menu doesn't go off top edge
  if (y < padding) {
    y = padding
  }

  menuPosition.value = { x, y }
  activeMenuEntryId.value = entry.id
}

function closeEntryMenu() {
  activeMenuEntryId.value = null
}

async function setEntryUsage(entryId: string, usage: MiningEntry['usage']) {
  try {
    await ledgerStore.updateMiningEntryUsage(entryId, usage)
    closeEntryMenu()
  } catch (e) {
    console.error('Failed to update entry usage:', e)
  }
}

async function setSelectedEntriesUsage(usage: MiningEntry['usage']) {
  try {
    const promises = Array.from(selectedEntryIds.value).map(id =>
      ledgerStore.updateMiningEntryUsage(id, usage)
    )
    await Promise.all(promises)
    clearSelection()
  } catch (e) {
    console.error('Failed to update entries usage:', e)
  }
}

function getUsageLabel(usage: MiningEntry['usage']): string {
  switch (usage) {
    case 'sold': return 'Personnel (vendu)'
    case 'corp_project': return 'Ope Corpo'
    case 'industry': return 'Projet Industrie'
    default: return 'Non assigne'
  }
}

function getUsageColor(usage: MiningEntry['usage']): string {
  switch (usage) {
    case 'sold': return 'text-emerald-400'
    case 'corp_project': return 'text-amber-400'
    case 'industry': return 'text-blue-400'
    default: return 'text-slate-500'
  }
}

// Close menu when clicking outside
function handleClickOutside(event: MouseEvent) {
  const target = event.target as HTMLElement
  if (!target.closest('.entry-menu') && !target.closest('.entry-row')) {
    closeEntryMenu()
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

// Period selection (cached in localStorage)
const selectedDays = ref(parseInt(localStorage.getItem('ledgerSelectedDays') || '30'))
const periodOptions = [
  { value: 7, label: '7 jours' },
  { value: 14, label: '14 jours' },
  { value: 30, label: '30 jours' },
  { value: 60, label: '60 jours' },
  { value: 90, label: '90 jours' },
]

// Calculate YTD days
const ytdDays = computed(() => {
  const now = new Date()
  const startOfYear = new Date(now.getFullYear(), 0, 1)
  const diffTime = now.getTime() - startOfYear.getTime()
  return Math.ceil(diffTime / (1000 * 60 * 60 * 24))
})

// Loading states
const isLoading = computed(() => ledgerStore.isLoading || pveStore.isLoading)
const isSyncing = computed(() => ledgerStore.isSyncing)

// Dashboard data
const dashboard = computed(() => ledgerStore.dashboard)
const dailyStats = computed(() => ledgerStore.dailyStats)

// Mining data
const miningEntries = computed(() => ledgerStore.miningEntries)
const miningStats = computed(() => ledgerStore.miningStats)


// Computed mining stats by usage (using best price)
const miningByUsage = computed(() => {
  const result = {
    sold: 0,
    corp_project: 0,
    industry: 0,
    unknown: 0,
    total: 0
  }
  for (const entry of miningEntries.value) {
    const bestPrice = getBestPriceValue(entry)
    const value = bestPrice * entry.quantity
    result[entry.usage] = (result[entry.usage] || 0) + value
    result.total += value
  }
  return result
})

// Helper to get just the best price value (for stats calculation)
function getBestPriceValue(entry: MiningEntry): number {
  const prices: number[] = []

  // Jita prices (compressed and reprocess only)
  if (entry.compressedEquivalentPrice) prices.push(entry.compressedEquivalentPrice)
  if (entry.reprocessValue) prices.push(entry.reprocessValue)

  // Structure prices (compressed and reprocess only)
  if (entry.structureCompressedUnitPrice) prices.push(entry.structureCompressedUnitPrice / 100)
  if (entry.structureReprocessValue) prices.push(entry.structureReprocessValue)

  // Fallback to totalValue / quantity if no other prices available
  if (prices.length === 0 && entry.totalValue && entry.quantity > 0) {
    return entry.totalValue / entry.quantity
  }

  return prices.length > 0 ? Math.max(...prices) : 0
}

// Active days calculation
const activeDays = computed(() => {
  const uniqueDays = new Set(miningEntries.value.map(e => e.date.split('T')[0]))
  return uniqueDays.size
})

// ISK per active day
const iskPerActiveDay = computed(() => {
  if (activeDays.value === 0) return 0
  return miningByUsage.value.total / activeDays.value
})

// Main system
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

  if (miningByUsage.value.total > 0) {
    maxSystem.percent = (maxSystem.value / miningByUsage.value.total) * 100
  }

  return maxSystem
})

// Settings
const settings = computed(() => ledgerStore.settings)

// Fetch data
async function fetchData() {
  await Promise.all([
    ledgerStore.fetchDashboard(selectedDays.value),
    ledgerStore.fetchDailyStats(selectedDays.value),
    ledgerStore.fetchSettings(),
  ])
}

async function fetchMiningData() {
  await Promise.all([
    ledgerStore.fetchMiningEntries(selectedDays.value, undefined, undefined, selectedStructureId.value, reprocessYield.value, exportTax.value),
    ledgerStore.fetchMiningStats(selectedDays.value),
  ])
}

// Sync
async function syncMining() {
  try {
    await ledgerStore.syncMining()
  } catch (e) {
    console.error('Sync failed:', e)
  }
}

// Update settings
async function updateCorpProjectAccounting(value: 'pve' | 'mining') {
  try {
    await ledgerStore.updateSettings({ corpProjectAccounting: value })
  } catch (e) {
    console.error('Failed to update settings:', e)
  }
}

// Watch for period changes
watch(selectedDays, async () => {
  localStorage.setItem('ledgerSelectedDays', selectedDays.value.toString())
  ledgerStore.setSelectedDays(selectedDays.value)
  pveStore.setSelectedDays(selectedDays.value)
  await fetchData()
  if (activeTab.value === 'mining') {
    await fetchMiningData()
  }
})

// Watch for tab changes
watch(activeTab, async (newTab) => {
  if (newTab === 'mining' && miningEntries.value.length === 0) {
    await fetchMiningData()
  }
})

// Reset page when entries change
watch(miningEntries, () => {
  currentPage.value = 1
})

// Watch for structure changes
watch(selectedStructure, async () => {
  if (activeTab.value === 'mining') {
    await fetchMiningData()
  }
}, { deep: true })

// Watch for yield changes (debounced)
let yieldTimeout: ReturnType<typeof setTimeout> | null = null
watch(reprocessYield, async () => {
  if (yieldTimeout) {
    clearTimeout(yieldTimeout)
  }
  yieldTimeout = setTimeout(async () => {
    if (activeTab.value === 'mining') {
      await fetchMiningData()
    }
  }, 500)
})

// Watch for export tax changes (debounced)
let exportTaxTimeout: ReturnType<typeof setTimeout> | null = null
watch(exportTax, async () => {
  if (exportTaxTimeout) {
    clearTimeout(exportTaxTimeout)
  }
  exportTaxTimeout = setTimeout(async () => {
    if (activeTab.value === 'mining') {
      await fetchMiningData()
    }
  }, 500)
})

// Initialize
onMounted(async () => {
  await fetchData()
})

// Format helpers
function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`
}

// Get best price for an entry (highest value per unit)
// Jita prices: cyan/purple (cool tones)
// Structure prices: teal/lime (warm tones)
function getBestPrice(entry: MiningEntry): { value: number; color: string; source: string } {
  const prices: { value: number; color: string; source: string }[] = []

  // Jita prices (cool tones) - no raw, only compressed and reprocess
  if (entry.compressedEquivalentPrice) {
    prices.push({ value: entry.compressedEquivalentPrice, color: 'text-cyan-400', source: 'Jita compresse' })
  }
  if (entry.reprocessValue) {
    prices.push({ value: entry.reprocessValue, color: 'text-purple-400', source: `Jita reprocess ${reprocessYield.value.toFixed(0)}%` })
  }

  // Structure prices (warm tones) - no raw, only compressed and reprocess
  if (entry.structureCompressedUnitPrice) {
    prices.push({ value: entry.structureCompressedUnitPrice / 100, color: 'text-teal-400', source: 'Structure compresse' })
  }
  if (entry.structureReprocessValue) {
    prices.push({ value: entry.structureReprocessValue, color: 'text-lime-400', source: `Structure reprocess ${reprocessYield.value.toFixed(0)}%` })
  }

  if (prices.length === 0) {
    return { value: 0, color: 'text-slate-600', source: '' }
  }

  // Return the highest price
  return prices.reduce((best, current) => current.value > best.value ? current : best)
}

// Calculate max value for chart scaling
const maxDailyValue = computed(() => {
  if (!dailyStats.value?.daily) return 0
  return Math.max(...dailyStats.value.daily.map(d => d.total))
})
</script>

<template>
  <MainLayout>
    <div class="space-y-6">
      <!-- Header with period selector and sync button -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <!-- Period selector -->
          <div class="flex items-center gap-2 bg-slate-900 rounded-lg p-1">
            <button
              v-for="option in periodOptions"
              :key="option.value"
              @click="selectedDays = option.value"
              :class="[
                'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
                selectedDays === option.value
                  ? 'bg-cyan-600 text-white'
                  : 'text-slate-400 hover:text-white hover:bg-slate-700/50'
              ]"
            >
              {{ option.label }}
            </button>
            <button
              @click="selectedDays = ytdDays"
              :class="[
                'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
                selectedDays === ytdDays
                  ? 'bg-cyan-600 text-white'
                  : 'text-slate-400 hover:text-white hover:bg-slate-700/50'
              ]"
            >
              YTD
            </button>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="border-b border-slate-800">
        <nav class="flex gap-6">
          <button
            v-for="tab in [
              { id: 'dashboard', label: 'Dashboard' },
              { id: 'pve', label: 'PVE' },
              { id: 'mining', label: 'Mining' },
              { id: 'settings', label: 'Parametres' },
            ]"
            :key="tab.id"
            @click="activeTab = tab.id as typeof activeTab"
            :class="[
              'pb-3 text-sm font-medium border-b-2 transition-colors',
              activeTab === tab.id
                ? 'border-cyan-500 text-cyan-400'
                : 'border-transparent text-slate-400 hover:text-slate-200'
            ]"
          >
            {{ tab.label }}
          </button>
        </nav>
      </div>

      <!-- Loading state -->
      <div v-if="isLoading && !dashboard" class="flex justify-center py-12">
        <svg class="animate-spin h-8 w-8 text-cyan-500" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
      </div>

      <!-- Dashboard Tab -->
      <div v-else-if="activeTab === 'dashboard' && dashboard" class="space-y-6">
        <!-- KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <!-- Total -->
          <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
            <div class="flex items-center justify-between">
              <span class="text-slate-400 text-sm">Total</span>
              <span class="text-xs text-slate-500">{{ selectedDays }}j</span>
            </div>
            <div class="mt-2 text-2xl font-bold text-white">
              {{ formatIskShort(dashboard.totals.total) }}
            </div>
            <div class="mt-2 flex items-center gap-2">
              <div class="flex-1 h-2 bg-slate-700 rounded-full overflow-hidden">
                <div
                  class="h-full bg-gradient-to-r from-cyan-500 to-blue-500"
                  :style="{ width: `${dashboard.pvePercent}%` }"
                />
              </div>
            </div>
            <div class="mt-1 flex justify-between text-xs text-slate-500">
              <span>PVE {{ formatPercent(dashboard.pvePercent) }}</span>
              <span>Mining {{ formatPercent(dashboard.miningPercent) }}</span>
            </div>
          </div>

          <!-- PVE -->
          <div class="bg-slate-900 rounded-xl p-6 border border-cyan-500/20">
            <div class="flex items-center justify-between">
              <span class="text-cyan-400 text-sm">PVE</span>
              <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
              </svg>
            </div>
            <div class="mt-2 text-2xl font-bold text-cyan-400">
              {{ formatIskShort(dashboard.totals.pve) }}
            </div>
            <div class="mt-2 text-xs text-slate-500">
              Bounties: {{ formatIskShort(dashboard.pveBreakdown.bounties) }}
            </div>
          </div>

          <!-- Mining -->
          <div class="bg-slate-900 rounded-xl p-6 border border-amber-500/20">
            <div class="flex items-center justify-between">
              <span class="text-amber-400 text-sm">Mining</span>
              <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
            </div>
            <div class="mt-2 text-2xl font-bold text-amber-400">
              {{ formatIskShort(dashboard.totals.mining) }}
            </div>
            <div class="mt-2 text-xs text-slate-500">
              ISK/jour: {{ formatIskShort(dashboard.iskPerDay) }}
            </div>
          </div>
        </div>

        <!-- Stacked Bar Chart -->
        <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
          <h3 class="text-lg font-semibold text-white mb-4">Revenus journaliers</h3>
          <div v-if="dailyStats?.daily" class="h-64 flex items-end gap-1">
            <div
              v-for="day in dailyStats.daily"
              :key="day.date"
              class="flex-1 flex flex-col justify-end gap-px"
              :title="`${formatDate(day.date)}\nPVE: ${formatIskFull(day.pve)}\nMining: ${formatIskFull(day.mining)}`"
            >
              <!-- Mining bar -->
              <div
                v-if="day.mining > 0"
                class="bg-amber-500/80 rounded-t-sm min-h-[2px]"
                :style="{ height: `${(day.mining / maxDailyValue) * 200}px` }"
              />
              <!-- PVE bar -->
              <div
                v-if="day.pve > 0"
                class="bg-cyan-500/80 min-h-[2px]"
                :class="{ 'rounded-t-sm': day.mining === 0 }"
                :style="{ height: `${(day.pve / maxDailyValue) * 200}px` }"
              />
            </div>
          </div>
          <div class="mt-4 flex items-center justify-center gap-6 text-sm">
            <div class="flex items-center gap-2">
              <div class="w-3 h-3 bg-cyan-500 rounded" />
              <span class="text-slate-400">PVE</span>
            </div>
            <div class="flex items-center gap-2">
              <div class="w-3 h-3 bg-amber-500 rounded" />
              <span class="text-slate-400">Mining</span>
            </div>
          </div>
        </div>

        <!-- Breakdown -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- PVE Breakdown -->
          <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
            <h3 class="text-lg font-semibold text-white mb-4">Repartition PVE</h3>
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Bounties</span>
                <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.bounties) }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">ESS</span>
                <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.ess) }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Missions</span>
                <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.missions) }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Loot</span>
                <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.lootSales) }}</span>
              </div>
              <div v-if="dashboard.settings.corpProjectAccounting === 'pve'" class="flex items-center justify-between">
                <span class="text-slate-400">Projets corpo</span>
                <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.corpProjects) }}</span>
              </div>
            </div>
          </div>

          <!-- Mining Breakdown -->
          <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
            <h3 class="text-lg font-semibold text-white mb-4">Repartition Mining</h3>
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Vendu</span>
                <span class="text-emerald-400 font-medium">{{ formatIskShort(dashboard.miningBreakdown.sold) }}</span>
              </div>
              <div v-if="dashboard.settings.corpProjectAccounting === 'mining'" class="flex items-center justify-between">
                <span class="text-slate-400">Ope corpo</span>
                <span class="text-amber-400 font-medium">{{ formatIskShort(dashboard.miningBreakdown.corpProject) }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Non assigne</span>
                <span class="text-slate-500 font-medium">{{ formatIskShort(dashboard.miningBreakdown.unknown) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Last sync info -->
        <div class="text-center text-xs text-slate-500">
          <span v-if="dashboard.lastSync.pve">PVE: {{ formatDateTime(dashboard.lastSync.pve) }}</span>
          <span v-if="dashboard.lastSync.pve && dashboard.lastSync.mining"> | </span>
          <span v-if="dashboard.lastSync.mining">Mining: {{ formatDateTime(dashboard.lastSync.mining) }}</span>
        </div>
      </div>

      <!-- PVE Tab -->
      <div v-else-if="activeTab === 'pve'">
        <PveTab
          ref="pveTabRef"
          :selected-days="selectedDays"
          @sync="fetchData"
        />
      </div>

      <!-- Mining Tab -->
      <div v-else-if="activeTab === 'mining'" class="space-y-6">
        <!-- Header with sync and structure selector -->
        <div class="flex items-center justify-between">
          <div>
            <p class="text-slate-400">Suivi du minage</p>
            <p v-if="settings?.lastMiningSyncAt" class="text-xs text-slate-500 mt-1">
              Derniere sync : {{ formatDateTime(settings.lastMiningSyncAt) }}
            </p>
            <p v-else class="text-xs text-amber-500 mt-1">
              Aucune synchronisation effectuee
            </p>
          </div>
          <div class="flex items-center gap-3">
            <!-- Export tax input -->
            <div class="flex items-center gap-1">
              <span class="text-xs text-slate-500">Export :</span>
              <input
                type="number"
                :value="exportTax.toFixed(0)"
                @change="onExportTaxInput"
                @blur="onExportTaxBlur"
                min="0"
                step="100"
                class="w-20 bg-slate-800/50 border border-slate-600 rounded-lg px-2 py-2 text-sm text-slate-200 text-right focus:outline-none focus:border-cyan-500/50"
                title="Taxe export Jita (ISK/m³)"
              />
              <span class="text-xs text-slate-500">ISK/m³</span>
            </div>

            <!-- Reprocess yield input -->
            <div class="flex items-center gap-1">
              <span class="text-xs text-slate-500">Yield :</span>
              <input
                type="number"
                :value="reprocessYield.toFixed(2)"
                @change="onYieldInput"
                @blur="onYieldBlur"
                min="0"
                max="100"
                step="0.01"
                class="w-20 bg-slate-800/50 border border-slate-600 rounded-lg px-2 py-2 text-sm text-slate-200 text-right focus:outline-none focus:border-cyan-500/50"
              />
              <span class="text-xs text-slate-500">%</span>
            </div>

            <!-- Structure selector (search-based like shopping-list) -->
            <div class="flex items-center gap-2">
              <span class="text-xs text-slate-500">Structure :</span>
              <div class="relative">
                <input
                  v-model="structureSearchQuery"
                  type="text"
                  :placeholder="selectedStructure.id ? selectedStructure.name : 'C-J6MT - 1st Taj Mahgoon (defaut)'"
                  @focus="showStructureDropdown = true"
                  @blur="onStructureInputBlur"
                  :class="[
                    'w-80 rounded-lg pl-3 pr-10 py-2 text-sm focus:outline-none',
                    selectedStructure.id
                      ? 'bg-slate-800/50 border-2 border-cyan-500/50 text-cyan-400 placeholder-cyan-400'
                      : 'bg-slate-800/50 border border-slate-600 text-slate-200 placeholder-slate-400 focus:border-cyan-500/50'
                  ]"
                />
                <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center">
                  <svg v-if="isSearchingStructures" class="w-4 h-4 animate-spin text-cyan-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
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
              {{ isSyncing ? 'Sync...' : 'Rafraichir' }}
            </button>
          </div>
        </div>

        <!-- Mining Stats - Row 1: Values -->
        <div class="grid grid-cols-3 gap-3">
          <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Valeur totale</span>
            <div class="mt-1 text-xl font-bold text-amber-400">{{ formatIskShort(miningByUsage.total) }}</div>
          </div>
          <div class="bg-slate-900 rounded-xl p-4 border border-emerald-500/20">
            <span class="text-emerald-400 text-xs uppercase tracking-wider">Personnel</span>
            <div class="mt-1 text-xl font-bold text-emerald-400">{{ formatIskShort(miningByUsage.sold) }}</div>
            <div class="text-xs text-slate-500">{{ miningByUsage.total > 0 ? ((miningByUsage.sold / miningByUsage.total) * 100).toFixed(0) : 0 }}%</div>
          </div>
          <div class="bg-slate-900 rounded-xl p-4 border border-amber-500/20">
            <span class="text-amber-400 text-xs uppercase tracking-wider">Corpo</span>
            <div class="mt-1 text-xl font-bold text-amber-400">{{ formatIskShort(miningByUsage.corp_project) }}</div>
            <div class="text-xs text-slate-500">{{ miningByUsage.total > 0 ? ((miningByUsage.corp_project / miningByUsage.total) * 100).toFixed(0) : 0 }}%</div>
          </div>
        </div>

        <!-- Mining Stats - Row 2: Activity -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
            <span class="text-slate-400 text-xs uppercase tracking-wider">ISK / jour actif</span>
            <div class="mt-1 text-xl font-bold text-cyan-400">{{ formatIskShort(iskPerActiveDay) }}</div>
            <div class="text-xs text-slate-500">{{ activeDays }} jours actifs</div>
          </div>
          <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Volume mine</span>
            <div class="mt-1 text-xl font-bold text-white">{{ formatNumber(miningStats?.totals.totalQuantity || 0) }}</div>
            <div class="text-xs text-slate-500">unites</div>
          </div>
          <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Systeme principal</span>
            <div class="mt-1 text-xl font-bold text-white truncate">{{ mainSystem.name }}</div>
            <div class="text-xs text-slate-500">{{ mainSystem.percent.toFixed(0) }}% du volume</div>
          </div>
          <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
            <span class="text-slate-400 text-xs uppercase tracking-wider">Non assigne</span>
            <div class="mt-1 text-xl font-bold text-slate-400">{{ formatIskShort(miningByUsage.unknown) }}</div>
            <div v-if="miningByUsage.unknown > 0" class="text-xs text-amber-400">A categoriser</div>
            <div v-else class="text-xs text-emerald-400">Tout assigne</div>
          </div>
        </div>

        <!-- Repartition bar -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-slate-400">Repartition du minerai</span>
            <span class="text-xs text-slate-500">{{ formatIskShort(miningByUsage.total) }} total</span>
          </div>
          <div class="h-3 flex rounded-full overflow-hidden bg-slate-700">
            <div
              v-if="miningByUsage.sold > 0"
              class="bg-emerald-500 h-full transition-all"
              :style="{ width: `${(miningByUsage.sold / miningByUsage.total) * 100}%` }"
              :title="`Personnel: ${formatIskShort(miningByUsage.sold)}`"
            />
            <div
              v-if="miningByUsage.corp_project > 0"
              class="bg-amber-500 h-full transition-all"
              :style="{ width: `${(miningByUsage.corp_project / miningByUsage.total) * 100}%` }"
              :title="`Corpo: ${formatIskShort(miningByUsage.corp_project)}`"
            />
            <div
              v-if="miningByUsage.unknown > 0"
              class="bg-slate-500 h-full transition-all"
              :style="{ width: `${(miningByUsage.unknown / miningByUsage.total) * 100}%` }"
              :title="`Non assigne: ${formatIskShort(miningByUsage.unknown)}`"
            />
          </div>
          <div class="flex flex-wrap justify-center gap-6 mt-2 text-xs">
            <div class="flex items-center gap-1">
              <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
              <span class="text-slate-400">Personnel</span>
            </div>
            <div class="flex items-center gap-1">
              <div class="w-2 h-2 bg-amber-500 rounded-full"></div>
              <span class="text-slate-400">Corpo</span>
            </div>
            <div class="flex items-center gap-1">
              <div class="w-2 h-2 bg-slate-500 rounded-full"></div>
              <span class="text-slate-400">Non assigne</span>
            </div>
          </div>
        </div>

        <!-- Multi-select action bar -->
        <div
          v-if="isMultiSelectMode"
          class="bg-slate-800 border border-cyan-500/30 rounded-xl p-4 flex items-center justify-between sticky top-0 z-10"
        >
          <div class="flex items-center gap-4">
            <span class="text-cyan-400 font-medium">{{ selectedEntryIds.size }} selectionne(s)</span>
            <button
              @click="selectAllEntries"
              class="text-sm text-slate-400 hover:text-white"
            >
              Tout selectionner
            </button>
            <button
              @click="clearSelection"
              class="text-sm text-slate-400 hover:text-white"
            >
              Annuler
            </button>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-sm text-slate-400 mr-2">Definir comme :</span>
            <button
              @click="setSelectedEntriesUsage('sold')"
              class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium"
            >
              Personnel
            </button>
            <button
              @click="setSelectedEntriesUsage('corp_project')"
              class="px-3 py-1.5 bg-amber-600 hover:bg-amber-500 rounded-lg text-white text-sm font-medium"
            >
              Corpo
            </button>
          </div>
        </div>

        <!-- Mining Entries Table -->
        <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden relative">
          <div class="px-6 py-4 border-b border-slate-800">
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center gap-3">
                <h3 class="text-lg font-semibold text-white">Entrees mining</h3>
                <span class="text-xs text-slate-500">({{ miningEntries.length }} entrees, page {{ currentPage }}/{{ totalPages }})</span>
              </div>
              <p class="text-xs text-slate-500">Cliquez sur une ligne pour changer son statut</p>
            </div>
            <!-- Legend -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
              <span class="text-slate-500">Meilleur prix :</span>
              <div class="flex items-center gap-1">
                <span class="text-cyan-400">●</span>
                <span class="text-slate-500">Jita comp.</span>
              </div>
              <div class="flex items-center gap-1">
                <span class="text-purple-400">●</span>
                <span class="text-slate-500">Jita reproc.</span>
              </div>
              <div class="flex items-center gap-1">
                <span class="text-teal-400">●</span>
                <span class="text-slate-500">Struct. comp.</span>
              </div>
              <div class="flex items-center gap-1">
                <span class="text-lime-400">●</span>
                <span class="text-slate-500">Struct. reproc.</span>
              </div>
            </div>
          </div>
          <!-- Table -->
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-900/50">
                <tr>
                  <th class="px-4 py-3 w-10">
                    <input
                      type="checkbox"
                      :checked="selectedEntryIds.size === paginatedMiningEntries.length && paginatedMiningEntries.length > 0"
                      :indeterminate="selectedEntryIds.size > 0 && selectedEntryIds.size < paginatedMiningEntries.length"
                      @change="selectedEntryIds.size === paginatedMiningEntries.length ? clearSelection() : selectAllEntries()"
                      class="w-4 h-4 rounded border-slate-600 bg-slate-700 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-slate-800"
                    />
                  </th>
                  <th class="px-3 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Date</th>
                  <th class="px-3 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Type</th>
                  <th class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider">Qte</th>
                  <th class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider" title="Prix equivalent compresse Jita (divise par 100)">Compress</th>
                  <th class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider" :title="`Valeur reprocess par unite (${reprocessYield.toFixed(2)}% yield)`">Reproc. {{ reprocessYield.toFixed(2) }}%</th>
                  <th v-if="selectedStructureId" class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider" title="Prix structure selectionnee">Structure</th>
                  <th class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider">Total</th>
                  <th class="px-3 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Statut</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-800">
                <tr
                  v-for="entry in paginatedMiningEntries"
                  :key="entry.id"
                  class="entry-row hover:bg-slate-900/30 cursor-pointer transition-colors"
                  :class="{
                    'bg-slate-900/50': activeMenuEntryId === entry.id,
                    'bg-cyan-900/20': selectedEntryIds.has(entry.id)
                  }"
                  @click="openEntryMenu(entry, $event)"
                >
                  <td class="px-4 py-3 w-10" @click.stop>
                    <input
                      type="checkbox"
                      :checked="selectedEntryIds.has(entry.id)"
                      @change="toggleEntrySelection(entry.id, $event)"
                      class="w-4 h-4 rounded border-slate-600 bg-slate-700 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-slate-800"
                    />
                  </td>
                  <td class="px-3 py-3 text-sm text-slate-300">{{ formatDate(entry.date) }}</td>
                  <td class="px-3 py-3">
                    <div class="flex items-center gap-2">
                      <img
                        :src="getTypeIconUrl(entry.typeId)"
                        :alt="entry.typeName"
                        class="w-5 h-5"
                        @error="onImageError"
                      />
                      <span class="text-sm text-white truncate max-w-[120px]" :title="entry.typeName">{{ entry.typeName }}</span>
                    </div>
                  </td>
                  <td class="px-3 py-3 text-sm text-right text-slate-300">{{ formatNumber(entry.quantity, 0) }}</td>
                  <td class="px-3 py-3 text-sm text-right">
                    <span v-if="entry.compressedEquivalentPrice" class="text-cyan-400" :title="`${entry.compressedTypeName}: ${formatNumber(entry.compressedUnitPrice || 0, 0)} ISK/u`">
                      {{ formatNumber(entry.compressedEquivalentPrice, 1) }}
                    </span>
                    <span v-else class="text-slate-600">-</span>
                  </td>
                  <td class="px-3 py-3 text-sm text-right">
                    <span v-if="entry.reprocessValue" class="text-purple-400" :title="`Valeur reprocess par unite (${reprocessYield.toFixed(2)}% yield)`">
                      {{ formatNumber(entry.reprocessValue, 2) }}
                    </span>
                    <span v-else class="text-slate-600">-</span>
                  </td>
                  <td v-if="selectedStructureId" class="px-3 py-3 text-sm text-right">
                    <span v-if="entry.structureCompressedUnitPrice" class="text-emerald-400" :title="`Compresse: ${formatNumber(entry.structureCompressedUnitPrice, 0)} ISK/u`">
                      {{ formatNumber(entry.structureCompressedUnitPrice / 100, 1) }}
                    </span>
                    <span v-else-if="entry.structureUnitPrice" class="text-slate-400">
                      {{ formatNumber(entry.structureUnitPrice, 0) }}
                    </span>
                    <span v-else class="text-slate-600">-</span>
                  </td>
                  <td class="px-3 py-3 text-sm text-right">
                    <span
                      v-if="getBestPrice(entry).value > 0"
                      :class="getBestPrice(entry).color"
                      class="font-medium"
                      :title="getBestPrice(entry).source"
                    >
                      {{ formatIskShort(getBestPrice(entry).value * entry.quantity) }}
                    </span>
                    <span v-else class="text-slate-600">-</span>
                  </td>
                  <td class="px-3 py-3 text-sm">
                    <span :class="getUsageColor(entry.usage)" class="text-xs font-medium">
                      {{ getUsageLabel(entry.usage) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="miningEntries.length === 0" class="p-8 text-center text-slate-500">
            Aucune donnee de minage. Cliquez sur "Rafraichir" pour importer vos donnees.
          </div>

          <!-- Pagination -->
          <div v-if="totalPages > 1" class="px-6 py-4 border-t border-slate-800 flex items-center justify-between">
            <div class="text-sm text-slate-400">
              {{ (currentPage - 1) * itemsPerPage + 1 }}-{{ Math.min(currentPage * itemsPerPage, miningEntries.length) }} sur {{ miningEntries.length }}
            </div>
            <div class="flex items-center gap-1">
              <button
                @click="goToPage(1)"
                :disabled="currentPage === 1"
                class="px-2 py-1 text-sm rounded hover:bg-slate-700/50 disabled:opacity-30 disabled:cursor-not-allowed text-slate-400"
              >
                &lt;&lt;
              </button>
              <button
                @click="goToPage(currentPage - 1)"
                :disabled="currentPage === 1"
                class="px-2 py-1 text-sm rounded hover:bg-slate-700/50 disabled:opacity-30 disabled:cursor-not-allowed text-slate-400"
              >
                &lt;
              </button>
              <template v-for="page in totalPages" :key="page">
                <button
                  v-if="page === 1 || page === totalPages || (page >= currentPage - 1 && page <= currentPage + 1)"
                  @click="goToPage(page)"
                  :class="[
                    'px-3 py-1 text-sm rounded transition-colors',
                    page === currentPage
                      ? 'bg-cyan-600 text-white'
                      : 'text-slate-400 hover:bg-slate-700/50'
                  ]"
                >
                  {{ page }}
                </button>
                <span
                  v-else-if="page === currentPage - 2 || page === currentPage + 2"
                  class="px-1 text-slate-500"
                >...</span>
              </template>
              <button
                @click="goToPage(currentPage + 1)"
                :disabled="currentPage === totalPages"
                class="px-2 py-1 text-sm rounded hover:bg-slate-700/50 disabled:opacity-30 disabled:cursor-not-allowed text-slate-400"
              >
                &gt;
              </button>
              <button
                @click="goToPage(totalPages)"
                :disabled="currentPage === totalPages"
                class="px-2 py-1 text-sm rounded hover:bg-slate-700/50 disabled:opacity-30 disabled:cursor-not-allowed text-slate-400"
              >
                &gt;&gt;
              </button>
            </div>
          </div>

          <!-- Contextual Menu -->
          <Teleport to="body">
            <div
              v-if="activeMenuEntryId && !isMultiSelectMode"
              class="entry-menu fixed z-50 w-56 bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden"
              :style="{ left: `${menuPosition.x}px`, top: `${menuPosition.y}px` }"
            >
              <div class="p-2 border-b border-slate-800">
                <p class="text-xs text-slate-500 px-2">Changer le statut</p>
              </div>
              <div class="p-1">
                <button
                  @click="setEntryUsage(activeMenuEntryId!, 'sold')"
                  class="w-full px-3 py-2 text-left text-sm rounded-md hover:bg-slate-700/50 flex items-center gap-3 transition-colors"
                >
                  <span class="w-2 h-2 bg-emerald-400 rounded-full"></span>
                  <span class="text-white">Personnel (vendu)</span>
                </button>
                <button
                  @click="setEntryUsage(activeMenuEntryId!, 'corp_project')"
                  class="w-full px-3 py-2 text-left text-sm rounded-md hover:bg-slate-700/50 flex items-center gap-3 transition-colors"
                >
                  <span class="w-2 h-2 bg-amber-400 rounded-full"></span>
                  <span class="text-white">Ope Corpo</span>
                </button>
                <button
                  @click="setEntryUsage(activeMenuEntryId!, 'unknown')"
                  class="w-full px-3 py-2 text-left text-sm rounded-md hover:bg-slate-700/50 flex items-center gap-3 transition-colors"
                >
                  <span class="w-2 h-2 bg-slate-500 rounded-full"></span>
                  <span class="text-white">Non assigne</span>
                </button>
              </div>
            </div>
          </Teleport>
        </div>
      </div>

      <!-- Settings Tab -->
      <div v-else-if="activeTab === 'settings'" class="space-y-6">
        <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
          <h3 class="text-lg font-semibold text-white mb-4">Comptabilisation des projets corporation</h3>
          <p class="text-slate-400 text-sm mb-4">
            Choisissez comment comptabiliser les contributions aux projets corporation pour eviter le double comptage.
          </p>

          <div class="space-y-3">
            <label
              class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-colors"
              :class="settings?.corpProjectAccounting === 'pve' ? 'bg-cyan-500/10 border-cyan-500/30' : 'border-slate-700 hover:border-slate-600'"
            >
              <input
                type="radio"
                name="corpProjectAccounting"
                value="pve"
                :checked="settings?.corpProjectAccounting === 'pve'"
                @change="updateCorpProjectAccounting('pve')"
                class="mt-1"
              />
              <div>
                <div class="text-white font-medium">Cote PVE (ISK recus)</div>
                <div class="text-sm text-slate-400 mt-1">
                  Les revenus des projets corporation apparaissent dans le module PVE.
                  Le minerai correspondant est exclu des stats Mining.
                </div>
                <div class="text-xs text-cyan-400 mt-2">Recommande</div>
              </div>
            </label>

            <label
              class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-colors"
              :class="settings?.corpProjectAccounting === 'mining' ? 'bg-amber-500/10 border-amber-500/30' : 'border-slate-700 hover:border-slate-600'"
            >
              <input
                type="radio"
                name="corpProjectAccounting"
                value="mining"
                :checked="settings?.corpProjectAccounting === 'mining'"
                @change="updateCorpProjectAccounting('mining')"
                class="mt-1"
              />
              <div>
                <div class="text-white font-medium">Cote Mining (valeur minerai)</div>
                <div class="text-sm text-slate-400 mt-1">
                  Le minerai contribue est valorise dans le module Mining.
                  Les revenus ISK des projets corporation sont exclus du PVE.
                </div>
              </div>
            </label>
          </div>

          <div class="mt-4 p-3 bg-slate-900/50 rounded-lg">
            <div class="flex items-center gap-2 text-xs text-slate-500">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span>Les minerais issus du reprocessing de salvage ne sont pas dans le Mining Ledger ESI, donc toujours comptes en PVE.</span>
            </div>
          </div>
        </div>

      </div>

      <!-- Error state -->
      <div v-if="ledgerStore.error" class="bg-red-500/10 border border-red-500/30 rounded-lg p-4">
        <p class="text-red-400">{{ ledgerStore.error }}</p>
        <button @click="ledgerStore.clearError()" class="mt-2 text-sm text-red-400 hover:text-red-300">
          Fermer
        </button>
      </div>
    </div>
  </MainLayout>
</template>
