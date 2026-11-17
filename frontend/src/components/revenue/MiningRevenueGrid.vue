<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useLedgerStore, type MiningGridColumnId } from '@/stores/ledger'
import type { RevenueTodoItem } from '@/types/revenueGrid'
import { buildMiningNetSeries, buildMiningRevenueColumns, miningTodoKinds } from '@/utils/miningRevenueGrid'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import RevenueLineGrid from './RevenueLineGrid.vue'
import RevenueTodoPanel from './RevenueTodoPanel.vue'

const props = defineProps<{
  missingPriceLabels: string[]
}>()

const emit = defineEmits<{
  categorise: []
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const ledgerStore = useLedgerStore()

const columnLabels = computed<Record<MiningGridColumnId, string>>(() => ({
  sold: t('revenue.mining.columns.sold'),
  corp_project: t('revenue.mining.columns.corpProject'),
  unknown: t('revenue.mining.columns.uncategorised'),
}))

const grid = computed(() => ledgerStore.miningGrid)
const columns = computed(() => (grid.value === null ? [] : buildMiningRevenueColumns(grid.value, columnLabels.value)))
const netSeries = computed(() => (grid.value === null ? null : buildMiningNetSeries(grid.value)))
const period = computed(() => (grid.value === null ? null : { to: grid.value.period.to, days: grid.value.period.days }))

const todoItems = computed<RevenueTodoItem[]>(() => {
  if (grid.value === null) return []
  const todoGrid = grid.value
  return miningTodoKinds(todoGrid).map(kind =>
    kind === 'missingPrice'
      ? {
          text: t('revenue.mining.todo.missingPrice.text', { types: props.missingPriceLabels.join(', ') }),
          effect: t('revenue.mining.todo.missingPrice.effect'),
          actionLabel: t('revenue.mining.todo.missingPrice.action'),
          to: { name: 'mining-ore-value' },
        }
      : {
          text: t('revenue.mining.todo.uncategorised.text', { quantity: formatNumber(todoGrid.columns.unknown.quantity) }),
          effect: t('revenue.mining.todo.uncategorised.effect'),
          actionLabel: t('revenue.mining.todo.uncategorised.action'),
        },
  )
})
</script>

<template>
  <div class="space-y-6">
    <RevenueLineGrid v-if="grid && netSeries && period" :title="t('revenue.mining.title')" :row-label="t('revenue.mining.rowLabel')" :columns="columns" :net="netSeries" :period="period" contributor-unit="characters" />
    <ErrorBanner v-else-if="ledgerStore.error" :message="ledgerStore.error" @dismiss="ledgerStore.clearError()" />
    <LoadingSpinner v-else />
    <RevenueTodoPanel :items="todoItems" @action="emit('categorise')" />
  </div>
</template>
