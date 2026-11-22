<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useUnassignedJobsStore } from '@/stores/group-industry/unassignedJobs'
import type { UnassignedJob } from '@/stores/group-industry/types'
import { ACTIVITY_STYLES } from './companionActivityStyles'
import type { ActivityGroup } from './companionTasks'

const ACTIVITY_GROUP_BY_ID: Record<number, ActivityGroup> = {
  1: 'manufacturing',
  5: 'copy',
  8: 'invention',
  9: 'reaction',
  11: 'reaction',
}
const PRODUCT_ICON_SIZE = 64

const { t } = useI18n()
const { formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const store = useUnassignedJobsStore()

function styleOf(job: UnassignedJob) {
  return ACTIVITY_STYLES[ACTIVITY_GROUP_BY_ID[job.activityId] ?? 'unknown']
}
</script>

<template>
  <section
    v-if="store.count > 0 || store.error !== null"
    data-testid="unassigned-jobs-panel"
    class="mb-6"
  >
    <div class="mb-3 flex items-center gap-3">
      <h2
        v-if="store.count > 0"
        data-testid="unassigned-jobs-title"
        class="font-mono text-sm uppercase tracking-[0.14em] text-amber-300"
      >{{ t('groupIndustry.unassignedJobs.title', { count: store.count }) }}</h2>
      <span
        v-if="store.isLoading"
        data-testid="unassigned-jobs-loading"
        role="status"
        class="text-xs text-slate-500"
      >{{ t('groupIndustry.unassignedJobs.loading') }}</span>
    </div>

    <p
      v-if="store.error !== null"
      data-testid="unassigned-jobs-error"
      role="alert"
      class="mb-3 rounded border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-300"
    >{{ store.error }}</p>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <article
        v-for="job in store.jobs"
        :key="job.esiJobId"
        data-testid="unassigned-job"
        class="rounded-xl border border-l-4 border-slate-800 bg-gradient-to-r to-slate-900/80 bg-slate-900/80 p-4"
        :class="[styleOf(job).stripe, styleOf(job).tint]"
      >
        <div class="mb-3 flex items-start gap-3">
          <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-700 bg-slate-800">
            <img
              :src="getTypeIconUrl(job.productTypeId, PRODUCT_ICON_SIZE)"
              :alt="job.productTypeName ?? ''"
              class="h-full w-full object-cover"
              @error="onImageError"
            >
          </div>
          <div class="min-w-0 flex-1">
            <h3 data-testid="unassigned-job-product" class="truncate text-base font-semibold" :class="styleOf(job).text">
              <template v-if="job.productTypeName !== null">{{ job.productTypeName }}</template>
              <UnknownValue v-else />
            </h3>
            <p class="font-mono text-xs text-slate-400">
              <span data-testid="unassigned-job-character">{{ job.characterName }}</span>
              ·
              <span data-testid="unassigned-job-runs">{{ t('groupIndustry.unassignedJobs.runs', { count: job.runs }) }}</span>
            </p>
            <p class="truncate text-xs text-slate-500">
              <span data-testid="unassigned-job-station">
                <template v-if="job.stationName !== null">{{ job.stationName }}</template>
                <UnknownValue v-else />
              </span>
            </p>
            <p class="font-mono text-xs text-slate-500">
              <span data-testid="unassigned-job-start">{{ formatDateTime(job.startDate) }}</span>
              →
              <span data-testid="unassigned-job-end">{{ formatDateTime(job.endDate) }}</span>
            </p>
          </div>
          <button
            type="button"
            data-testid="unassign-dismiss"
            :disabled="store.isLoading"
            class="flex-shrink-0 border border-slate-700 px-2 py-1 text-xs uppercase tracking-wider text-slate-400 hover:bg-slate-800 disabled:opacity-50"
            @click="store.dismiss(job.esiJobId)"
          >{{ t('groupIndustry.unassignedJobs.dismiss') }}</button>
        </div>

        <ul class="flex flex-col gap-2">
          <li
            v-for="candidate in job.candidates"
            :key="candidate.lineId"
            data-testid="unassigned-job-candidate"
            class="flex items-center gap-3 rounded border border-slate-800 bg-slate-950/60 px-3 py-2"
          >
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm text-slate-200">
                <span data-testid="unassigned-candidate-project">
                  <template v-if="candidate.projectName !== null">{{ candidate.projectName }}</template>
                  <UnknownValue v-else />
                </span>
                ·
                <span data-testid="unassigned-candidate-line">{{ candidate.lineName }}</span>
              </p>
              <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                <span data-testid="unassigned-candidate-remaining" class="font-mono text-slate-400">{{ t('groupIndustry.unassignedJobs.remainingRuns', { count: candidate.remainingRuns }) }}</span>
                <span
                  v-for="signal in candidate.signals"
                  :key="signal"
                  data-testid="unassigned-candidate-signal"
                  class="rounded border border-cyan-500/30 bg-cyan-500/10 px-1.5 py-0.5 text-cyan-300"
                >{{ t(`groupIndustry.unassignedJobs.signals.${signal}`) }}</span>
                <span
                  v-if="candidate.oversized"
                  data-testid="unassigned-candidate-oversized"
                  class="rounded border border-amber-500/40 bg-amber-500/10 px-1.5 py-0.5 text-amber-300"
                >{{ t('groupIndustry.unassignedJobs.oversized') }}</span>
              </div>
            </div>
            <button
              type="button"
              data-testid="unassign-assign"
              :disabled="store.isLoading"
              class="flex-shrink-0 border border-cyan-400 bg-cyan-400 px-3 py-1 text-xs font-bold uppercase tracking-wider text-cyan-950 hover:bg-cyan-300 disabled:opacity-50"
              @click="store.assign(job.esiJobId, candidate.lineId)"
            >{{ t('groupIndustry.unassignedJobs.assign') }}</button>
          </li>
        </ul>
      </article>
    </div>
  </section>
</template>
