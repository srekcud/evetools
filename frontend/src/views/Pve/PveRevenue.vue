<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import PveTab from '@/components/ledger/PveTab.vue'
import LedgerPeriodSelector from '@/components/ledger/LedgerPeriodSelector.vue'
import PveModuleTabs from '@/components/pve-module/PveModuleTabs.vue'
import { daysSinceOldestEntry, useLedgerPeriod } from '@/composables/useLedgerPeriod'
import { useLedgerStore } from '@/stores/ledger'

const { t } = useI18n()
const ledgerStore = useLedgerStore()
const { selectedDays, periodOptions, yearToDateDays } = useLedgerPeriod()

const allDays = computed(() => daysSinceOldestEntry(ledgerStore.dashboard?.oldestEntryDate))

function reloadDashboard(): Promise<void> {
  return ledgerStore.fetchDashboard(selectedDays.value)
}

onMounted(reloadDashboard)
</script>

<template>
  <MainLayout>
    <div>
      <PveModuleTabs active="revenue" />

      <h1 class="sr-only">{{ t('pveModule.pages.revenue') }}</h1>

      <LedgerPeriodSelector v-model="selectedDays" :options="periodOptions" :ytd-days="yearToDateDays" :all-days="allDays" class="mb-6" />

      <PveTab :selected-days="selectedDays" @sync="reloadDashboard" />

      <ErrorBanner v-if="ledgerStore.error" class="mt-6" :message="ledgerStore.error" @dismiss="ledgerStore.clearError()" />
    </div>
  </MainLayout>
</template>
