<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore, type MiningEntry } from '@/stores/ledger'
import { useFormatters } from '@/composables/useFormatters'
import { useMiningPricing } from '@/composables/useMiningPricing'
import { useMiningValuationSettings } from '@/composables/useMiningValuationSettings'
import MiningStatsCards from './MiningStatsCards.vue'
import MiningEntriesTable from './MiningEntriesTable.vue'

const props = defineProps<{
  selectedDays: number
}>()

const emit = defineEmits<{
  sync: []
}>()

const { t } = useI18n()
const ledgerStore = useLedgerStore()
const { formatDateTime } = useFormatters()

const { selectedStructureId } = useMiningValuationSettings()

// Store data
const miningEntries = computed(() => ledgerStore.miningEntries)
const miningStats = computed(() => ledgerStore.miningStats)
const settings = computed(() => ledgerStore.settings)
const isSyncing = computed(() => ledgerStore.isSyncing)

// Pricing composable
const { miningByUsage } = useMiningPricing(miningEntries)

// Derived stats
const activeDays = computed(() => {
  const uniqueDays = new Set(miningEntries.value.map(e => e.date.split('T')[0]))
  return uniqueDays.size
})

const iskPerActiveDay = computed((): number | null => {
  if (miningStats.value === null) return null
  const totalValue = miningStats.value.totals.totalValue
  if (totalValue === null) return null
  if (activeDays.value === 0) return 0
  return totalValue / activeDays.value
})

const missingPriceTypeLabels = computed((): string[] => {
  if (miningStats.value === null) return []
  const typeNamesById = new Map(miningEntries.value.map(entry => [entry.typeId, entry.typeName]))
  return miningStats.value.missingPriceTypeIds.map(typeId => typeNamesById.get(typeId) ?? `#${typeId}`)
})

const mainSystem = computed(() => {
  const systemCounts: Record<string, { name: string; value: number }> = {}
  for (const entry of miningEntries.value) {
    if (!systemCounts[entry.solarSystemId]) {
      systemCounts[entry.solarSystemId] = { name: entry.solarSystemName, value: 0 }
    }
    systemCounts[entry.solarSystemId].value += entry.totalValue || 0
  }

  let maxSystem = { name: '-', value: 0 }
  for (const sys of Object.values(systemCounts)) {
    if (sys.value > maxSystem.value) {
      maxSystem = { ...sys }
    }
  }

  if (miningStats.value === null) return { ...maxSystem, percent: null }
  const backendTotal = miningStats.value.totals.totalValue
  if (backendTotal === null) return { ...maxSystem, percent: null }
  return { ...maxSystem, percent: backendTotal > 0 ? (maxSystem.value / backendTotal) * 100 : 0 }
})

// Table ref for page reset
const tableRef = ref<InstanceType<typeof MiningEntriesTable> | null>(null)

// Fetch mining data
async function fetchMiningData() {
  await Promise.all([
    ledgerStore.fetchMiningEntries(props.selectedDays),
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
      :loading="ledgerStore.isLoading"
      :main-system="mainSystem"
      :missing-price-type-labels="missingPriceTypeLabels"
    />

    <!-- Entries Table -->
    <MiningEntriesTable
      ref="tableRef"
      :entries="miningEntries"
      :selected-structure-id="selectedStructureId"
      @update-usage="handleUpdateUsage"
    />
  </div>
</template>
