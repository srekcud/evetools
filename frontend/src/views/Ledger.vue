<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore } from '@/stores/ledger'
import { usePveStore } from '@/stores/pve'
import MainLayout from '@/layouts/MainLayout.vue'
import PveTab from '@/components/ledger/PveTab.vue'
import LedgerDashboardTab from '@/components/ledger/LedgerDashboardTab.vue'
import MiningTab from '@/components/ledger/MiningTab.vue'
import LedgerSettingsTab from '@/components/ledger/LedgerSettingsTab.vue'

const { t } = useI18n()
const ledgerStore = useLedgerStore()
const pveStore = usePveStore()

// Tab state
const activeTab = ref<'dashboard' | 'pve' | 'mining' | 'settings'>('dashboard')

// Period selection (cached in localStorage)
const selectedDays = ref(parseInt(localStorage.getItem('ledgerSelectedDays') || '30'))
const periodOptions = computed(() => [
  { value: 7, label: t('ledger.period.nDays', { n: 7 }) },
  { value: 14, label: t('ledger.period.nDays', { n: 14 }) },
  { value: 30, label: t('ledger.period.nDays', { n: 30 }) },
  { value: 60, label: t('ledger.period.nDays', { n: 60 }) },
  { value: 90, label: t('ledger.period.nDays', { n: 90 }) },
])

const ytdDays = computed(() => {
  const now = new Date()
  const startOfYear = new Date(now.getFullYear(), 0, 1)
  return Math.ceil((now.getTime() - startOfYear.getTime()) / (1000 * 60 * 60 * 24))
})

// Loading states
const isLoading = computed(() => ledgerStore.isLoading || pveStore.isLoading)

// Dashboard data
const dashboard = computed(() => ledgerStore.dashboard)
const dailyStats = computed(() => ledgerStore.dailyStats)

// Settings
const settings = computed(() => ledgerStore.settings)

// Max daily value for chart scaling
const maxDailyValue = computed(() => {
  if (!dailyStats.value?.daily) return 0
  return Math.max(...dailyStats.value.daily.map(d => d.total))
})

// Fetch dashboard data
async function fetchData() {
  await Promise.all([
    ledgerStore.fetchDashboard(selectedDays.value),
    ledgerStore.fetchDailyStats(selectedDays.value),
    ledgerStore.fetchSettings(),
  ])
}

// Update settings
async function updateCorpProjectAccounting(key: string, value: string) {
  try {
    await ledgerStore.updateSettings({ [key]: value })
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
})

// Initialize
onMounted(async () => {
  await fetchData()
})
</script>

<template>
  <MainLayout>
    <div class="space-y-6">
      <!-- Header with period selector -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
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
              { id: 'dashboard', label: t('ledger.tabs.dashboard') },
              { id: 'pve', label: t('ledger.tabs.pve') },
              { id: 'mining', label: t('ledger.tabs.mining') },
              { id: 'settings', label: t('ledger.tabs.settings') },
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
      <LedgerDashboardTab
        v-else-if="activeTab === 'dashboard' && dashboard"
        :dashboard="dashboard"
        :daily-stats="dailyStats"
        :selected-days="selectedDays"
        :max-daily-value="maxDailyValue"
      />

      <!-- PVE Tab -->
      <div v-else-if="activeTab === 'pve'">
        <PveTab
          :selected-days="selectedDays"
          @sync="fetchData"
        />
      </div>

      <!-- Mining Tab -->
      <div v-else-if="activeTab === 'mining'">
        <MiningTab
          :selected-days="selectedDays"
          @sync="fetchData"
        />
      </div>

      <!-- Settings Tab -->
      <LedgerSettingsTab
        v-else-if="activeTab === 'settings'"
        :settings="settings"
        @update-setting="updateCorpProjectAccounting"
      />

      <!-- Error state -->
      <div v-if="ledgerStore.error" class="bg-red-500/10 border border-red-500/30 rounded-lg p-4">
        <p class="text-red-400">{{ ledgerStore.error }}</p>
        <button @click="ledgerStore.clearError()" class="mt-2 text-sm text-red-400 hover:text-red-300">
          {{ t('common.actions.close') }}
        </button>
      </div>
    </div>
  </MainLayout>
</template>
