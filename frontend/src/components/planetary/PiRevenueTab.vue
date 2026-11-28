<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatIskShort } from '@/composables/useIskParser'
import { usePlanetaryStore } from '@/stores/planetary'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import PiRevenuePlanLine from '@/components/planetary/PiRevenuePlanLine.vue'
import RevenueLineGrid from '@/components/revenue/RevenueLineGrid.vue'
import RevenueTodoPanel from '@/components/revenue/RevenueTodoPanel.vue'
import ProjectSellOrdersCard from '@/components/group-industry/ProjectSellOrdersCard.vue'
import PiProductionProfitGrid from './PiProductionProfitGrid.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import type { RevenueTodoItem } from '@/types/revenueGrid'
import {
  ESTIMATE_NET,
  buildEstimateColumn,
  buildRealisedColumns,
  buildRealisedNet,
  groupColoniesWithoutSale,
  type PlanetaryRevenueLabels,
} from '@/utils/planetaryRevenue'

const PERIOD_OPTIONS = [7, 30, 90] as const
const DEFAULT_PERIOD_DAYS = 30

const emit = defineEmits<{ 'open-planner': [] }>()
const { t } = useI18n()
const planetaryStore = usePlanetaryStore()
const plannerStore = usePlanetaryPlannerStore()

const selectedDays = ref<number>(DEFAULT_PERIOD_DAYS)
const revenue = computed(() => planetaryStore.revenue)
const now = ref(Date.now())
const productionProfit = computed(() => planetaryStore.productionProfit)

const estimateProfit = computed(() => {
  const profit = productionProfit.value
  if (profit === null) return null

  const total = profit.profitPerDay * period.value.days
  return {
    amount: total < 0 ? `-${formatIskShort(-total)}` : formatIskShort(total),
    purchase: t(`pi.revenue.profit.source.${profit.sources.purchase}`),
    sale: t(`pi.revenue.profit.source.${profit.sources.sale}`),
    unknownLineCount: profit.unknownLineCount,
  }
})

const labels = computed<PlanetaryRevenueLabels>(() => ({
  tier: tier => t(`pi.revenue.tiers.${tier}`),
  offMarket: t('pi.revenue.offMarket'),
  walletUnsynced: t('pi.revenue.walletUnsynced'),
  estimate: t('pi.revenue.estimateColumn'),
}))

const period = computed(() => ({ to: revenue.value?.periodEnd ?? '', days: revenue.value?.days ?? selectedDays.value }))
const realisedColumns = computed(() => (revenue.value ? buildRealisedColumns(revenue.value, labels.value) : []))
const estimateColumns = computed(() => (revenue.value ? [buildEstimateColumn(revenue.value, labels.value)] : []))
const realisedNet = computed(() => (revenue.value ? buildRealisedNet(revenue.value) : ESTIMATE_NET))

const walletTodoItems = computed<RevenueTodoItem[]>(() =>
  (revenue.value?.unsyncedWallets ?? []).map(wallet => ({
    text: t('pi.revenue.walletTodo.text', { character: wallet.characterName, reason: t(`pi.revenue.walletTodo.status.${wallet.status}`) }),
    effect: t('pi.revenue.walletTodo.effect'),
    actionLabel: t('pi.revenue.walletTodo.action'),
    to: { name: 'characters' },
  })),
)

const unsoldColoniesTodoItems = computed<RevenueTodoItem[]>(() =>
  groupColoniesWithoutSale(revenue.value?.toFix ?? []).map(group => ({
    text: t('pi.revenue.todo.text', { character: group.characterName, n: group.planetNames.length, planets: group.planetNames.join(', ') }, group.planetNames.length),
    effect: t('pi.revenue.todo.effect'),
    actionLabel: t('pi.revenue.todo.action'),
    to: { name: 'planetary' },
  })),
)

const todoItems = computed<RevenueTodoItem[]>(() => [...walletTodoItems.value, ...unsoldColoniesTodoItems.value])

function periodButtonClass(days: number): string[] {
  return [
    'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
    selectedDays.value === days ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50',
  ]
}

watch(revenue, () => {
  now.value = Date.now()
})
onMounted(() => {
  void plannerStore.loadFollowContext()
  planetaryStore.fetchProductionProfit()
  return planetaryStore.fetchRevenue(selectedDays.value)
})
watch(selectedDays, days => planetaryStore.fetchRevenue(days))
</script>

<template>
  <div class="space-y-6" data-testid="pi-revenue-tab">
    <div class="flex w-fit max-w-full flex-wrap items-center gap-2 rounded-lg bg-slate-900 p-1" role="group" :aria-label="t('pi.revenue.period')">
      <button
        v-for="days in PERIOD_OPTIONS"
        :key="days"
        type="button"
        :class="periodButtonClass(days)"
        :aria-pressed="selectedDays === days"
        @click="selectedDays = days"
      >{{ t('pi.revenue.days', { n: days }) }}</button>
    </div>

    <ErrorBanner v-if="planetaryStore.error" :message="planetaryStore.error" @dismiss="planetaryStore.clearError()" />

    <div v-if="revenue === null && planetaryStore.error === null" class="flex justify-center py-12">
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>

    <template v-else-if="revenue !== null">
      <PiRevenuePlanLine />

      <RevenueLineGrid
        :title="t('pi.revenue.realisedTitle')"
        :row-label="t('pi.revenue.rowLabel')"
        :row-sub="t('pi.revenue.rowSub')"
        :net-label="t('pi.revenue.realisedNet')"
        :columns="realisedColumns"
        :net="realisedNet"
        :period="period"
      />

      <RevenueLineGrid
        :title="t('pi.revenue.estimateTitle')"
        :row-label="t('pi.revenue.rowLabel')"
        :row-sub="t('pi.revenue.estimateSub')"
        :net-label="t('pi.revenue.estimateNet')"
        :columns="estimateColumns"
        :net="ESTIMATE_NET"
        :period="period"
      />

      <p v-if="estimateProfit !== null" class="text-sm text-slate-300" data-testid="estimate-profit" :data-incomplete="estimateProfit.unknownLineCount > 0">
        {{ t('pi.revenue.estimateProfit', { n: period.days, profit: estimateProfit.amount, purchase: estimateProfit.purchase, sale: estimateProfit.sale }) }}
        <span v-if="estimateProfit.unknownLineCount > 0" class="text-amber-300">{{ t('pi.revenue.estimateProfitUnknown', { n: estimateProfit.unknownLineCount }, estimateProfit.unknownLineCount) }}</span>
      </p>

      <ProjectSellOrdersCard
        :sell-orders="revenue.sellOrders"
        :now="now"
        :title="t('pi.revenue.sellOrders.title')"
        :empty="t('pi.revenue.sellOrders.empty')"
      />
      <PiProductionProfitGrid v-if="productionProfit !== null" :profit="productionProfit" @open-planner="emit('open-planner')" />

      <RevenueTodoPanel :items="todoItems" />
    </template>
  </div>
</template>
