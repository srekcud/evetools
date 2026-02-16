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

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend
)

export interface TrendDataPoint {
  label: string
  profit: number
}

const props = defineProps<{
  data: TrendDataPoint[]
}>()

const { t } = useI18n()

const chartData = computed(() => ({
  labels: props.data.map(d => d.label),
  datasets: [
    {
      label: t('profitTracker.table.profit'),
      data: props.data.map(d => d.profit / 1_000_000),
      backgroundColor: props.data.map(d =>
        d.profit >= 0 ? 'rgba(52, 211, 153, 0.7)' : 'rgba(248, 113, 113, 0.7)'
      ),
      borderColor: props.data.map(d =>
        d.profit >= 0 ? '#34d399' : '#f87171'
      ),
      borderWidth: 1,
      borderRadius: 4,
    },
  ],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (context: { raw: unknown }) => {
          const value = context.raw as number
          const sign = value >= 0 ? '+' : ''
          return `${sign}${value.toFixed(1)}M ISK`
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
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 p-6">
    <div class="flex items-center justify-between mb-6">
      <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">{{ t('profitTracker.trend.title') }}</h3>
      <div class="flex items-center gap-4 text-xs text-slate-500">
        <span class="flex items-center gap-1.5">
          <span class="w-2.5 h-2.5 rounded-sm bg-emerald-500"></span>
          {{ t('profitTracker.filter.profit') }}
        </span>
        <span class="flex items-center gap-1.5">
          <span class="w-2.5 h-2.5 rounded-sm bg-red-500"></span>
          {{ t('profitTracker.filter.loss') }}
        </span>
      </div>
    </div>

    <div v-if="data.length > 0" class="h-48">
      <Bar :data="chartData" :options="chartOptions" />
    </div>
    <div v-else class="h-48 flex items-center justify-center">
      <p class="text-sm text-slate-500">{{ t('common.status.empty') }}</p>
    </div>
  </div>
</template>
