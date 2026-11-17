<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useLedgerStore } from '@/stores/ledger'
import { useLedgerPeriod, daysSinceOldestEntry } from '@/composables/useLedgerPeriod'
import MainLayout from '@/layouts/MainLayout.vue'
import PatrimoineModuleTabs from '@/components/patrimoine/PatrimoineModuleTabs.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LedgerDashboardTab from '@/components/ledger/LedgerDashboardTab.vue'
import LedgerPeriodSelector from '@/components/ledger/LedgerPeriodSelector.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const ledgerStore = useLedgerStore()

const { selectedDays, periodOptions, yearToDateDays } = useLedgerPeriod()

const isLoading = computed(() => ledgerStore.isLoading)

const dashboard = computed(() => ledgerStore.dashboard)
const allDays = computed(() => daysSinceOldestEntry(dashboard.value?.oldestEntryDate))
const dailyStats = computed(() => ledgerStore.dailyStats)


const maxDailyValue = computed(() => {
  if (!dailyStats.value?.daily) return 0
  const knownTotals = dailyStats.value.daily.map(d => d.total).filter((total): total is number => total !== null)
  return Math.max(...knownTotals)
})

async function fetchData() {
  await Promise.all([
    ledgerStore.fetchDashboard(selectedDays.value),
    ledgerStore.fetchDailyStats(selectedDays.value),
  ])
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

      <!-- Loading state -->
      <div v-if="isLoading && !dashboard" key="loading" class="flex justify-center py-12">
        <LoadingSpinner size="lg" class="text-cyan-500" />
      </div>

      <!-- Dashboard Tab -->
      <div v-else-if="dashboard" key="dashboard">
        <LedgerDashboardTab
          :dashboard="dashboard"
          :daily-stats="dailyStats"
          :selected-days="selectedDays"
          :max-daily-value="maxDailyValue"
        />
      </div>

      <!-- Error state -->
      <ErrorBanner v-if="ledgerStore.error" :message="ledgerStore.error" @dismiss="ledgerStore.clearError()" />
    </div>
  </MainLayout>
</template>
