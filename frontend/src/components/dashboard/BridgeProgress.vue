<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { chunkCountdown } from '@/composables/useCorporationMiningExtractions'
import { useBridgeMiningOperations } from '@/composables/useBridgeMiningOperations'
import { useBridgeEndingJobs } from '@/composables/useBridgeEndingJobs'
import { useBridgeNextChunks } from '@/composables/useBridgeNextChunks'
import { useBridgeExtractors } from '@/composables/useBridgeExtractors'
import type { SourceState } from '@/composables/useTodoItems'
import type { PlanetaryStats } from '@/stores/planetary'
import BridgeProgressCard from '@/components/dashboard/BridgeProgressCard.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'

const MS_PER_SECOND = 1000
const SECONDS_PER_MINUTE = 60
const MINING_OPERATIONS_LINK = '/mining/operations'
const JOBS_LINK = '/industry/production?tab=slots'
const CHUNKS_LINK = '/mining/moons'
const EXTRACTORS_LINK = '/planetary'

const props = defineProps<{
  planetaryStats: SourceState<PlanetaryStats>
}>()

const { t } = useI18n()
const { formatIsk, formatDuration } = useFormatters()
const miningOperations = useBridgeMiningOperations()
const endingJobs = useBridgeEndingJobs()
const nextChunks = useBridgeNextChunks()
const extractors = useBridgeExtractors(computed(() => props.planetaryStats))

function jobTimeLeft(endDate: string): string {
  const secondsLeft = Math.floor((new Date(endDate).getTime() - Date.now()) / MS_PER_SECOND)
  return secondsLeft > 0 ? formatDuration(secondsLeft) : t('dashboard.bridge.progress.jobs.done')
}

function chunkValueOf(chunkValue: { totalValue: number | null } | null): number | null {
  return chunkValue === null ? null : chunkValue.totalValue
}

function thresholdLabel(minutes: number): string {
  return formatDuration(minutes * SECONDS_PER_MINUTE)
}
</script>

<template>
  <section class="space-y-3">
    <h3 class="text-sm font-semibold text-slate-200 uppercase tracking-wider">{{ t('dashboard.bridge.progress.title') }}</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
      <BridgeProgressCard
        card-id="mining-operations"
        :title="t('dashboard.bridge.progress.miningOperations.title')"
        :status="miningOperations.status.value"
        :empty-label="t('dashboard.bridge.progress.miningOperations.empty')"
        :link-to="MINING_OPERATIONS_LINK"
        :link-label="t('dashboard.bridge.progress.miningOperations.link')"
      >
        <p class="mb-2 text-sm text-slate-300">{{ t('dashboard.bridge.progress.miningOperations.count', { count: miningOperations.inProgressCount.value }) }}</p>
        <ul class="space-y-1.5">
          <li v-for="operation in miningOperations.shown.value" :key="operation.id" data-testid="dashboard-progress-item" class="min-w-0">
            <RouterLink
              :to="`/mining/operations/${operation.id}`"
              :title="operation.label"
              class="block truncate text-sm text-slate-200 hover:text-cyan-300 transition-colors"
            >{{ operation.label }}</RouterLink>
          </li>
        </ul>
      </BridgeProgressCard>

      <BridgeProgressCard
        card-id="jobs"
        :title="t('dashboard.bridge.progress.jobs.title')"
        :status="endingJobs.status.value"
        :empty-label="t('dashboard.bridge.progress.jobs.empty')"
        :link-to="JOBS_LINK"
        :link-label="t('dashboard.bridge.progress.jobs.link')"
      >
        <ul class="space-y-1.5">
          <li v-for="job in endingJobs.shown.value" :key="job.jobId" data-testid="dashboard-progress-item" class="flex min-w-0 items-baseline justify-between gap-3 text-sm">
            <span class="min-w-0">
              <span
                class="block truncate text-slate-200"
                :title="job.productTypeName ?? t('dashboard.bridge.progress.jobs.unknownProduct')"
              >{{ job.productTypeName ?? t('dashboard.bridge.progress.jobs.unknownProduct') }}</span>
              <span class="block truncate text-xs text-slate-500" :title="job.characterName">{{ job.characterName }}</span>
            </span>
            <span class="shrink-0 font-mono text-xs text-cyan-400">{{ jobTimeLeft(job.endDate) }}</span>
          </li>
        </ul>
      </BridgeProgressCard>

      <BridgeProgressCard
        card-id="chunks"
        :title="t('dashboard.bridge.progress.chunks.title')"
        :status="nextChunks.status.value"
        :empty-label="t('dashboard.bridge.progress.chunks.empty')"
        :link-to="CHUNKS_LINK"
        :link-label="t('dashboard.bridge.progress.chunks.link')"
      >
        <ul class="space-y-1.5">
          <li v-for="chunk in nextChunks.shown.value" :key="chunk.id" data-testid="dashboard-progress-item" class="flex min-w-0 items-baseline justify-between gap-3 text-sm">
            <span class="min-w-0">
              <span
                class="block truncate text-slate-200"
                :title="chunk.moonName ?? t('dashboard.bridge.progress.chunks.unknownMoon')"
              >{{ chunk.moonName ?? t('dashboard.bridge.progress.chunks.unknownMoon') }}</span>
              <span class="block font-mono text-xs text-slate-500">{{ chunkCountdown(chunk, new Date()) }}</span>
            </span>
            <span class="shrink-0 font-mono text-xs text-amber-400">
              <template v-if="chunkValueOf(chunk.chunkValue) !== null">{{ formatIsk(chunkValueOf(chunk.chunkValue)) }}</template>
              <UnknownValue v-else />
            </span>
          </li>
        </ul>
      </BridgeProgressCard>

      <BridgeProgressCard
        card-id="extractors"
        :title="t('dashboard.bridge.progress.extractors.title')"
        :status="extractors.status.value"
        :empty-label="t('dashboard.bridge.progress.extractors.empty')"
        :link-to="EXTRACTORS_LINK"
        :link-label="t('dashboard.bridge.progress.extractors.link')"
      >
        <template v-if="extractors.extractors.value">
          <p class="text-sm text-slate-200">{{ t('dashboard.bridge.progress.extractors.active', { active: extractors.extractors.value.active, total: extractors.extractors.value.total }) }}</p>
          <p class="mt-1.5 text-sm text-red-300">{{ t('dashboard.bridge.progress.extractors.expired', { count: extractors.extractors.value.expired }) }}</p>
          <p class="mt-1.5 text-sm text-amber-300">
            <template v-if="extractors.extractors.value.expiryThresholdMinutes !== null">{{ t('dashboard.bridge.progress.extractors.expiring', { count: extractors.extractors.value.expiring, threshold: thresholdLabel(extractors.extractors.value.expiryThresholdMinutes) }) }}</template>
            <template v-else>{{ t('dashboard.bridge.progress.extractors.expiringWithoutThreshold', { count: extractors.extractors.value.expiring }) }}</template>
          </p>
        </template>
      </BridgeProgressCard>
    </div>
  </section>
</template>
