<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useProfitTrackerStore } from '@/stores/profitTracker'
import { useSyncStore } from '@/stores/sync'
import ProfitKpiBar from '@/components/profit/ProfitKpiBar.vue'
import ProfitTable from '@/components/profit/ProfitTable.vue'
import ProfitItemDetail from '@/components/profit/ProfitItemDetail.vue'
import UnmatchedPanel from '@/components/profit/UnmatchedPanel.vue'
import ProfitTrendChart from '@/components/profit/ProfitTrendChart.vue'
import ProfitSettings from '@/components/profit/ProfitSettings.vue'
import type { SortField, FilterType, ProfitSettings as ProfitSettingsType } from '@/stores/profitTracker'

const { t } = useI18n()
const store = useProfitTrackerStore()
const syncStore = useSyncStore()

// Tab state
const activeTab = ref<'overview' | 'detail' | 'unmatched'>('overview')

// Period selection
const selectedDays = ref(parseInt(localStorage.getItem('profitTrackerDays') || '30'))
const periodOptions = [
  { value: 7, label: '7d' },
  { value: 30, label: '30d' },
  { value: 90, label: '90d' },
]

// Settings modal
const showSettings = ref(false)

// Loading state
const isLoading = computed(() => store.isLoading)
const isComputing = computed(() => store.isComputing)

// Mercure sync
const profitTrackerProgress = computed(() => syncStore.getSyncProgress('profit-tracker'))

// Unmatched count badge
const unmatchedCount = computed(() => store.unmatchedCount)

// Data fetch
async function fetchData(): Promise<void> {
  await store.fetchAll(selectedDays.value)
}

// Period change
function setPeriod(days: number): void {
  selectedDays.value = days
  localStorage.setItem('profitTrackerDays', days.toString())
  store.setSelectedDays(days)
}

// Sort handler
function handleSort(field: SortField): void {
  store.setSort(field)
  store.fetchItems(selectedDays.value)
}

// Filter handler
function handleFilter(filter: FilterType): void {
  store.setFilter(filter)
  store.fetchItems(selectedDays.value)
}

// Select item for detail
async function handleSelectItem(typeId: number): Promise<void> {
  await store.fetchItemDetail(typeId, selectedDays.value)
  activeTab.value = 'detail'
}

// Back from detail
function handleBackFromDetail(): void {
  store.clearItemDetail()
  activeTab.value = 'overview'
}

// Trigger compute
async function handleCompute(): Promise<void> {
  await store.triggerCompute(selectedDays.value)
}

// Save settings
async function handleSaveSettings(updates: Partial<ProfitSettingsType>): Promise<void> {
  try {
    await store.updateSettings(updates)
    showSettings.value = false
    // Refresh data after settings change
    await fetchData()
  } catch {
    // Error is handled in the store
  }
}

// Watch for period changes
watch(selectedDays, () => {
  fetchData()
})

// Watch Mercure sync progress
watch(profitTrackerProgress, (progress) => {
  if (progress?.status === 'completed') {
    fetchData()
    syncStore.clearSyncStatus('profit-tracker')
  }
})

