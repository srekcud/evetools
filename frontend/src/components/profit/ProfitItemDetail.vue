<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js'
import { useFormatters } from '@/composables/useFormatters'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import type { ProfitItemDetail as ItemDetailType } from '@/stores/profitTracker'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend
)

const props = defineProps<{
  detail: ItemDetailType
}>()

const emit = defineEmits<{
  back: []
}>()

const { t } = useI18n()
const { formatIsk, formatDate, formatDateTime, formatNumber } = useFormatters()

function profitColor(value: number): string {
  return value >= 0 ? 'text-emerald-400' : 'text-red-400'
}

// Cost breakdown percentages
const costSegments = computed(() => {
  const { materialCost, jobInstallCost, taxAmount, totalCost } = props.detail.costBreakdown
  if (totalCost === 0) return []

  return [
    { label: t('profitTracker.table.materialCost'), value: materialCost, percent: (materialCost / totalCost) * 100, color: 'bg-cyan-600' },
    { label: t('profitTracker.table.jobCost'), value: jobInstallCost, percent: (jobInstallCost / totalCost) * 100, color: 'bg-indigo-500' },
    { label: t('profitTracker.table.tax'), value: taxAmount, percent: (taxAmount / totalCost) * 100, color: 'bg-amber-500' },
  ]
})

// Margin trend chart data
const trendChartData = computed(() => ({
  labels: props.detail.marginTrend.map((d) => {
    const date = new Date(d.date)
    return date.toLocaleDateString('en-US', { day: '2-digit', month: '2-digit' })
  }),
  datasets: [
    {
      label: 'Profit',
      data: props.detail.marginTrend.map((d) => d.profit / 1_000_000),
      backgroundColor: props.detail.marginTrend.map((d) =>
        d.profit >= 0 ? 'rgba(52, 211, 153, 0.7)' : 'rgba(248, 113, 113, 0.7)'
      ),
      borderColor: props.detail.marginTrend.map((d) =>
        d.profit >= 0 ? '#34d399' : '#f87171'
      ),
      borderWidth: 1,
      borderRadius: 4,
    },
  ],
}))

const trendChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (context: { raw: unknown }) => {
          const value = context.raw as number
          const sign = value >= 0 ? '+' : ''
          return `Profit: ${sign}${value.toFixed(1)}M ISK`
        },
      },
    },
  },
  scales: {
    x: {
      ticks: { color: '#64748b', font: { size: 10 } },
      grid: { display: false },
    },
    y: {
      ticks: {
        color: '#64748b',
        font: { size: 10 },
        callback: (value: string | number) => `${value}M`,
      },
      grid: { color: 'rgba(51, 65, 85, 0.3)' },
    },
  },
}

