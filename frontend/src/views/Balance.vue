<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore } from '@/stores/ledger'
import { useLedgerPeriod, daysSinceOldestEntry } from '@/composables/useLedgerPeriod'
import { useTabQuery } from '@/composables/useTabQuery'
import MainLayout from '@/layouts/MainLayout.vue'
import SubTabs from '@/components/common/SubTabs.vue'
import PatrimoineModuleTabs from '@/components/patrimoine/PatrimoineModuleTabs.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import BalanceRevenueGrid from '@/components/balance/BalanceRevenueGrid.vue'
import LedgerPeriodSelector from '@/components/ledger/LedgerPeriodSelector.vue'
import LedgerSettingsTab from '@/components/ledger/LedgerSettingsTab.vue'

const { t } = useI18n()
const ledgerStore = useLedgerStore()

type TabId = 'dashboard' | 'settings'
const validTabs: TabId[] = ['dashboard', 'settings']
const activeTab = useTabQuery(validTabs, 'dashboard')
const subTabs = computed(() => validTabs.map(tab => ({ id: tab, label: t(`ledger.tabs.${tab}`) })))

const { selectedDays, periodOptions, yearToDateDays } = useLedgerPeriod()

const dashboard = computed(() => ledgerStore.dashboard)
const allDays = computed(() => daysSinceOldestEntry(dashboard.value?.oldestEntryDate))

const settings = computed(() => ledgerStore.settings)

async function fetchData() {
  await Promise.all([
    ledgerStore.fetchDashboard(selectedDays.value),
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

      <SubTabs v-model="activeTab" :tabs="subTabs" />

      <BalanceRevenueGrid v-if="activeTab === 'dashboard'" :days="selectedDays" />

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
