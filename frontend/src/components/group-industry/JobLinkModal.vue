<script setup lang="ts">
import { useI18n } from 'vue-i18n'
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
  link: [esiJobId: number]
}>()

const { t } = useI18n()
const { formatDateTime, formatNumber } = useFormatters()
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
              <td class="py-2.5 px-3 text-slate-400">{{ job.status }}</td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-400" style="font-variant-numeric: tabular-nums;"><IskAmount :value="job.cost" /></td>
              <td class="py-2.5 px-4 text-right">
                <span
                  v-if="job.linkedToBomLineId !== null"
                  data-testid="available-job-linked"
                  class="px-2 py-0.5 rounded-sm text-xs bg-emerald-500/10 text-emerald-400"
                >{{ t('groupIndustry.jobs.alreadyLinkedTo', { name: job.linkedToBomLineName ?? job.linkedToBomLineId }) }}</span>
                <button
                  v-else
                  data-testid="available-job-link"
                  class="px-2 py-0.5 rounded-sm text-xs bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 hover:bg-cyan-500/20 disabled:opacity-50"
                  :disabled="linkingEsiJobId !== null"
                  @click="emit('link', job.esiJobId)"
                >{{ t('groupIndustry.jobs.link') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </BaseModal>
</template>
