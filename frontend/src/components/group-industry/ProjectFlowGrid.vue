<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ACTIVITY_STYLES } from './companionActivityStyles'
import { ACTIVITY_GROUP_ORDER, type ActivityGroup } from './companionTasks'
import ProjectFlowCard from './ProjectFlowCard.vue'
import { FLOW_COLUMNS, foldCell, type FlowCard, type FlowColumn, type FlowGrid, type FlowRow } from './projectFlow'
import { useFlowReasonLabel } from './useFlowReasonLabel'

const props = defineProps<{
  grid: FlowGrid
  personalUse: boolean
}>()

const LEGEND_ACTIVITIES: ActivityGroup[] = ACTIVITY_GROUP_ORDER.filter(activity => activity !== 'unknown')

const { t } = useI18n()
const reasonLabel = useFlowReasonLabel()
const openCells = ref(new Set<string>())
const hoveredLinkKey = ref<string | null>(null)

const linkedKeys = computed(() => new Set(
  props.grid.rows.flatMap(row => FLOW_COLUMNS.flatMap(column => row.cells[column])).flatMap(card => (card.expected === null ? [] : [card.expected.cardKey])),
))

function linkKeyOf(card: FlowCard): string {
  return card.expected?.cardKey ?? card.key
}

function hoverCard(card: FlowCard, entered: boolean): void {
  hoveredLinkKey.value = entered && linkedKeys.value.has(linkKeyOf(card)) ? linkKeyOf(card) : null
}

function columnLabel(column: FlowColumn): string {
  const key = column === 'done' && props.personalUse ? 'doneHandedOver' : column
  return t(`groupIndustry.flow.columns.${key}`)
}

function rowLabel(row: FlowRow): string {
  if (row.kind === 'unresolved') return t('groupIndustry.flow.unresolvedRow')
  return row.name ?? t('groupIndustry.flow.unnamedStation')
}

function cellKey(row: FlowRow, column: FlowColumn): string {
  return `${row.key}|${column}`
}

function toggleCell(row: FlowRow, column: FlowColumn): void {
  const key = cellKey(row, column)
  const next = new Set(openCells.value)
  if (!next.delete(key)) next.add(key)
  openCells.value = next
}

function foldedCell(row: FlowRow, column: FlowColumn) {
  return foldCell(row.cells[column], openCells.value.has(cellKey(row, column)))
}

function foldLabel(row: FlowRow, column: FlowColumn): string {
  const folded = foldedCell(row, column)
  if (openCells.value.has(cellKey(row, column))) return t('groupIndustry.flow.fold.collapse')
  const names = folded.foldLabelNames.map(name => name ?? t('groupIndustry.flow.unnamedType')).join(', ')
  const suffix = folded.hasMoreNames ? '…' : ''
  return t(`groupIndustry.flow.fold.${folded.foldedKind ?? 'onSite'}`, { count: folded.folded.length, names: `${names}${suffix}` })
}
</script>

<template>
  <div data-testid="project-flow-grid">
    <p
      v-if="grid.unavailableReason !== null"
      data-testid="flow-unavailable"
      role="alert"
      class="mb-3 border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-200"
    >{{ t('groupIndustry.flow.unavailable', { reason: reasonLabel(grid.unavailableReason) }) }}</p>
    <p
      v-if="grid.rows.length === 0 && grid.unavailableReason === null"
      data-testid="flow-empty"
      class="border border-slate-800 bg-slate-950 px-3 py-6 text-sm text-slate-400"
    >{{ t('groupIndustry.flow.empty') }}</p>
    <template v-else-if="grid.rows.length > 0">
      <ul
        data-testid="flow-activity-legend"
        class="mb-2 flex flex-wrap gap-x-3 gap-y-1 font-display text-[11px] font-semibold uppercase tracking-[0.08em]"
      >
        <li
          v-for="activity in LEGEND_ACTIVITIES"
          :key="activity"
          data-testid="flow-activity-legend-item"
          class="inline-flex items-center gap-1.5"
          :class="ACTIVITY_STYLES[activity].text"
        >
          <span class="h-[3px] w-2.5" :class="ACTIVITY_STYLES[activity].bar" />
          {{ t(`groupIndustry.companion.activityGroups.${activity}`) }}
        </li>
      </ul>
      <div class="overflow-x-auto pb-1">
        <div class="grid min-w-[930px] grid-cols-[150px_repeat(5,minmax(150px,1fr))] gap-1.5">
          <div />
          <div
            v-for="column in FLOW_COLUMNS"
            :key="column"
            data-testid="flow-column-header"
            class="px-1.5 py-1 font-display text-[11.5px] font-bold uppercase tracking-[0.12em] text-slate-500"
          >{{ columnLabel(column) }}</div>
          <div v-for="row in grid.rows" :key="row.key" data-testid="flow-row" class="contents">
            <div class="flex min-w-0 flex-col gap-1 border border-slate-800 bg-slate-900 p-2">
              <router-link
                v-if="row.locationId !== null"
                :to="{ name: 'industry-station', params: { locationId: String(row.locationId) } }"
                data-testid="flow-row-link"
                class="min-w-0 hover:underline"
                :title="t('groupIndustry.flow.openStation')"
              >
                <span data-testid="flow-row-label" class="break-words font-display text-[15px] font-bold tracking-wide text-cyan-400">{{ rowLabel(row) }}</span>
              </router-link>
              <span v-else data-testid="flow-row-label" class="break-words font-display text-[15px] font-bold tracking-wide text-cyan-400">{{ rowLabel(row) }}</span>
              <span
                v-if="row.here === true"
                data-testid="flow-row-here"
                class="w-fit whitespace-nowrap rounded-sm border border-emerald-400/30 px-1.5 text-[10.5px] text-emerald-400"
              >{{ t('groupIndustry.flow.here') }}</span>
            </div>
            <div
              v-for="column in FLOW_COLUMNS"
              :key="column"
              :data-testid="`flow-cell-${column}`"
              class="flex min-h-[70px] min-w-0 flex-col gap-1.5 border border-slate-800 bg-slate-950 p-1.5"
            >
              <ProjectFlowCard
                v-for="card in foldedCell(row, column).shown"
                :key="card.key"
                :card="card"
                :highlighted="hoveredLinkKey !== null && hoveredLinkKey === linkKeyOf(card)"
                @highlight="hoverCard(card, $event)"
              />
              <button
                v-if="foldedCell(row, column).foldedKind !== null"
                type="button"
                data-testid="flow-fold-toggle"
                class="border border-dashed border-slate-700 p-1 text-left text-[11px] text-slate-500 transition-colors hover:text-slate-300"
                @click="toggleCell(row, column)"
              >{{ foldLabel(row, column) }}</button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
