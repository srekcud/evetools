<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useToast } from '@/composables/useToast'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import type { UnmatchedData } from '@/stores/profitTracker'

defineProps<{
  data: UnmatchedData
}>()

const { t } = useI18n()
const { formatIsk, formatDate, formatNumber } = useFormatters()
const { showToast } = useToast()

function handleIgnoreJob(_jobId: number): void {
  showToast(t('profitTracker.unmatched.comingSoon'), 'warning')
}

function handleIgnoreSale(_transactionId: number): void {
  showToast(t('profitTracker.unmatched.comingSoon'), 'warning')
}
</script>

<template>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Unmatched Jobs -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">{{ t('profitTracker.unmatched.jobs') }}</h3>
          <span
            v-if="data.unmatchedJobs.length > 0"
            class="inline-flex items-center rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-400"
          >
            {{ data.unmatchedJobs.length }}
          </span>
        </div>
        <span class="text-xs text-slate-500">{{ t('profitTracker.unmatched.jobsInfo') }}</span>
      </div>

      <div v-if="data.unmatchedJobs.length > 0" class="divide-y divide-slate-800/50">
        <div
          v-for="job in data.unmatchedJobs"
          :key="job.jobId"
          class="px-6 py-4 hover:bg-slate-800/30 transition-colors"
        >
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-3">
              <img
                :src="`https://images.evetech.net/types/${job.productTypeId}/icon?size=32`"
                :alt="job.typeName"
                class="w-8 h-8 rounded border border-slate-700"
                loading="lazy"
              />
              <div>
                <div class="flex items-center gap-2">
                  <span class="font-medium text-slate-200 text-sm">{{ job.typeName }}</span>
                  <OpenInGameButton type="market" :target-id="job.productTypeId" />
                </div>
                <div class="text-xs text-slate-500">Job #{{ job.jobId }} &middot; {{ job.runs }} runs</div>
              </div>
            </div>
            <span class="inline-flex items-center rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-400">
              No sale
            </span>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-4 text-xs text-slate-500">
              <span>{{ t('profitTracker.unmatched.completed') }}: {{ formatDate(job.completedDate) }}</span>
              <span>{{ t('profitTracker.table.jobCost') }}: <span class="font-mono text-slate-400">{{ formatIsk(job.cost) }}</span></span>
            </div>
            <button
              @click.stop="handleIgnoreJob(job.jobId)"
              class="px-3 py-1 bg-slate-700/50 hover:bg-slate-600/50 rounded-full text-xs font-medium text-slate-400 hover:text-slate-300 transition-colors shrink-0"
            >
              {{ t('profitTracker.unmatched.ignore') }}
            </button>
          </div>
        </div>
      </div>

      <div v-else class="px-6 py-12 text-center">
        <svg class="w-10 h-10 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm text-slate-500">{{ t('profitTracker.unmatched.allJobsMatched') }}</p>
      </div>
    </div>

    <!-- Unmatched Sales -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">{{ t('profitTracker.unmatched.sales') }}</h3>
          <span
            v-if="data.unmatchedSales.length > 0"
            class="inline-flex items-center rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-400"
          >
            {{ data.unmatchedSales.length }}
          </span>
        </div>
        <span class="text-xs text-slate-500">{{ t('profitTracker.unmatched.salesInfo') }}</span>
      </div>

      <div v-if="data.unmatchedSales.length > 0" class="divide-y divide-slate-800/50">
        <div
          v-for="sale in data.unmatchedSales"
          :key="sale.transactionId"
          class="px-6 py-4 hover:bg-slate-800/30 transition-colors"
        >
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-3">
              <img
                :src="`https://images.evetech.net/types/${sale.typeId}/icon?size=32`"
                :alt="sale.typeName"
                class="w-8 h-8 rounded border border-slate-700"
                loading="lazy"
              />
              <div>
                <div class="flex items-center gap-2">
                  <span class="font-medium text-slate-200 text-sm">{{ sale.typeName }}</span>
                  <OpenInGameButton type="market" :target-id="sale.typeId" />
                </div>
                <div class="text-xs text-slate-500">
                  Tx #{{ sale.transactionId }} &middot; {{ formatNumber(sale.quantity, 0) }} units @ {{ formatIsk(sale.unitPrice) }}
                </div>
              </div>
            </div>
            <span class="inline-flex items-center rounded-full bg-indigo-500/15 px-2 py-0.5 text-[10px] font-medium text-indigo-400">
              No job
            </span>
          </div>
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-4 text-xs text-slate-500">
              <span>{{ formatDate(sale.date) }}</span>
              <span>{{ t('profitTracker.table.revenue') }}: <span class="font-mono text-slate-400">{{ formatIsk(sale.quantity * sale.unitPrice) }}</span></span>
            </div>
            <button
              @click.stop="handleIgnoreSale(sale.transactionId)"
              class="px-3 py-1 bg-slate-700/50 hover:bg-slate-600/50 rounded-full text-xs font-medium text-slate-400 hover:text-slate-300 transition-colors shrink-0"
            >
              {{ t('profitTracker.unmatched.ignore') }}
            </button>
          </div>
        </div>
      </div>

      <div v-else class="px-6 py-12 text-center">
        <svg class="w-10 h-10 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm text-slate-500">{{ t('profitTracker.unmatched.allSalesMatched') }}</p>
      </div>

      <!-- Info hint -->
      <div v-if="data.unmatchedSales.length > 0" class="px-6 py-6 text-center border-t border-slate-800/50">
        <svg class="w-8 h-8 text-slate-700 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-xs text-slate-500">{{ t('profitTracker.unmatched.hint') }}</p>
      </div>
    </div>
  </div>
</template>