// Trend stats
const trendStats = computed(() => {
  const margins = props.detail.marginTrend.map(d => d.marginPercent)
  if (margins.length === 0) return { best: 0, worst: 0, avg: 0 }
  return {
    best: Math.max(...margins),
    worst: Math.min(...margins),
    avg: margins.reduce((a, b) => a + b, 0) / margins.length,
  }
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-4">
      <button
        @click="emit('back')"
        class="p-2 rounded-lg bg-slate-800 border border-slate-700 hover:bg-slate-700 transition-colors"
      >
        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
      </button>
      <div class="flex items-center gap-4">
        <img
          :src="`https://images.evetech.net/types/${detail.productTypeId}/icon?size=64`"
          :alt="detail.typeName"
          class="w-12 h-12 rounded-lg border border-slate-700"
        />
        <div>
          <div class="flex items-center gap-2">
            <h2 class="text-xl font-semibold text-slate-100">{{ detail.typeName }}</h2>
            <OpenInGameButton type="market" :target-id="detail.productTypeId" />
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Cost Breakdown -->
      <div class="lg:col-span-2 bg-slate-900 rounded-xl border border-slate-800 p-6">
        <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-4">{{ t('profitTracker.detail.costBreakdown') }}</h3>

        <!-- Stacked bar -->
        <div class="mb-6" v-if="costSegments.length > 0">
          <div class="flex rounded-lg overflow-hidden h-8">
            <div
              v-for="seg in costSegments"
              :key="seg.label"
              :class="seg.color"
              class="flex items-center justify-center text-[10px] font-medium text-white transition-all duration-500"
              :style="{ width: seg.percent + '%' }"
              :title="`${seg.label}: ${seg.percent.toFixed(1)}%`"
            >
              <span v-if="seg.percent > 8">{{ seg.percent.toFixed(1) }}%</span>
            </div>
          </div>
        </div>

        <!-- Cost details -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div
            v-for="seg in costSegments"
            :key="seg.label"
            class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg"
          >
            <div class="flex items-center gap-2">
              <span class="w-2.5 h-2.5 rounded-sm" :class="seg.color"></span>
              <span class="text-sm text-slate-400">{{ seg.label }}</span>
            </div>
            <span class="font-mono text-sm text-slate-200">{{ formatIsk(seg.value) }}</span>
          </div>
          <div class="flex items-center justify-between p-3 bg-emerald-500/10 rounded-lg border border-emerald-500/20">
            <span class="text-sm font-medium text-slate-300">{{ t('profitTracker.table.totalCost') }}</span>
            <span class="font-mono text-sm font-semibold text-slate-100">{{ formatIsk(detail.costBreakdown.totalCost) }}</span>
          </div>
        </div>
      </div>

      <!-- Margin Trend Mini Chart -->
      <div class="bg-slate-900 rounded-xl border border-slate-800 p-6">
        <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-4">{{ t('profitTracker.detail.marginTrend') }}</h3>

        <div v-if="detail.marginTrend.length > 0" class="h-32">
          <Bar :data="trendChartData" :options="trendChartOptions" />
        </div>
        <div v-else class="h-32 flex items-center justify-center">
          <p class="text-sm text-slate-500">{{ t('common.status.empty') }}</p>
        </div>

        <div class="mt-4 space-y-2" v-if="detail.marginTrend.length > 0">
          <div class="flex justify-between text-xs">
            <span class="text-slate-500">Best margin</span>
            <span class="text-emerald-400 font-mono">{{ trendStats.best.toFixed(1) }}%</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-slate-500">Worst margin</span>
            <span class="font-mono" :class="trendStats.worst >= 0 ? 'text-amber-400' : 'text-red-400'">{{ trendStats.worst.toFixed(1) }}%</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-slate-500">Avg margin</span>
            <span class="text-slate-300 font-mono">{{ trendStats.avg.toFixed(1) }}%</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Sales History -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-800">
        <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">{{ t('profitTracker.detail.salesHistory') }}</h3>
      </div>

      <div v-if="detail.matches.length > 0" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-800">
              <th class="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.table.lastSale') }}</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.table.qtySold') }}</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.detail.unitPrice') }}</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.table.revenue') }}</th>
              <th class="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Source</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(match, index) in detail.matches"
              :key="'sale-' + match.id"
              class="border-b border-slate-800/50"
              :class="index % 2 === 0 ? 'bg-slate-900' : 'bg-slate-800/30'"
            >
              <td class="px-4 py-3 font-mono text-slate-400">{{ formatDateTime(match.matchedAt) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-300">{{ formatNumber(match.quantitySold, 0) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-300">
                {{ match.quantitySold > 0 ? formatIsk(match.revenue / match.quantitySold) : '---' }}
              </td>
              <td class="px-4 py-3 text-right font-mono text-slate-200">{{ formatIsk(match.revenue) }}</td>
              <td class="px-4 py-3">
                <span class="text-xs text-slate-500 capitalize">{{ match.costSource }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="px-6 py-8 text-center">
        <p class="text-sm text-slate-500">{{ t('common.status.empty') }}</p>
      </div>
    </div>

    <!-- Matches Table -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
      <div class="px-6 py-4 border-b border-slate-800">
        <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">{{ t('profitTracker.detail.matches') }}</h3>
      </div>

      <div v-if="detail.matches.length > 0" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-800">
              <th class="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">ID</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Runs</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.table.qtySold') }}</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.table.materialCost') }}</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.table.jobCost') }}</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.table.revenue') }}</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">{{ t('profitTracker.table.profit') }}</th>
              <th class="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase">Source</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase">Date</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(match, index) in detail.matches"
              :key="match.id"
              class="border-b border-slate-800/50"
              :class="index % 2 === 0 ? 'bg-slate-900' : 'bg-slate-800/30'"
            >
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded bg-cyan-500/15 px-2 py-0.5 text-[10px] font-mono text-cyan-400">
                  {{ match.id.slice(0, 8) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right font-mono text-slate-300">{{ match.jobRuns }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-300">{{ match.quantitySold }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-400">{{ formatIsk(match.materialCost) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-400">{{ formatIsk(match.jobInstallCost) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-200">{{ formatIsk(match.revenue) }}</td>
              <td class="px-4 py-3 text-right font-mono font-medium" :class="profitColor(match.profit)">
                {{ match.profit >= 0 ? '+' : '' }}{{ formatIsk(match.profit) }}
              </td>
              <td class="px-4 py-3">
                <span class="text-xs text-slate-500 capitalize">{{ match.costSource }}</span>
              </td>
              <td class="px-4 py-3 text-right text-xs text-slate-500">{{ formatDate(match.matchedAt) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="px-6 py-8 text-center">
        <p class="text-sm text-slate-500">{{ t('common.status.empty') }}</p>
      </div>
    </div>
  </div>
</template>
