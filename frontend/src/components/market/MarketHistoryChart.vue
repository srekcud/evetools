<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  Title,
  Tooltip,
  Legend,
  Filler,
  type Plugin,
  type ChartData,
  type ChartOptions,
} from 'chart.js'
import type { HistoryEntry } from '@/stores/market'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
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

function formatVolume(value: number): string {
  if (value >= 1_000_000) return (value / 1_000_000).toFixed(1) + 'M'
  if (value >= 1_000) return (value / 1_000).toFixed(1) + 'K'
  return value.toFixed(0)
}

const crosshairPlugin: Plugin = {
  id: 'crosshair',
  afterDraw(chart) {
    const tooltip = chart.tooltip
    if (tooltip?.getActiveElements().length) {
      const activePoint = tooltip.getActiveElements()[0]
      const x = activePoint.element.x
      const topY = chart.scales.y.top
      const bottomY = chart.scales.y.bottom
      const ctx = chart.ctx
      ctx.save()
      ctx.beginPath()
      ctx.setLineDash([3, 3])
      ctx.lineWidth = 1
      ctx.strokeStyle = 'rgba(148, 163, 184, 0.25)'
      ctx.moveTo(x, topY)
      ctx.lineTo(x, bottomY)
      ctx.stroke()
      ctx.restore()
    }
  },
}

const isStructure = computed(() => props.source === 'structure')

const maxVolume = computed(() => {
  if (props.data.length === 0) return 1
  const getVol = isStructure.value
    ? (d: HistoryEntry) => (d.sellVolume ?? 0) + (d.buyVolume ?? 0)
    : (d: HistoryEntry) => d.volume
  return Math.max(...props.data.map(d => getVol(d)))
})

// Chart.js supports mixed chart types at runtime, but vue-chartjs types are strict.
// We cast to ChartData<'bar'> because the Bar component handles mixed datasets correctly.
const chartData = computed((): ChartData<'bar'> => {
  const labels = props.data.map(d => {
    const date = new Date(d.date)
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
  })

  // Price reference for trend coloring: sellMin for structure, average for Jita
  const getPrice = isStructure.value
    ? (d: HistoryEntry) => d.sellMin ?? d.average
    : (d: HistoryEntry) => d.average

  const getVolume = isStructure.value
    ? (d: HistoryEntry) => (d.sellVolume ?? 0) + (d.buyVolume ?? 0)
    : (d: HistoryEntry) => d.volume

  // Color bars based on price trend vs previous day
  const volumeBackgroundColors = props.data.map((d, i) => {
    if (i === 0) return 'rgba(34, 211, 238, 0.4)'
    const prev = getPrice(props.data[i - 1])
    return getPrice(d) >= prev
      ? 'rgba(16, 185, 129, 0.4)'
      : 'rgba(239, 68, 68, 0.4)'
  })

  const volumeBorderColors = props.data.map((d, i) => {
    if (i === 0) return 'rgba(34, 211, 238, 0.6)'
    const prev = getPrice(props.data[i - 1])
    return getPrice(d) >= prev
      ? 'rgba(16, 185, 129, 0.6)'
      : 'rgba(239, 68, 68, 0.6)'
  })

  const sellData = isStructure.value
    ? props.data.map(d => d.sellMin ?? null)
    : props.data.map(d => d.highest)

  const buyData = isStructure.value
    ? props.data.map(d => d.buyMax ?? null)
    : props.data.map(d => d.lowest)

  return {
    labels,
    datasets: [
      // Volume bars (rendered behind lines due to order: 3)
      {
        label: 'Volume',
        data: props.data.map(d => getVolume(d)),
        backgroundColor: volumeBackgroundColors,
        borderColor: volumeBorderColors,
        borderWidth: 1,
        yAxisID: 'yVolume',
        order: 3,
        barPercentage: 0.85,
        categoryPercentage: 0.9,
        borderRadius: 2,
      },
      // Sell Price line
      {
        type: 'line',
        label: t('market.detail.sellPrice'),
        data: sellData,
        borderColor: '#22d3ee',
        backgroundColor: 'rgba(34, 211, 238, 0.06)',
        borderWidth: 2,
        fill: '+1',
        tension: 0.3,
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBackgroundColor: '#22d3ee',
        yAxisID: 'y',
        order: 1,
      },
      // Buy Price line
      {
        type: 'line',
        label: t('market.detail.buyPrice'),
        data: buyData,
        borderColor: '#f59e0b',
        backgroundColor: 'transparent',
        borderWidth: 2,
        fill: false,
        tension: 0.3,
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBackgroundColor: '#f59e0b',
        yAxisID: 'y',
        order: 1,
      },
      // Average dashed line
      {
        type: 'line',
        label: t('market.detail.averagePrice'),
        data: props.data.map(d => d.average),
        borderColor: 'rgba(148, 163, 184, 0.35)',
        backgroundColor: 'transparent',
        borderWidth: 1,
        borderDash: [4, 4],
        fill: false,
        tension: 0.3,
        pointRadius: 0,
        pointHoverRadius: 3,
        pointHoverBackgroundColor: '#94a3b8',
        yAxisID: 'y',
        order: 2,
      },
    ] as ChartData<'bar'>['datasets'],
  }
})

