<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ProjectActivityEvent, ProjectRunningJob } from '@/stores/group-industry/types'
import { formatCountdown } from './jobCountdown'

const COUNTDOWN_TICK_MS = 1000
const DETAIL_SEPARATOR = ' · '
const JOB_TIME_FIELDS: Record<string, string> = {
  jobs_detected: 'startedAt',
  jobs_finished: 'endedAt',
}

const props = defineProps<{
  events: ProjectActivityEvent[]
  runningJobs: ProjectRunningJob[]
  live: boolean
}>()

const { t, te } = useI18n()
const { formatIskFull, formatNumber, formatDateTime } = useFormatters()

const now = ref(Date.now())
let countdownTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  countdownTimer = setInterval(() => {
    now.value = Date.now()
  }, COUNTDOWN_TICK_MS)
})

onBeforeUnmount(() => {
  if (countdownTimer !== null) {
    clearInterval(countdownTimer)
  }
})

const isEmpty = computed(() => props.events.length === 0 && props.runningJobs.length === 0)

function unknownText(): string {
  return t('groupIndustry.journal.unknown')
}

function plainText(value: unknown): string {
  return typeof value === 'string' || typeof value === 'number' ? String(value) : unknownText()
}

function countText(value: unknown): string {
  return typeof value === 'number' ? formatNumber(value) : unknownText()
}

function iskText(value: unknown): string {
  return typeof value === 'number' ? formatIskFull(value) : unknownText()
}

function percentText(value: unknown): string {
  return typeof value === 'number' ? `${formatNumber(value)} %` : unknownText()
}

function statusText(value: unknown): string {
  return typeof value === 'string' && te(`groupIndustry.status.${value}`) ? t(`groupIndustry.status.${value}`) : plainText(value)
}

function jobsSummary(jobCount: unknown, runs: unknown): string {
  if (typeof jobCount !== 'number' || typeof runs !== 'number') return unknownText()
  return `${t('groupIndustry.journal.jobCount', { count: jobCount }, jobCount)} × ${t('groupIndustry.journal.runCount', { count: runs }, runs)}`
}

function jobCountText(jobCount: unknown): string {
  return typeof jobCount === 'number' ? t('groupIndustry.journal.jobCount', { count: jobCount }, jobCount) : unknownText()
}

function runningJobSummary(job: ProjectRunningJob): string {
  return jobsSummary(job.jobCount, job.runs)
}

function dateTimeText(value: string | null): string {
  return value === null ? unknownText() : formatDateTime(value)
}

function countdownText(job: ProjectRunningJob): string {
  return formatCountdown(job.endsAt, now.value) ?? t('groupIndustry.journal.finished')
}

function kindLabel(event: ProjectActivityEvent): string {
  const key = `groupIndustry.journal.kinds.${event.kind}`
  return te(key) ? t(key) : t('groupIndustry.journal.genericEvent')
}

function detailSegments(event: ProjectActivityEvent): string[] {
  const data = event.data
  switch (event.kind) {
    case 'member_joined':
    case 'member_accepted':
    case 'member_left':
      return [plainText(data.characterName)]
    case 'contribution_submitted':
    case 'contribution_approved':
    case 'contribution_rejected':
      return [plainText(data.characterName), `${plainText(data.bomItemName)} × ${countText(data.quantity)}`]
    case 'sale_recorded':
    case 'sale_updated':
      return [`${plainText(data.typeName)} × ${countText(data.quantity)}`, iskText(data.totalPrice)]
    case 'status_changed':
      return [`${statusText(data.oldStatus)} → ${statusText(data.newStatus)}`]
    case 'fees_updated':
      return [
        t('groupIndustry.journal.fees', { broker: percentText(data.brokerFeePercent), tax: percentText(data.salesTaxPercent) }),
        t('groupIndustry.journal.previousFees', { broker: percentText(data.previousBrokerFeePercent), tax: percentText(data.previousSalesTaxPercent) }),
      ]
    case 'jobs_detected':
      return [
        plainText(data.characterName),
        plainText(data.typeName),
        jobsSummary(data.jobCount, data.runs),
        data.cost === null || data.cost === undefined ? t('groupIndustry.journal.unknownCost') : iskText(data.cost),
        ...(typeof data.endsAt === 'string' ? [t('groupIndustry.journal.endsOn', { date: formatDateTime(data.endsAt) })] : []),
      ]
    case 'jobs_finished':
      return [plainText(data.characterName), plainText(data.typeName), jobCountText(data.jobCount)]
    default:
      return []
  }
}

