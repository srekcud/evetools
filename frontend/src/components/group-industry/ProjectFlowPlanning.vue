<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCoalescedGroupProjectEvents } from '@/composables/useCoalescedGroupProjectEvents'
import { useFormatters } from '@/composables/useFormatters'
import { useRefetchOnReconnect } from '@/composables/useRefetchOnReconnect'
import { useGroupPlanningStore } from '@/stores/group-industry/planning'
import { useSyncStore } from '@/stores/sync'
import type { BomItem } from '@/stores/group-industry/types'
import { ACTIVITY_STYLES } from './companionActivityStyles'
import { buildFlowPlanning, type PlanningBar, type PlanningBarState, type PlanningStation } from './projectFlowPlanning'
import type { FlowGrid } from './projectFlow'
import UnknownContributionValue from './UnknownContributionValue.vue'

const props = defineProps<{
  projectId: string
  grid: FlowGrid
  bomItems: BomItem[]
}>()

const NOW_REFRESH_MS = 60_000
const MS_PER_DAY = 86_400_000
const PERCENT = 100
const MINIMUM_BAR_PERCENT = 0.6

const BAR_STYLES: Record<PlanningBarState, string> = {
  done: 'bg-emerald-500',
  running: 'bg-sky-400',
  planned: 'bg-amber-400',
  waiting: 'bg-[repeating-linear-gradient(45deg,#475569_0_4px,rgba(51,65,85,0.55)_4px_8px)]',
}

const LEGEND_STATES: PlanningBarState[] = ['planned', 'running', 'waiting', 'done']

const { t } = useI18n()
const { formatDateTime } = useFormatters()
const syncStore = useSyncStore()
const planningStore = useGroupPlanningStore()

const now = ref(Date.now())
let nowTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  nowTimer = setInterval(() => {
    now.value = Date.now()
  }, NOW_REFRESH_MS)
})

onBeforeUnmount(() => {
  if (nowTimer !== null) clearInterval(nowTimer)
})

watch(() => props.projectId, projectId => planningStore.fetchPlanning(projectId), { immediate: true })

useCoalescedGroupProjectEvents(
  () => props.projectId,
  () => {
    planningStore.fetchPlanning(props.projectId)
  },
)

useRefetchOnReconnect(() => planningStore.fetchPlanning(props.projectId))

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    planningStore.fetchPlanning(props.projectId)
  },
)

const planning = computed(() => planningStore.planning)

const flowPlanning = computed(() => (planning.value === null
  ? null
  : buildFlowPlanning({ planning: planning.value, grid: props.grid, bomItems: props.bomItems, now: now.value })))

const expectedEnd = computed((): string | null => (planning.value === null ? null : planning.value.expectedEnd ?? planning.value.plannedEnd))

const expectedEndDays = computed((): number | null => {
  if (expectedEnd.value === null) return null
  const remaining = Date.parse(expectedEnd.value) - now.value
  return remaining < 0 ? null : Math.floor(remaining / MS_PER_DAY)
})

const isEmpty = computed(() => planning.value !== null
  && planning.value.jobs.length === 0
  && planning.value.unscheduled.length === 0)

function percentOf(timestamp: number): number {
  if (flowPlanning.value === null || flowPlanning.value.start === null || flowPlanning.value.end === null) return 0
  return ((timestamp - flowPlanning.value.start) / (flowPlanning.value.end - flowPlanning.value.start)) * PERCENT
}

function barStyle(bar: PlanningBar): Record<string, string> {
  const left = percentOf(bar.start)
  return { left: `${left}%`, width: `${Math.max(percentOf(bar.end) - left, MINIMUM_BAR_PERCENT)}%` }
}

const nowStyle = computed(() => ({ left: `${percentOf(now.value)}%` }))

function endLabel(bar: PlanningBar): string {
  if (bar.state === 'done') return t('groupIndustry.flowPlanning.done')
  if (bar.end < now.value) return formatDateTime(new Date(bar.end).toISOString())
  return t('groupIndustry.flowPlanning.dayOffset', { days: Math.floor((bar.end - now.value) / MS_PER_DAY) })
}

function periodTitle(bar: PlanningBar): string {
  return `${formatDateTime(new Date(bar.start).toISOString())} → ${formatDateTime(new Date(bar.end).toISOString())}`
}

function stationLabel(station: PlanningStation): string {
  if (station.kind === 'unresolved') return t('groupIndustry.flow.unresolvedRow')
  return station.name ?? t('groupIndustry.flow.unnamedStation')
}
</script>

