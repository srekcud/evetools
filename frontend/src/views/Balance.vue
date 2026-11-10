<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore } from '@/stores/ledger'
import { useLedgerPeriod, daysSinceOldestEntry } from '@/composables/useLedgerPeriod'
import { useTabQuery } from '@/composables/useTabQuery'
import MainLayout from '@/layouts/MainLayout.vue'
import PatrimoineModuleTabs from '@/components/patrimoine/PatrimoineModuleTabs.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LedgerDashboardTab from '@/components/ledger/LedgerDashboardTab.vue'
import LedgerPeriodSelector from '@/components/ledger/LedgerPeriodSelector.vue'
import LedgerSettingsTab from '@/components/ledger/LedgerSettingsTab.vue'
import LedgerIndustryTab from '@/components/ledger/LedgerIndustryTab.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const ledgerStore = useLedgerStore()

type TabId = 'dashboard' | 'industry' | 'settings'
const validTabs: TabId[] = ['dashboard', 'industry', 'settings']
const activeTab = useTabQuery(validTabs, 'dashboard')

const { selectedDays, periodOptions, yearToDateDays } = useLedgerPeriod()

const isLoading = computed(() => ledgerStore.isLoading)

const dashboard = computed(() => ledgerStore.dashboard)
const allDays = computed(() => daysSinceOldestEntry(dashboard.value?.oldestEntryDate))
const dailyStats = computed(() => ledgerStore.dailyStats)

const settings = computed(() => ledgerStore.settings)

const maxDailyValue = computed(() => {
  if (!dailyStats.value?.daily) return 0
  const knownTotals = dailyStats.value.daily.map(d => d.total).filter((total): total is number => total !== null)
  return Math.max(...knownTotals)
})

async function fetchData() {
  await Promise.all([
    ledgerStore.fetchDashboard(selectedDays.value),
    ledgerStore.fetchDailyStats(selectedDays.value),
    ledgerStore.fetchSettings(),
  ])
}

async function updateCorpProjectAccounting(key: string, value: string) {
  try {
    await ledgerStore.updateSettings({ [key]: value })
  } catch (e) {
    console.error('Failed to update settings:', e)
  }
}

watch(selectedDays, async () => {
  ledgerStore.setSelectedDays(selectedDays.value)
  await fetchData()
})

onMounted(async () => {
  await fetchData()
})
</script>

<template>
  <MainLayout>
    <PatrimoineModuleTabs active="balance" />
    <div class="space-y-6">
      <LedgerPeriodSelector v-model="selectedDays" :options="periodOptions" :ytd-days="yearToDateDays" :all-days="allDays" />

      <!-- Tabs -->
      <div class="border-b border-slate-800">
        <nav class="flex gap-6">
          <button
            v-for="tab in [
              { id: 'dashboard', label: t('ledger.tabs.dashboard') },
              { id: 'industry', label: t('ledger.tabs.industry') },
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

      <div v-else-if="activeTab === 'industry'" key="industry">
        <LedgerIndustryTab :selected-days="selectedDays" />
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
