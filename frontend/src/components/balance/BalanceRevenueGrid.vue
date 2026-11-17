<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore } from '@/stores/ledger'
import { usePlanetaryStore } from '@/stores/planetary'
import { usePveStore } from '@/stores/pve'
import type { RevenueTodoItem } from '@/types/revenueGrid'
import { PLANETARY_MAX_DAYS, buildBalanceRevenue, type BalanceLabels, type BalanceTodoKind } from '@/utils/balanceRevenueGrid'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import RevenueLineGrid from '@/components/revenue/RevenueLineGrid.vue'
import RevenueTodoPanel from '@/components/revenue/RevenueTodoPanel.vue'

const props = defineProps<{
  days: number
}>()

const { t } = useI18n()
const ledgerStore = useLedgerStore()
const pveStore = usePveStore()
const planetaryStore = usePlanetaryStore()

const isLoaded = ref(false)

const TODO_DESTINATIONS: Record<BalanceTodoKind, RevenueTodoItem['to']> = {
  missingPrice: { name: 'mining-my-mining' },
  uncategorised: { name: 'mining-my-mining' },
  unknownProfit: { name: 'industry-projects' },
  piWithoutSale: { name: 'planetary', query: { tab: 'revenue' } },
}

const labels = computed<BalanceLabels>(() => ({
  columns: {
    pve: t('revenue.balance.columns.pve'),
    mining: t('revenue.balance.columns.mining'),
    industry: t('revenue.balance.columns.industry'),
    planetary: t('revenue.balance.columns.planetary'),
    unknown: t('revenue.balance.columns.unknown'),
  },
  planetaryEstimate: t('revenue.balance.columns.planetaryEstimate'),
  accountProjects: t('revenue.balance.accountProjects'),
  pveColumns: {
    bounties: t('revenue.pve.columns.bounties'),
    ess: t('revenue.pve.columns.ess'),
    missions: t('revenue.pve.columns.missions'),
    lootSales: t('revenue.pve.columns.lootSales'),
    escalations: t('revenue.pve.columns.escalations'),
    expenses: t('revenue.pve.columns.expenses'),
  },
  miningColumns: {
    sold: t('revenue.mining.columns.sold'),
    corp_project: t('revenue.mining.columns.corpProject'),
    unknown: t('revenue.mining.columns.uncategorised'),
  },
  industry: {
    profit: t('revenue.industry.columns.profit'),
    unknownProfit: t('revenue.industry.columns.unknownProfit'),
    multiProduct: t('ledger.industry.multiProduct'),
  },
}))

const revenue = computed(() =>
  buildBalanceRevenue(
    { pve: pveStore.grid, mining: ledgerStore.miningGrid, industry: ledgerStore.industry, planetary: planetaryStore.revenue },
    labels.value,
  ),
)

const todoItems = computed<RevenueTodoItem[]>(() =>
  revenue.value.todos.map(todo => ({
    text: t(`revenue.balance.todo.${todo.kind}.text`, { count: todo.count }, todo.count),
    effect: t(`revenue.balance.todo.${todo.kind}.effect`),
    actionLabel: t(`revenue.balance.todo.${todo.kind}.action`),
    to: TODO_DESTINATIONS[todo.kind],
  })),
)

async function load(): Promise<void> {
  await Promise.all([
    pveStore.fetchGrid(props.days),
    ledgerStore.fetchMiningGrid(props.days),
    ledgerStore.fetchIndustry(props.days),
    planetaryStore.fetchRevenue(Math.min(props.days, PLANETARY_MAX_DAYS)),
  ])
  isLoaded.value = true
}

watch(() => props.days, load)
onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <RevenueLineGrid
      v-if="isLoaded && revenue.period"
      :title="t('revenue.balance.title')"
      :row-label="t('revenue.balance.rowLabel')"
      :columns="revenue.columns"
      :net="revenue.net"
      :period="revenue.period"
    />
    <p v-if="isLoaded" class="font-mono text-xs text-slate-500">{{ t('revenue.balance.hint') }}</p>
    <LoadingSpinner v-else />
    <RevenueTodoPanel :items="todoItems" />
  </div>
</template>
