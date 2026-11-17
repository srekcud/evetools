<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useLedgerStore } from '@/stores/ledger'
import { useLedgerPeriod, daysSinceOldestEntry } from '@/composables/useLedgerPeriod'
import MainLayout from '@/layouts/MainLayout.vue'
import PatrimoineModuleTabs from '@/components/patrimoine/PatrimoineModuleTabs.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import BalanceRevenueGrid from '@/components/balance/BalanceRevenueGrid.vue'
import LedgerPeriodSelector from '@/components/ledger/LedgerPeriodSelector.vue'

const ledgerStore = useLedgerStore()

const { selectedDays, periodOptions, yearToDateDays } = useLedgerPeriod()

const dashboard = computed(() => ledgerStore.dashboard)
const allDays = computed(() => daysSinceOldestEntry(dashboard.value?.oldestEntryDate))

async function fetchData() {
  await ledgerStore.fetchDashboard(selectedDays.value)
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

      <BalanceRevenueGrid :days="selectedDays" />

      <ErrorBanner v-if="ledgerStore.error" :message="ledgerStore.error" @dismiss="ledgerStore.clearError()" />
    </div>
  </MainLayout>
</template>
