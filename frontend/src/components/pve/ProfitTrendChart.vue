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
  type TooltipItem,
} from 'chart.js'
import type { DailyStats } from '@/stores/pve'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend
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
      label: 'Profit',
      data: props.data.map((d) => d.profit / 1_000_000_000),
      backgroundColor: props.data.map((d) =>
        d.profit >= 0 ? 'rgba(16, 185, 129, 0.7)' : 'rgba(239, 68, 68, 0.7)'
      ),
      borderColor: props.data.map((d) =>
        d.profit >= 0 ? '#10b981' : '#ef4444'
      ),
      borderWidth: 1,
    },
  ],
}))

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: false,
    },
    tooltip: {
      callbacks: {
        label: (context: TooltipItem<'bar'>) => {
          const value = context.raw as number
          const sign = value >= 0 ? '+' : ''
          return `Profit: ${sign}${value.toFixed(2)}B ISK`
        },
      },
    },
  },
  scales: {
    x: {
      ticks: {
        color: '#9ca3af',
      },
      grid: {
        display: false,
      },
    },
    y: {
      ticks: {
        color: '#9ca3af',
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
    <h3 class="text-lg font-medium text-white mb-4">{{ t('pve.charts.dailyProfit') }}</h3>
    <div class="h-64">
      <Bar :data="chartData" :options="chartOptions" />
    </div>
  </div>
</template>
