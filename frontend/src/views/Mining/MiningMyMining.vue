<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import MiningTab from '@/components/ledger/MiningTab.vue'
import MiningModuleTabs from '@/components/mining-module/MiningModuleTabs.vue'
import MiningSubTabs from '@/components/mining-module/MiningSubTabs.vue'
import { useLedgerPeriod } from '@/composables/useLedgerPeriod'
import { useTabQuery } from '@/composables/useTabQuery'
import { useLedgerStore } from '@/stores/ledger'
import MiningSeasonPanel from '@/views/MiningOperation/MiningSeasonPanel.vue'

type MyMiningTab = 'ledger' | 'season'

const MY_MINING_TABS = ['ledger', 'season'] as const satisfies readonly MyMiningTab[]

const activeTab = useTabQuery<MyMiningTab>(MY_MINING_TABS, 'ledger')
const { t } = useI18n()
const ledgerStore = useLedgerStore()
const { selectedDays, periodOptions, yearToDateDays } = useLedgerPeriod()

const subTabs = computed(() => MY_MINING_TABS.map(tab => ({ id: tab, label: t(`miningModule.myMining.tabs.${tab}`) })))

function periodButtonClass(selected: boolean): string[] {
  return [
    'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
    selected ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50',
  ]
}

onMounted(() => ledgerStore.fetchSettings())
</script>

<template>
  <MainLayout>
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
      <MiningModuleTabs active="my-mining" />

      <h1 class="sr-only">{{ t('miningModule.pages.myMining') }}</h1>

      <MiningSubTabs v-model="activeTab" :tabs="subTabs" />

      <template v-if="activeTab === 'ledger'">
        <div class="mb-6 flex flex-wrap items-center gap-2 rounded-lg bg-slate-900 p-1 w-fit max-w-full" role="group" :aria-label="t('miningModule.myMining.period')">
          <button
            v-for="option in periodOptions"
            :key="option.value"
            type="button"
            :class="periodButtonClass(selectedDays === option.value)"
            @click="selectedDays = option.value"
          >{{ option.label }}</button>
          <button
            type="button"
            :class="periodButtonClass(selectedDays === yearToDateDays)"
            @click="selectedDays = yearToDateDays"
          >YTD</button>
        </div>

        <MiningTab :selected-days="selectedDays" @sync="ledgerStore.fetchSettings()" />
      </template>

      <MiningSeasonPanel v-else />
    </div>
  </MainLayout>
</template>