// Initialize
onMounted(() => {
  fetchData()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
      <div class="flex items-center gap-3">
        <svg class="w-7 h-7 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2 10h2l2-4 3 8 2-4h2m5-4v16m-3-3l3 3 3-3M15 3l3 3 3-3"/>
        </svg>
        <div>
          <h1 class="text-xl font-semibold text-slate-100">{{ t('profitTracker.title') }}</h1>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <!-- Period selector -->
        <div class="flex items-center gap-1 bg-slate-900 rounded-lg p-1 border border-slate-800">
          <button
            v-for="option in periodOptions"
            :key="option.value"
            @click="setPeriod(option.value)"
            :class="[
              'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
              selectedDays === option.value
                ? 'bg-cyan-600 text-white'
                : 'text-slate-400 hover:text-white hover:bg-slate-700/50'
            ]"
          >
            {{ option.label }}
          </button>
        </div>

        <!-- Compute button -->
        <button
          @click="handleCompute"
          :disabled="isComputing"
          class="flex items-center gap-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-cyan-600/50 rounded-lg text-white text-sm font-medium transition-colors disabled:cursor-not-allowed"
        >
          <svg v-if="isComputing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ isComputing ? t('profitTracker.computing') : t('profitTracker.compute') }}
        </button>

        <!-- Settings gear -->
        <button
          @click="showSettings = true"
          class="p-2 bg-slate-800 hover:bg-slate-700 rounded-lg border border-slate-700 transition-colors"
          :title="t('profitTracker.settings.title')"
        >
          <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Mercure sync banner -->
    <div
      v-if="profitTrackerProgress && (profitTrackerProgress.status === 'started' || profitTrackerProgress.status === 'in_progress')"
      class="bg-cyan-500/10 border border-cyan-500/30 rounded-lg p-3 flex items-center gap-3"
    >
      <svg class="w-5 h-5 text-cyan-400 animate-spin shrink-0" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
      </svg>
      <span class="text-sm text-cyan-300">{{ profitTrackerProgress.message || t('profitTracker.computing') }}</span>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2">
      <button
        @click="activeTab = 'overview'"
        :class="[
          'px-5 py-2.5 rounded-lg text-sm font-medium transition-colors',
          activeTab === 'overview'
            ? 'bg-cyan-600 text-white'
            : 'bg-slate-800 text-slate-400 hover:text-slate-200'
        ]"
      >
        {{ t('profitTracker.tabs.overview') }}
      </button>
      <button
        @click="activeTab = 'detail'"
        :class="[
          'px-5 py-2.5 rounded-lg text-sm font-medium transition-colors',
          activeTab === 'detail'
            ? 'bg-cyan-600 text-white'
            : 'bg-slate-800 text-slate-400 hover:text-slate-200'
        ]"
      >
        {{ t('profitTracker.tabs.detail') }}
      </button>
      <button
        @click="activeTab = 'unmatched'"
        :class="[
          'px-5 py-2.5 rounded-lg text-sm font-medium transition-colors relative',
          activeTab === 'unmatched'
            ? 'bg-cyan-600 text-white'
            : 'bg-slate-800 text-slate-400 hover:text-slate-200'
        ]"
      >
        {{ t('profitTracker.tabs.unmatched') }}
        <span
          v-if="unmatchedCount > 0"
          class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-amber-500 rounded-full text-[10px] font-bold text-slate-900 flex items-center justify-center"
        >
          {{ unmatchedCount }}
        </span>
      </button>
    </div>

    <!-- Loading state -->
    <div v-if="isLoading && !store.hasData" class="flex justify-center py-12">
      <svg class="animate-spin h-8 w-8 text-cyan-500" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
      </svg>
    </div>

    <!-- Overview Tab -->
    <template v-else-if="activeTab === 'overview'">
      <!-- KPI Bar -->
      <ProfitKpiBar
        v-if="store.summary"
        :summary="store.summary"
        :days="selectedDays"
      />

      <!-- Profit Table -->
      <ProfitTable
        :items="store.filteredItems"
        :sort-by="store.sortBy"
        :sort-order="store.sortOrder"
        :filter="store.filter"
        @sort="handleSort"
        @filter="handleFilter"
        @select-item="handleSelectItem"
      />

      <!-- Profit Trend Chart -->
      <ProfitTrendChart
        v-if="store.profitTrendData.length > 0"
        :data="store.profitTrendData"
      />
    </template>

    <!-- Item Detail Tab -->
    <template v-else-if="activeTab === 'detail'">
      <ProfitItemDetail
        v-if="store.itemDetail"
        :detail="store.itemDetail"
        @back="handleBackFromDetail"
      />
      <div v-else class="bg-slate-900 rounded-xl border border-slate-800 p-12 text-center">
        <svg class="w-12 h-12 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/>
        </svg>
        <p class="text-sm text-slate-500">{{ t('profitTracker.detail.selectItem') }}</p>
        <button
          @click="activeTab = 'overview'"
          class="mt-3 text-sm text-cyan-400 hover:text-cyan-300 transition-colors"
        >
          {{ t('profitTracker.detail.back') }}
        </button>
      </div>
    </template>

    <!-- Unmatched Tab -->
    <template v-else-if="activeTab === 'unmatched'">
      <UnmatchedPanel
        v-if="store.unmatched"
        :data="store.unmatched"
      />
      <div v-else class="flex justify-center py-12">
        <svg class="animate-spin h-8 w-8 text-cyan-500" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
      </div>
    </template>

    <!-- Error state -->
    <div v-if="store.error" class="bg-red-500/10 border border-red-500/30 rounded-lg p-4">
      <p class="text-red-400">{{ store.error }}</p>
      <button @click="store.clearError()" class="mt-2 text-sm text-red-400 hover:text-red-300">
        {{ t('common.actions.close') }}
      </button>
    </div>

    <!-- Settings Modal -->
    <ProfitSettings
      :settings="store.settings"
      :show="showSettings"
      @close="showSettings = false"
      @save="handleSaveSettings"
    />
  </div>
</template>
