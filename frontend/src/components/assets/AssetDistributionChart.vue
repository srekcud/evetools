<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS,
  ArcElement,
  Tooltip,
  Legend,
} from 'chart.js'

ChartJS.register(ArcElement, Tooltip, Legend)

type LocationGroupSummary = {
  locationName: string
  totalQuantity: number
}

const props = defineProps<{
  locationGroups: LocationGroupSummary[]
}>()

const { t } = useI18n()

const COLORS = [
  '#22d3ee', // cyan-400
  '#f59e0b', // amber-500
  '#a78bfa', // violet-400
  '#34d399', // emerald-400
  '#f87171', // red-400
  '#60a5fa', // blue-400
  '#fbbf24', // amber-400
  '#818cf8', // indigo-400
  '#fb923c', // orange-400
  '#2dd4bf', // teal-400
]

const TOP_N = 8

const chartData = computed(() => {
  if (props.locationGroups.length === 0) return null

  const sorted = [...props.locationGroups].sort((a, b) => b.totalQuantity - a.totalQuantity)

  let labels: string[]
  let data: number[]
  let colors: string[]

  if (sorted.length <= TOP_N) {
    labels = sorted.map(g => g.locationName)
    data = sorted.map(g => g.totalQuantity)
    colors = sorted.map((_, i) => COLORS[i % COLORS.length])
  } else {
    const top = sorted.slice(0, TOP_N)
    const rest = sorted.slice(TOP_N)
    const otherTotal = rest.reduce((sum, g) => sum + g.totalQuantity, 0)

    labels = [...top.map(g => g.locationName), t('assets.chart.other')]
    data = [...top.map(g => g.totalQuantity), otherTotal]
    colors = [...top.map((_, i) => COLORS[i % COLORS.length]), '#475569']
  }

  return {
    labels,
    datasets: [{
      data,
      backgroundColor: colors.map(c => c + 'CC'),
      borderColor: colors,
      borderWidth: 1,
      hoverOffset: 4,
    }],
  }
})

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  cutout: '55%',
  plugins: {
    legend: {
      display: true,
      position: 'right' as const,
      labels: {
        color: '#94a3b8',
        font: { size: 11 },
        padding: 12,
        boxWidth: 12,
        boxHeight: 12,
        usePointStyle: true,
        pointStyle: 'rectRounded' as const,
      },
    },
    tooltip: {
      backgroundColor: 'rgba(15, 23, 42, 0.96)',
      borderColor: 'rgba(6, 182, 212, 0.25)',
      borderWidth: 1,
      titleColor: '#e2e8f0',
      bodyColor: '#94a3b8',
      bodyFont: { family: 'JetBrains Mono', size: 12 },
      padding: 12,
      cornerRadius: 8,
      callbacks: {
        label: (context: { raw: unknown; dataset: { data: number[] } }) => {
          const value = context.raw as number
          const total = context.dataset.data.reduce((a: number, b: number) => a + b, 0)
          const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0'
          return `  ${value.toLocaleString()} items (${pct}%)`
        },
      },
    },
  },
}))
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
    <h3 class="text-sm font-semibold text-white mb-3">{{ t('assets.chart.title') }}</h3>
    <div v-if="chartData" style="height: 240px;">
      <Doughnut :data="chartData" :options="chartOptions" />
    </div>
    <div v-else class="h-40 flex items-center justify-center">
      <p class="text-sm text-slate-500">{{ t('assets.chart.noData') }}</p>
    </div>
  </div>
</template>