<template>
  <section data-testid="flow-planning" class="border border-slate-800 bg-slate-900 px-3 py-2">
    <p
      v-if="planningStore.loading && planning === null"
      data-testid="flow-planning-loading"
      class="py-6 text-center text-sm text-slate-500"
    >{{ t('groupIndustry.flowPlanning.loading') }}</p>

    <div
      v-else-if="planningStore.error !== null"
      data-testid="flow-planning-error"
      role="alert"
      class="my-2 border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.flowPlanning.loadError') }}</p>
      <p class="mt-1 break-words">{{ planningStore.error }}</p>
    </div>

    <template v-else-if="planning !== null && flowPlanning !== null">
      <div class="mb-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11.5px]">
        <h2 class="font-display text-[13px] font-bold uppercase tracking-[0.08em] text-slate-200">{{ t('groupIndustry.flowPlanning.title') }}</h2>
        <span class="text-slate-500">
          {{ t('groupIndustry.flowPlanning.expectedEnd') }}
          <span
            data-testid="flow-planning-expected-end"
            :data-value="expectedEnd ?? undefined"
            class="text-slate-300"
          >
            <template v-if="expectedEnd !== null">{{ formatDateTime(expectedEnd) }}<template v-if="expectedEndDays !== null"> (<span data-testid="flow-planning-expected-end-days">{{ t('groupIndustry.flowPlanning.dayOffset', { days: expectedEndDays }) }}</span>)</template></template>
            <UnknownContributionValue v-else data-testid="flow-planning-unknown" />
          </span>
        </span>
        <span data-testid="flow-planning-transfers-note" class="text-slate-500">{{ t('groupIndustry.flowPlanning.transfersNote') }}</span>
      </div>

      <p
        v-if="!planning.structureTimeBonusIncluded"
        data-testid="flow-planning-structure-notice"
        class="mb-2 text-xs text-slate-400"
      >{{ t('groupIndustry.planning.structureNotice') }}</p>

      <p
        v-if="isEmpty"
        data-testid="flow-planning-empty"
        class="py-6 text-center text-sm text-slate-400"
      >{{ t('groupIndustry.flowPlanning.empty') }}</p>

      <template v-if="flowPlanning.stations.length > 0">
        <ul class="mb-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-400">
          <li v-for="state in LEGEND_STATES" :key="state" class="inline-flex items-center gap-1.5">
            <span class="h-2 w-4" :class="BAR_STYLES[state]" />
            {{ t(`groupIndustry.flowPlanning.states.${state}`) }}
          </li>
        </ul>
        <div
          v-for="station in flowPlanning.stations"
          :key="station.key"
          :data-testid="`flow-planning-station-${station.key}`"
        >
          <p
            data-testid="flow-planning-station-title"
            class="mb-0.5 mt-2 font-display text-[11.5px] font-bold uppercase tracking-[0.1em] text-cyan-400"
          >{{ stationLabel(station) }}</p>
          <div
            v-for="bar in station.bars"
            :key="bar.lineId"
            class="grid grid-cols-[120px_1fr_54px] items-center gap-2 py-px text-[11.5px] sm:grid-cols-[210px_1fr_54px]"
          >
            <div class="flex min-w-0 items-center gap-1.5">
              <span class="h-2 w-2 shrink-0" :class="ACTIVITY_STYLES[bar.activity].bar" />
              <span
                :data-testid="`flow-planning-type-${bar.lineId}`"
                :title="bar.typeName"
                class="truncate text-slate-300"
              >{{ bar.typeName }}</span>
            </div>
            <div class="relative h-3 bg-slate-900">
              <i
                data-testid="flow-planning-now"
                class="absolute -bottom-0.5 -top-0.5 w-px bg-fuchsia-400"
                :style="nowStyle"
              />
              <span
                :data-testid="`flow-planning-bar-${bar.lineId}`"
                :data-state="bar.state"
                :title="periodTitle(bar)"
                class="absolute top-0.5 h-2"
                :class="BAR_STYLES[bar.state]"
                :style="barStyle(bar)"
              />
            </div>
            <span :data-testid="`flow-planning-end-${bar.lineId}`" class="text-right text-slate-500">{{ endLabel(bar) }}</span>
          </div>
        </div>
      </template>

      <div v-if="flowPlanning.undated.length > 0" data-testid="flow-planning-undated" class="mt-3 border-t border-slate-800 pt-2">
        <h3 class="mb-1 text-xs uppercase tracking-wider text-violet-300">{{ t('groupIndustry.flowPlanning.undatedTitle') }}</h3>
        <ul class="space-y-1 text-xs">
          <li v-for="job in flowPlanning.undated" :key="job.lineId" class="flex items-baseline gap-2">
            <UnknownContributionValue />
            <span :title="job.typeName" class="truncate text-slate-200">{{ job.typeName }}</span>
          </li>
        </ul>
      </div>

      <div v-if="planning.unscheduled.length > 0" data-testid="flow-planning-unscheduled" class="mt-3 border-t border-slate-800 pt-2">
        <h3 class="mb-1 text-xs uppercase tracking-wider text-violet-300">{{ t('groupIndustry.planning.unscheduledTitle') }}</h3>
        <ul class="space-y-1 text-xs">
          <li
            v-for="line in planning.unscheduled"
            :key="line.lineId"
            :data-testid="`flow-planning-unscheduled-${line.lineId}`"
            class="flex flex-wrap items-baseline gap-x-2"
          >
            <UnknownContributionValue />
            <span :title="line.typeName" class="max-w-[18rem] truncate text-slate-200">{{ line.typeName }}</span>
            <span class="text-violet-300">{{ t(`groupIndustry.planning.unscheduledReason.${line.reason}`) }}</span>
          </li>
        </ul>
      </div>
    </template>
  </section>
</template>