function detailText(event: ProjectActivityEvent): string {
  return detailSegments(event).join(DETAIL_SEPARATOR)
}

function eventTime(event: ProjectActivityEvent): string {
  const jobTimeField = JOB_TIME_FIELDS[event.kind]
  const gameTime = jobTimeField === undefined ? undefined : event.data[jobTimeField]
  return formatDateTime(typeof gameTime === 'string' ? gameTime : event.occurredAt)
}
</script>

<template>
  <div class="space-y-6">
    <section
      v-if="runningJobs.length > 0"
      class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden"
    >
      <header class="px-4 py-3 border-b border-slate-800">
        <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
          {{ t('groupIndustry.journal.runningTitle') }}
        </h2>
      </header>
      <ul class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
        <li
          v-for="job in runningJobs"
          :key="`${job.typeId}-${job.characterName}`"
          :data-testid="`journal-running-job-${job.typeId}`"
          class="rounded-lg border border-cyan-500/20 bg-slate-950/60 p-3 min-w-0"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p data-testid="journal-product" class="truncate text-sm font-semibold text-slate-100" :title="job.typeName">{{ job.typeName }}</p>
              <p data-testid="journal-pilot" class="truncate text-xs text-slate-400" :title="job.characterName">{{ job.characterName }}</p>
            </div>
            <p data-testid="journal-countdown" class="font-mono text-lg tabular-nums text-cyan-400 shrink-0">{{ countdownText(job) }}</p>
          </div>
          <p data-testid="journal-summary" class="mt-2 text-sm text-slate-300">{{ runningJobSummary(job) }}</p>
          <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
            <dt class="text-slate-500">{{ t('groupIndustry.journal.startedAt') }}</dt>
            <dd class="text-slate-300 text-right">{{ dateTimeText(job.startedAt) }}</dd>
            <dt class="text-slate-500">{{ t('groupIndustry.journal.cost') }}</dt>
            <dd data-testid="journal-cost" class="text-right" :class="job.cost === null ? 'text-amber-400' : 'text-slate-300'">{{ job.cost === null ? t('groupIndustry.journal.unknownCost') : formatIskFull(job.cost) }}</dd>
            <dt class="text-slate-500">{{ t('groupIndustry.journal.ends') }}</dt>
            <dd class="text-slate-300 text-right">{{ formatDateTime(job.endsAt) }}</dd>
          </dl>
        </li>
      </ul>
    </section>

    <section class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
      <header class="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
        <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
          {{ t('groupIndustry.journal.feedTitle') }}
        </h2>
        <span
          v-if="live"
          data-testid="journal-live-badge"
          class="inline-flex items-center gap-1.5 rounded px-2 py-0.5 text-xs font-medium bg-emerald-500/15 text-emerald-400"
        >
          <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse" />
          {{ t('groupIndustry.journal.live') }}
        </span>
      </header>

      <p v-if="isEmpty" data-testid="journal-empty" class="p-6 text-center text-slate-500">{{ t('groupIndustry.journal.empty') }}</p>

      <ul v-else class="divide-y divide-slate-800">
        <li
          v-for="event in events"
          :key="event.id"
          :data-testid="`journal-event-${event.id}`"
          class="flex items-baseline gap-3 px-4 py-2 text-sm min-w-0"
        >
          <time class="shrink-0 font-mono text-xs text-slate-500" :datetime="event.occurredAt">{{ eventTime(event) }}</time>
          <span class="shrink-0 text-xs font-medium uppercase tracking-wider text-sky-400">{{ kindLabel(event) }}</span>
          <span class="min-w-0 truncate text-slate-300" :title="detailText(event)">{{ detailText(event) }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>
