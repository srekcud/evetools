<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'
import type { HistoryEntry } from '@/stores/market'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
)

const props = defineProps<{
  data: HistoryEntry[]
  selectedPeriod: number
}>()

const emit = defineEmits<{
  'update:selectedPeriod': [days: number]
}>()

const { t } = useI18n()

const periods = [
  { days: 30, label: 'market.period.30d' },
  { days: 90, label: 'market.period.90d' },
  { days: 365, label: 'market.period.365d' },
]

function formatPrice(value: number): string {
  if (value >= 1_000_000_000) return (value / 1_000_000_000).toFixed(2) + 'B'
  if (value >= 1_000_000) return (value / 1_000_000).toFixed(2) + 'M'
  if (value >= 1_000) return (value / 1_000).toFixed(1) + 'K'
  return value.toFixed(0)
}

const chartData = computed(() => {
  const labels = props.data.map(d => {
    const date = new Date(d.date)
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
  })

  return {
    labels,
    datasets: [
      {
        label: 'Highest (Sell)',
        data: props.data.map(d => d.highest),
        borderColor: '#22d3ee',
        backgroundColor: 'rgba(34, 211, 238, 0.05)',
        borderWidth: 1.5,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0.3,
        fill: '+1',
      },
      {
        label: 'Lowest (Buy)',
        data: props.data.map(d => d.lowest),
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.05)',
        borderWidth: 1.5,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0.3,
        fill: false,
      },
      {
        label: 'Average',
        data: props.data.map(d => d.average),
        borderColor: 'rgba(148, 163, 184, 0.8)',
        backgroundColor: 'transparent',
        borderWidth: 1,
        borderDash: [4, 4],
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0.3,
        fill: false,
      },
    ],
  }
})

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    mode: 'index' as const,
    intersect: false,
  },
  plugins: {
    legend: {
      display: true,
      position: 'top' as const,
      labels: {
        color: '#94a3b8',
        usePointStyle: true,
        pointStyle: 'line' as const,
        padding: 16,
        font: { size: 11 },
      },
    },
    tooltip: {
      backgroundColor: 'rgba(15, 23, 42, 0.95)',
      borderColor: 'rgba(6, 182, 212, 0.3)',
      borderWidth: 1,
      titleColor: '#e2e8f0',
      bodyColor: '#94a3b8',
      padding: 10,
      callbacks: {
        label: (context: { dataset: { label?: string }; raw: unknown }) => {
          const value = context.raw as number
          return `${context.dataset.label}: ${formatPrice(value)} ISK`
        },
      },
    },
  },
  scales: {
    x: {
      ticks: {
        color: '#64748b',
        maxTicksLimit: 12,
        font: { size: 10 },
      },
      grid: {
        color: 'rgba(51, 65, 85, 0.3)',
      },
    },
    y: {
      ticks: {
        color: '#64748b',
        callback: (value: string | number) => formatPrice(Number(value)),
        font: { size: 10 },
      },
      grid: {
        color: 'rgba(51, 65, 85, 0.3)',
      },
    },
  },
}))
</script>

<template>
  <div>
    <!-- Period toggle -->
    <div class="flex items-center gap-1 mb-3">
      <button
        v-for="period in periods"
        :key="period.days"
        @click="emit('update:selectedPeriod', period.days)"
        class="px-3 py-1 rounded-md text-xs font-medium transition-colors"
        :class="selectedPeriod === period.days
          ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30'
          : 'text-slate-500 hover:text-slate-300 border border-transparent'"
      >
        {{ t(period.label) }}
      </button>
    </div>

    <!-- Chart -->
    <div class="h-64 md:h-72">
      <Line
        v-if="data.length > 0"
        :data="chartData"
        :options="chartOptions"
      />
      <div v-else class="flex items-center justify-center h-full">
        <p class="text-sm text-slate-500">{{ t('market.search.noResults') }}</p>
      </div>
    </div>
  </div>
</template>
