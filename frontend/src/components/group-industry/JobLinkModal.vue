<script setup lang="ts">
import { reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEsiJobStatusLabel } from '@/composables/useEsiJobStatusLabel'
import { useFormatters } from '@/composables/useFormatters'
import BaseModal from '@/components/common/BaseModal.vue'
import IskAmount from '@/components/common/IskAmount.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import type { AvailableJob } from '@/stores/group-industry/types'

defineProps<{
  show: boolean
  lineName: string
  jobs: AvailableJob[]
  loading: boolean
  error: string | null
  linkingEsiJobId: number | null
}>()

const emit = defineEmits<{
  close: []
  link: [esiJobId: number, runs: number | undefined]
}>()

const { t } = useI18n()

const { esiJobStatusLabel } = useEsiJobStatusLabel()
const { formatDateTime, formatNumber } = useFormatters()

const typedRunsByJobId = reactive<Record<number, number>>({})

function runsToAssignOf(job: AvailableJob): number {
  return typedRunsByJobId[job.esiJobId] ?? job.remainingRuns
}

function setRunsToAssign(job: AvailableJob, event: Event): void {
  const typed = Number((event.target as HTMLInputElement).value)
  if (Number.isInteger(typed) && typed >= 1 && typed <= job.remainingRuns) {
    typedRunsByJobId[job.esiJobId] = typed
  }
}

function link(job: AvailableJob): void {
  const runs = runsToAssignOf(job)
  emit('link', job.esiJobId, runs === job.remainingRuns ? undefined : runs)
}
</script>

<template>
  <BaseModal
    :show="show"
    :title="t('groupIndustry.jobs.linkModalTitle', { name: lineName })"
    panel-class="border-slate-700 max-w-3xl max-h-[90vh] overflow-y-auto"
    @close="emit('close')"
  >
    <template #title="{ titleId }">
      <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
        <h2 :id="titleId" class="text-lg font-bold text-slate-100">{{ t('groupIndustry.jobs.linkModalTitle', { name: lineName }) }}</h2>
        <button
          class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
          :aria-label="t('common.actions.close')"
          @click="emit('close')"
        >
          <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </template>

    <div class="px-6 py-5">
      <p class="text-sm text-slate-400 mb-4">{{ t('groupIndustry.jobs.linkModalDescription') }}</p>

      <p
        v-if="error !== null"
        data-testid="job-link-error"
        role="alert"
        class="mb-3 px-3 py-2 bg-red-500/10 border border-red-500/30 rounded-lg text-red-400 text-sm"
      >{{ error }}</p>

      <div v-if="loading" class="py-6 text-center text-slate-500 text-sm">
        <LoadingSpinner class="w-5 h-5 mx-auto mb-2 text-cyan-500" />
        {{ t('groupIndustry.jobs.availableJobsLoading') }}
      </div>

      <p v-else-if="jobs.length === 0" class="py-6 text-center text-slate-500 text-sm">
        {{ t('groupIndustry.jobs.availableJobsEmpty') }}
      </p>

      <div v-else class="bg-slate-800/50 rounded-xl border border-slate-700/50 overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-700">
              <th class="text-left py-2.5 px-4">{{ t('groupIndustry.jobs.character') }}</th>
              <th class="text-left py-2.5 px-3">{{ t('groupIndustry.jobs.product') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('groupIndustry.jobs.runs') }}</th>
              <th class="text-left py-2.5 px-3">{{ t('groupIndustry.jobs.status') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('groupIndustry.jobs.jobCost') }}</th>
              <th class="text-right py-2.5 px-4"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-700/50">
            <tr v-for="job in jobs" :key="job.esiJobId" data-testid="available-job" class="hover:bg-slate-800/30">
              <td class="py-2.5 px-4 text-slate-200">
                {{ job.characterName }}
                <span class="block text-xs text-slate-500">{{ formatDateTime(job.startDate) }}</span>
              </td>
              <td class="py-2.5 px-3 text-slate-300">{{ job.productTypeName ?? job.productTypeId }}</td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">{{ formatNumber(job.runs, 0) }}</td>
              <td class="py-2.5 px-3 text-slate-400">{{ esiJobStatusLabel(job.status) }}</td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-400" style="font-variant-numeric: tabular-nums;"><IskAmount :value="job.cost" /></td>
              <td class="py-2.5 px-4 text-right">
                <span
                  v-if="job.linkedToBomLineId !== null"
                  data-testid="available-job-linked"
                  class="px-2 py-0.5 rounded-sm text-xs bg-emerald-500/10 text-emerald-400"
                >{{ t('groupIndustry.jobs.alreadyLinkedTo', { name: job.linkedToBomLineName ?? job.linkedToBomLineId }) }}</span>
                <span
                  v-if="job.linkedRuns > 0"
                  data-testid="available-job-shared"
                  class="ml-1 px-2 py-0.5 rounded-sm text-xs bg-amber-500/10 text-amber-400"
                >{{ t('groupIndustry.jobs.sharedRuns', { linkedRuns: job.linkedRuns, runs: job.runs }) }}</span>
                <template v-if="job.remainingRuns > 0">
                  <input
                    data-testid="available-job-runs"
                    type="number"
                    min="1"
                    :max="job.remainingRuns"
                    :value="runsToAssignOf(job)"
                    :aria-label="t('groupIndustry.jobs.runsToAssign')"
                    class="ml-2 w-16 px-1 py-0.5 rounded-sm text-xs text-right font-mono bg-slate-900 border border-slate-700 text-slate-200"
                    @input="setRunsToAssign(job, $event)"
                  />
                  <button
                    data-testid="available-job-link"
                    class="ml-1 px-2 py-0.5 rounded-sm text-xs bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 hover:bg-cyan-500/20 disabled:opacity-50"
                    :disabled="linkingEsiJobId !== null"
                    @click="link(job)"
                  >{{ t('groupIndustry.jobs.link') }}</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </BaseModal>
</template>
