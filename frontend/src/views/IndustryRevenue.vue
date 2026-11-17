<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import IndustryRevenueTab from '@/components/industry/IndustryRevenueTab.vue'
import LedgerPeriodSelector from '@/components/ledger/LedgerPeriodSelector.vue'
import { daysSinceOldestEntry, useLedgerPeriod } from '@/composables/useLedgerPeriod'
import { useLedgerStore } from '@/stores/ledger'

const { t } = useI18n()
const ledgerStore = useLedgerStore()
const { selectedDays, periodOptions, yearToDateDays } = useLedgerPeriod()

const allDays = computed(() => daysSinceOldestEntry(ledgerStore.dashboard?.oldestEntryDate))

onMounted(() => ledgerStore.fetchDashboard(selectedDays.value))
</script>

<template>
  <MainLayout>
    <div>
      <IndustryModuleTabs active="revenue" />

      <h1 class="sr-only">{{ t('industryModule.pages.revenue') }}</h1>

      <LedgerPeriodSelector v-model="selectedDays" :options="periodOptions" :ytd-days="yearToDateDays" :all-days="allDays" class="mb-6" />

      <IndustryRevenueTab :selected-days="selectedDays" />

      <ErrorBanner v-if="ledgerStore.error" class="mt-6" :message="ledgerStore.error" @dismiss="ledgerStore.clearError()" />
    </div>
  </MainLayout>
</template>
