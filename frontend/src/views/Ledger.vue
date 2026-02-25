<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useLedgerStore } from '@/stores/ledger'
import { usePveStore } from '@/stores/pve'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import PveTab from '@/components/ledger/PveTab.vue'
import LedgerDashboardTab from '@/components/ledger/LedgerDashboardTab.vue'
import MiningTab from '@/components/ledger/MiningTab.vue'
import LedgerSettingsTab from '@/components/ledger/LedgerSettingsTab.vue'
import EscalationsTab from '@/components/ledger/EscalationsTab.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const route = useRoute()
const ledgerStore = useLedgerStore()
const pveStore = usePveStore()

// Tab state
type TabId = 'dashboard' | 'pve' | 'mining' | 'settings' | 'escalations'
const validTabs: TabId[] = ['dashboard', 'pve', 'mining', 'settings', 'escalations']
const activeTab = ref<TabId>('dashboard')

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
  // Deep linking: read tab from query param
  const tabParam = route.query.tab as string | undefined
  if (tabParam && validTabs.includes(tabParam as TabId)) {
    activeTab.value = tabParam as TabId
  }
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
              { id: 'escalations', label: t('ledger.tabs.escalations') },
              { id: 'settings', label: t('ledger.tabs.settings') },
            ]"
            :key="tab.id"
            @click="activeTab = tab.id as TabId"
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
      <div v-if="isLoading && !dashboard" key="loading" class="flex justify-center py-12">
        <LoadingSpinner size="lg" class="text-cyan-500" />
      </div>

      <!-- Dashboard Tab -->
      <div v-else-if="activeTab === 'dashboard' && dashboard" key="dashboard">
        <LedgerDashboardTab
          :dashboard="dashboard"
          :daily-stats="dailyStats"
          :selected-days="selectedDays"
          :max-daily-value="maxDailyValue"
        />
      </div>

      <!-- PVE Tab -->
      <div v-else-if="activeTab === 'pve'" key="pve">
        <PveTab
          :selected-days="selectedDays"
          @sync="fetchData"
        />
      </div>

      <!-- Mining Tab -->
      <div v-else-if="activeTab === 'mining'" key="mining">
        <MiningTab
          :selected-days="selectedDays"
          @sync="fetchData"
        />
      </div>

      <!-- Escalations Tab -->
      <div v-else-if="activeTab === 'escalations'" key="escalations">
        <EscalationsTab />
      </div>

      <!-- Settings Tab -->
      <div v-else-if="activeTab === 'settings'" key="settings">
        <LedgerSettingsTab
          :settings="settings"
          @update-setting="updateCorpProjectAccounting"
        />
      </div>

      <!-- Error state -->
      <ErrorBanner v-if="ledgerStore.error" :message="ledgerStore.error" @dismiss="ledgerStore.clearError()" />
    </div>
  </MainLayout>
</template>
