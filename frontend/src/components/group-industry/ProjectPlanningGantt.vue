<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { PlanningJob, ProjectPlanning } from '@/stores/group-industry/types'
import PlanningWaitLabel from './PlanningWaitLabel.vue'
import UnknownContributionValue from './UnknownContributionValue.vue'

const props = defineProps<{
  planning: ProjectPlanning
}>()

const NOW_REFRESH_MS = 60_000
const MINIMUM_SPAN_MS = 60_000
const MINIMUM_BAR_PERCENT = 0.6
const PERCENT = 100
const MS_PER_SECOND = 1000

const { t } = useI18n()
const { formatDateTime } = useFormatters()

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

function timesOf(job: PlanningJob): number[] {
  const times: number[] = []
  if (job.planned) times.push(Date.parse(job.planned.start), Date.parse(job.planned.end))
  if (job.real) times.push(Date.parse(job.real.start), Date.parse(job.real.end))
  return times
}

const range = computed(() => {
  const times = [Date.parse(props.planning.anchor), now.value]
  for (const character of props.planning.characters) {
    for (const job of character.jobs) times.push(...timesOf(job))
  }
  const start = Math.min(...times)
  const end = Math.max(...times)
  return { start, span: Math.max(end - start, MINIMUM_SPAN_MS), end }
})

function percentOf(timestamp: number): number {
  return ((timestamp - range.value.start) / range.value.span) * PERCENT
}

function barStyle(start: number, end: number): Record<string, string> {
  const left = percentOf(start)
  const width = Math.max(percentOf(end) - left, MINIMUM_BAR_PERCENT)
  return { left: `${left}%`, width: `${width}%` }
}

function plannedStyle(job: PlanningJob): Record<string, string> | null {
  return job.planned ? barStyle(Date.parse(job.planned.start), Date.parse(job.planned.end)) : null
}

function realStyle(job: PlanningJob): Record<string, string> | null {
  return job.real ? barStyle(Date.parse(job.real.start), Date.parse(job.real.end)) : null
}

function waitStyle(job: PlanningJob): Record<string, string> | null {
  if (!job.planned || !job.wait) return null
  const start = Date.parse(job.planned.start)
  return barStyle(start, start + job.wait.lateSeconds * MS_PER_SECOND)
}

function periodTitle(period: { start: string; end: string }): string {
  return `${formatDateTime(period.start)} → ${formatDateTime(period.end)}`
}

const nowStyle = computed(() => ({ left: `${percentOf(now.value)}%` }))
</script>

<template>
  <div data-testid="planning-gantt" class="px-4 py-3">
    <div class="grid grid-cols-[12rem_1fr] gap-x-3 px-3 text-xs uppercase tracking-wider text-slate-500 mb-2">
      <span>{{ t('groupIndustry.planning.ganttCharacter') }}</span>
      <span class="flex justify-between">
        <span>{{ formatDateTime(new Date(range.start).toISOString()) }}</span>
        <span>{{ formatDateTime(new Date(range.end).toISOString()) }}</span>
      </span>
    </div>

    <div class="relative space-y-3">
      <div class="absolute inset-y-0 left-[13.5rem] right-3 pointer-events-none">
        <div
          data-testid="planning-now"
          class="absolute top-0 bottom-0 border-l border-cyan-400/80"
          :style="nowStyle"
        >
          <span class="absolute -top-1 left-1 text-xs uppercase tracking-wider text-cyan-300">{{ t('groupIndustry.planning.now') }}</span>
        </div>
      </div>

      <div
        v-for="character in planning.characters"
        :key="character.characterName"
        :data-testid="`planning-lane-${character.characterName}`"
        class="rounded border border-slate-800 bg-slate-950/40"
      >
        <div class="px-3 py-1.5 border-b border-slate-800/70 min-w-0">
          <span
            data-testid="lane-name"
            :title="character.characterName"
            class="block truncate font-display text-xs font-semibold uppercase tracking-wider text-slate-200"
          >{{ character.characterName }}</span>
        </div>

        <div v-if="character.skillsUnknown" data-testid="lane-unknown" class="flex items-center gap-2 px-3 py-2 text-xs text-violet-300">
          <UnknownContributionValue data-testid="planning-unknown" />
          <span>{{ t('groupIndustry.planning.skillsUnknownCause') }}</span>
        </div>
        <p v-else-if="character.jobs.length === 0" class="px-3 py-2 text-xs text-slate-500">
          {{ t('groupIndustry.planning.noJob') }}
        </p>
        <div v-else class="divide-y divide-slate-800/50">
          <div
            v-for="job in character.jobs"
            :key="job.lineId"
            :data-testid="`planning-job-${job.lineId}`"
            :data-activity="job.activity"
            class="grid grid-cols-[12rem_1fr] gap-x-3 px-3 py-1.5"
          >
            <div class="min-w-0">
              <span
                data-testid="job-name"
                :title="job.typeName"
                class="block truncate text-xs text-slate-200"
              >{{ job.typeName }}</span>
              <span class="block text-xs uppercase tracking-wider text-slate-500">
                {{ t(`groupIndustry.planning.activity.${job.activity}`) }} · {{ t('groupIndustry.planning.runs', { runs: job.runs }) }}
              </span>
            </div>
            <div class="min-w-0">
              <div class="relative h-6">
                <div
                  v-if="waitStyle(job)"
                  class="absolute top-0 bottom-0 rounded-sm border border-amber-400/40 bg-[repeating-linear-gradient(135deg,rgba(251,191,36,0.35)_0,rgba(251,191,36,0.35)_4px,transparent_4px,transparent_8px)]"
                  :style="waitStyle(job) ?? undefined"
                ></div>
                <div
                  v-if="plannedStyle(job)"
                  data-testid="bar-planned"
                  :data-start="job.planned?.start"
                  :data-end="job.planned?.end"
                  :title="job.planned ? periodTitle(job.planned) : undefined"
                  class="absolute top-0 h-2.5 rounded-sm border border-dashed border-slate-400 bg-slate-500/10"
                  :style="plannedStyle(job) ?? undefined"
                ></div>
                <div
                  v-if="realStyle(job)"
                  data-testid="bar-real"
                  :data-start="job.real?.start"
                  :data-end="job.real?.end"
                  :data-status="job.real?.status"
                  :title="job.real ? periodTitle(job.real) : undefined"
                  class="absolute bottom-0 h-2.5 rounded-sm border"
                  :class="job.real?.status === 'done' ? 'border-emerald-400 bg-emerald-500/60' : 'border-cyan-400 bg-cyan-500/60'"
                  :style="realStyle(job) ?? undefined"
                ></div>
              </div>
              <PlanningWaitLabel
                v-if="job.wait"
                :wait="job.wait"
                data-testid="job-wait"
                class="block mt-0.5 text-xs text-amber-300"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
