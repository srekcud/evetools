<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePveStore, type PveGridColumnId } from '@/stores/pve'
import type { RevenueTodoItem } from '@/types/revenueGrid'
import { buildPveNetSeries, buildPveRevenueColumns } from '@/utils/pveRevenueGrid'
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
}>()

const { t } = useI18n()
const pveStore = usePveStore()

const columnLabels = computed<Record<PveGridColumnId, string>>(() => ({
  bounties: t('revenue.pve.columns.bounties'),
  ess: t('revenue.pve.columns.ess'),
  missions: t('revenue.pve.columns.missions'),
  lootSales: t('revenue.pve.columns.lootSales'),
  escalations: t('revenue.pve.columns.escalations'),
  expenses: t('revenue.pve.columns.expenses'),
}))

const columns = computed(() => (pveStore.grid === null ? [] : buildPveRevenueColumns(pveStore.grid, columnLabels.value)))
const netSeries = computed(() => (pveStore.grid === null ? null : buildPveNetSeries(pveStore.grid)))
const period = computed(() => (pveStore.grid === null ? null : { to: pveStore.grid.period.to, days: pveStore.grid.period.days }))

const todoItems = computed<RevenueTodoItem[]>(() =>
  props.neverSynced
    ? [{ text: t('revenue.pve.todo.neverSynced.text'), effect: t('revenue.pve.todo.neverSynced.effect'), actionLabel: t('common.actions.refresh') }]
    : [],
)

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
    <RevenueTodoPanel :items="todoItems" @action="emit('sync')" />
  </div>
</template>
