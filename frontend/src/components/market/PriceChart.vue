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
  source?: 'jita' | 'structure'
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
  return value.toFixed(2)
}

const isStructure = computed(() => props.source === 'structure')

const chartData = computed(() => {
  const labels = props.data.map(d => {
    const date = new Date(d.date)
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
  })

  if (isStructure.value) {
    return {
      labels,
      datasets: [
        {
          label: 'Sell Price',
          data: props.data.map(d => d.sellMin ?? null),
          borderColor: '#22d3ee',
          backgroundColor: 'rgba(34, 211, 238, 0.05)',
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 4,
          pointHoverBackgroundColor: '#22d3ee',
          tension: 0.3,
          fill: '+1',
        },
        {
          label: 'Buy Price',
          data: props.data.map(d => d.buyMax ?? null),
          borderColor: '#f59e0b',
          backgroundColor: 'transparent',
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 4,
          pointHoverBackgroundColor: '#f59e0b',
          tension: 0.3,
          fill: false,
        },
      ],
    }
  }

  return {
    labels,
    datasets: [
      {
        label: 'Sell Price',
        data: props.data.map(d => d.highest),
        borderColor: '#22d3ee',
        backgroundColor: 'rgba(34, 211, 238, 0.05)',
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBackgroundColor: '#22d3ee',
        tension: 0.3,
        fill: '+1',
      },
      {
        label: 'Buy Price',
        data: props.data.map(d => d.lowest),
        borderColor: '#f59e0b',
        backgroundColor: 'transparent',
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBackgroundColor: '#f59e0b',
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
      display: false,
    },
    tooltip: {
      backgroundColor: 'rgba(15, 23, 42, 0.95)',
      borderColor: 'rgba(6, 182, 212, 0.3)',
      borderWidth: 1,
      titleColor: '#94a3b8',
      bodyColor: '#e2e8f0',
      bodyFont: { family: 'JetBrains Mono', size: 12 },
      padding: 12,
      displayColors: true,
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
      grid: { color: 'rgba(51, 65, 85, 0.3)' },
      ticks: {
        color: '#475569',
        font: { family: 'JetBrains Mono', size: 10 },
        maxTicksLimit: 8,
        maxRotation: 0,
      },
      border: { display: false },
    },
    y: {
      grid: { color: 'rgba(51, 65, 85, 0.3)' },
      ticks: {
        color: '#475569',
        font: { family: 'JetBrains Mono', size: 10 },
        callback: (value: string | number) => formatPrice(Number(value)),
      },
      border: { display: false },
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
        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors border"
        :class="selectedPeriod === period.days
          ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30'
          : 'bg-slate-800/50 text-slate-400 border-slate-700/50 hover:border-cyan-500/30 hover:text-cyan-400'"
      >
        {{ t(period.label) }}
      </button>
    </div>

    <!-- Structure history accumulating banner -->
    <div
      v-if="isStructure && data.length < 3 && data.length > 0"
      class="mb-3 px-3 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-lg"
    >
      <p class="text-xs text-indigo-400">
        {{ t('market.detail.structureHistoryAccumulating') }}
      </p>
    </div>

    <!-- Chart -->
    <div style="height: 280px;">
      <Line
        v-if="data.length > 0"
        :data="chartData"
        :options="chartOptions"
      />
      <div v-else class="flex items-center justify-center h-full">
        <p class="text-sm text-slate-500">{{ t('market.search.noResults') }}</p>
      </div>
    </div>

    <!-- Custom Legend -->
    <div class="flex items-center gap-6 mt-3 ml-2">
      <span class="flex items-center gap-2 text-xs text-slate-500">
        <span class="inline-block w-3 h-0.5 bg-cyan-400 rounded-full"></span>
        {{ t('market.detail.sellPrice') }}
      </span>
      <span class="flex items-center gap-2 text-xs text-slate-500">
        <span class="inline-block w-3 h-0.5 bg-amber-400 rounded-full"></span>
        {{ t('market.detail.buyPrice') }}
      </span>
      <span class="flex items-center gap-2 text-xs text-slate-500">
        <span class="inline-block w-3 rounded-full bg-cyan-400/20" style="height: 8px"></span>
        {{ t('market.detail.spreadArea') }}
      </span>
    </div>
  </div>
</template>