const chartOptions = computed((): ChartOptions<'bar'> => {
  const days = props.selectedPeriod
  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' as const, intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(15, 23, 42, 0.96)',
        borderColor: 'rgba(6, 182, 212, 0.25)',
        borderWidth: 1,
        titleColor: '#94a3b8',
        titleFont: { family: 'JetBrains Mono', size: 11, weight: 'normal' as const },
        bodyColor: '#e2e8f0',
        bodyFont: { family: 'JetBrains Mono', size: 12 },
        bodySpacing: 6,
        padding: { top: 10, bottom: 10, left: 14, right: 14 },
        cornerRadius: 8,
        displayColors: true,
        boxWidth: 8,
        boxHeight: 8,
        boxPadding: 4,
        callbacks: {
          label: (context: { dataset: { label?: string }; raw: unknown }) => {
            const value = context.raw as number
            if (value == null) return ''
            if (context.dataset.label === 'Volume') {
              return `  Volume:    ${value.toLocaleString()}`
            }
            return `  ${context.dataset.label}:  ${formatPrice(value)} ISK`
          },
        },
      },
    },
    scales: {
      x: {
        grid: { color: 'rgba(51, 65, 85, 0.2)', drawTicks: false },
        ticks: {
          color: '#475569',
          font: { family: 'JetBrains Mono', size: 10 },
          maxTicksLimit: days <= 30 ? 10 : days <= 90 ? 12 : 15,
          maxRotation: 0,
          padding: 8,
        },
        border: { display: false },
      },
      y: {
        position: 'left' as const,
        grid: { color: 'rgba(51, 65, 85, 0.2)', drawTicks: false },
        ticks: {
          color: '#475569',
          font: { family: 'JetBrains Mono', size: 10 },
          callback: (value: string | number) => formatPrice(Number(value)),
          padding: 8,
        },
        border: { display: false },
      },
      yVolume: {
        position: 'right' as const,
        grid: { drawOnChartArea: false, drawTicks: false },
        ticks: {
          color: '#334155',
          font: { family: 'JetBrains Mono', size: 10 },
          callback: (value: string | number) => formatVolume(Number(value)),
          padding: 8,
        },
        border: { display: false },
        // Push volume bars to bottom third of the chart
        max: maxVolume.value * 3.5,
      },
    },
  }
})
</script>

<template>
  <div>
    <!-- Period toggle -->
    <div class="flex items-center justify-between mb-3">
      <div class="flex gap-1">
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
    </div>

    <!-- Structure accumulating banner -->
    <div
      v-if="isStructure && data.length < 3 && data.length > 0"
      class="mb-3 px-3 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-lg"
    >
      <p class="text-xs text-indigo-400">{{ t('market.detail.structureHistoryAccumulating') }}</p>
    </div>

    <!-- Chart -->
    <div style="height: 320px;">
      <Bar
        v-if="data.length > 0"
        :data="chartData"
        :options="chartOptions"
        :plugins="[crosshairPlugin]"
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
        <span class="inline-block w-3 h-0.5 rounded-full border-t-2 border-dashed border-slate-400"></span>
        {{ t('market.detail.averagePrice') }}
      </span>
      <span class="flex items-center gap-2 text-xs text-slate-500">
        <span class="inline-block w-3 rounded-full bg-cyan-400/20" style="height: 8px"></span>
        {{ t('market.detail.spreadArea') }}
      </span>
      <span class="flex items-center gap-2 text-xs text-slate-500">
        <span class="inline-block w-2.5 h-2.5 rounded-sm bg-emerald-500/60"></span>
        {{ t('market.detail.priceUp') }}
      </span>
      <span class="flex items-center gap-2 text-xs text-slate-500">
        <span class="inline-block w-2.5 h-2.5 rounded-sm bg-red-500/60"></span>
        {{ t('market.detail.priceDown') }}
      </span>
    </div>
  </div>
</template>
