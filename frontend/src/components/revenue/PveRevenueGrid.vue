<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePveStore, type PveGridColumnId } from '@/stores/pve'
import type { RevenueTodoItem } from '@/types/revenueGrid'
import { useFormatters } from '@/composables/useFormatters'
import { buildPveNetSeries, buildPveRevenueColumns, pveTodoKinds, type PveTodoKind } from '@/utils/pveRevenueGrid'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import RevenueLineGrid from './RevenueLineGrid.vue'
import RevenueTodoPanel from './RevenueTodoPanel.vue'

const props = defineProps<{
  days: number
  neverSynced: boolean
  refreshToken?: unknown
}>()

const emit = defineEmits<{
  sync: []
  scanLoot: []
  scanExpenses: []
}>()

const { t } = useI18n()
const { formatDate } = useFormatters()
const pveStore = usePveStore()

const columnLabels = computed<Record<PveGridColumnId, string>>(() => ({
  bounties: t('revenue.pve.columns.bounties'),
  ess: t('revenue.pve.columns.ess'),
  missions: t('revenue.pve.columns.missions'),
  lootSales: t('revenue.pve.columns.lootSales'),
  escalations: t('revenue.pve.columns.escalations'),
  expenses: t('revenue.pve.columns.expenses'),
}))

const todoKinds = computed(() => pveTodoKinds(pveStore.grid, props.neverSynced))
const columns = computed(() => (pveStore.grid === null ? [] : buildPveRevenueColumns(pveStore.grid, columnLabels.value, todoKinds.value)))
const netSeries = computed(() => (pveStore.grid === null ? null : buildPveNetSeries(pveStore.grid)))
const period = computed(() => (pveStore.grid === null ? null : { to: pveStore.grid.period.to, days: pveStore.grid.period.days }))

function scanTodoItem(kind: 'lootScan' | 'expenseScan', lastScanAt: string | null): RevenueTodoItem {
  return {
    text: lastScanAt === null ? t(`revenue.pve.todo.${kind}.neverText`) : t(`revenue.pve.todo.${kind}.overdueText`, { date: formatDate(lastScanAt) }),
    effect: t(`revenue.pve.todo.${kind}.effect`),
    actionLabel: t(`revenue.pve.todo.${kind}.action`),
  }
}

function todoItemOf(kind: PveTodoKind): RevenueTodoItem {
  if (kind === 'neverSynced') {
    return { text: t('revenue.pve.todo.neverSynced.text'), effect: t('revenue.pve.todo.neverSynced.effect'), actionLabel: t('common.actions.refresh') }
  }
  const scans = pveStore.grid?.scans
  return kind === 'lootScan' ? scanTodoItem(kind, scans?.lootSales.lastScanAt ?? null) : scanTodoItem(kind, scans?.expenses.lastScanAt ?? null)
}

const todoItems = computed<RevenueTodoItem[]>(() => todoKinds.value.map(todoItemOf))

function onTodoAction(index: number) {
  const kind = todoKinds.value[index]
  if (kind === 'neverSynced') emit('sync')
  else if (kind === 'lootScan') emit('scanLoot')
  else if (kind === 'expenseScan') emit('scanExpenses')
}

function load(): Promise<void> {
  return pveStore.fetchGrid(props.days)
}

watch(() => [props.days, props.refreshToken], load)
onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <RevenueLineGrid v-if="pveStore.grid && netSeries && period" :title="t('revenue.pve.title')" row-label="PVE" :columns="columns" :net="netSeries" :period="period" contributor-unit="characters" />
    <ErrorBanner v-else-if="pveStore.error" :message="pveStore.error" @dismiss="pveStore.clearError()" />
    <LoadingSpinner v-else />
    <RevenueTodoPanel :items="todoItems" @action="onTodoAction" />
  </div>
</template>
