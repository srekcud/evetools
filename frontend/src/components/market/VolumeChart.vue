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
import type { HistoryEntry } from '@/stores/market'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend
)

const props = defineProps<{
  data: HistoryEntry[]
}>()

const { t } = useI18n()

function formatVolume(value: number): string {
  if (value >= 1_000_000) return (value / 1_000_000).toFixed(1) + 'M'
  if (value >= 1_000) return (value / 1_000).toFixed(1) + 'K'
  return value.toFixed(0)
}

const chartData = computed(() => {
  const labels = props.data.map(d => {
    const date = new Date(d.date)
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
  })

  // Color bars based on price trend vs previous day
  const colors = props.data.map((d, i) => {
    if (i === 0) return 'rgba(34, 211, 238, 0.6)'
    const prevAvg = props.data[i - 1].average
    return d.average >= prevAvg
      ? 'rgba(16, 185, 129, 0.6)'
      : 'rgba(239, 68, 68, 0.6)'
  })

  const borderColors = props.data.map((d, i) => {
    if (i === 0) return '#22d3ee'
    const prevAvg = props.data[i - 1].average
    return d.average >= prevAvg ? '#10b981' : '#ef4444'
  })

  return {
    labels,
    datasets: [
      {
        label: t('market.detail.volume'),
        data: props.data.map(d => d.volume),
        backgroundColor: colors,
        borderColor: borderColors,
        borderWidth: 1,
      },
    ],
  }
})

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: false,
    },
    tooltip: {
      backgroundColor: 'rgba(15, 23, 42, 0.95)',
      borderColor: 'rgba(6, 182, 212, 0.3)',
      borderWidth: 1,
      titleColor: '#e2e8f0',
      bodyColor: '#94a3b8',
      padding: 10,
      callbacks: {
        label: (context: { raw: unknown }) => {
          const value = context.raw as number
          return `Volume: ${value.toLocaleString()}`
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
        display: false,
      },
    },
    y: {
      ticks: {
        color: '#64748b',
        callback: (value: string | number) => formatVolume(Number(value)),
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
    <div class="h-40 md:h-48">
      <Bar
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
