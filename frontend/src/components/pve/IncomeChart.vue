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
  type TooltipItem,
} from 'chart.js'
import type { DailyStats } from '@/stores/pve'
import { CHART_COLORS } from '@/utils/chartColors'

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

const { t } = useI18n()

const props = defineProps<{
  data: DailyStats[]
}>()

const chartData = computed(() => ({
  labels: props.data.map((d) => {
    const date = new Date(d.date)
    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
  }),
  datasets: [
    {
      label: t('pve.charts.totalIncome'),
      data: props.data.map((d) => d.income / 1_000_000_000),
      borderColor: CHART_COLORS.brand,
      backgroundColor: 'rgba(6, 182, 212, 0.1)',
      fill: true,
      tension: 0.3,
    },
    {
      label: 'Bounties',
      data: props.data.map((d) => d.bounties / 1_000_000_000),
      borderColor: CHART_COLORS.profit,
      backgroundColor: 'transparent',
      tension: 0.3,
    },
    {
      label: t('pve.charts.lootSales'),
      data: props.data.map((d) => d.lootSales / 1_000_000_000),
      borderColor: CHART_COLORS.warning,
      backgroundColor: 'transparent',
      tension: 0.3,
    },
  ],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'top' as const,
      labels: {
        color: CHART_COLORS.muted,
      },
    },
    tooltip: {
      callbacks: {
        label: (context: TooltipItem<'line'>) => {
          return `${context.dataset.label}: ${(context.raw as number).toFixed(2)}B ISK`
        },
      },
    },
  },
  scales: {
    x: {
      ticks: {
        color: CHART_COLORS.muted,
      },
      grid: {
        color: 'rgba(75, 85, 99, 0.3)',
      },
    },
    y: {
      ticks: {
        color: CHART_COLORS.muted,
        callback: (value: string | number) => `${value}B`,
      },
      grid: {
        color: 'rgba(75, 85, 99, 0.3)',
      },
    },
  },
}
</script>

<template>
  <div class="bg-gray-800 rounded-lg p-4">
    <h3 class="text-lg font-medium text-white mb-4">{{ t('pve.charts.incomeOverTime') }}</h3>
    <div class="h-64">
      <Line :data="chartData" :options="chartOptions" />
    </div>
  </div>
</template>
