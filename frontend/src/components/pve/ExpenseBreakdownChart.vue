<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS,
  ArcElement,
  Tooltip,
  Legend,
  type TooltipItem,
} from 'chart.js'
import { CHART_COLORS, CHART_SERIES } from '@/utils/chartColors'

ChartJS.register(ArcElement, Tooltip, Legend)

const { t } = useI18n()

const props = defineProps<{
  data: Record<string, number>
}>()

const typeLabels = computed<Record<string, string>>(() => ({
  fuel: 'Fuel',
  ammo: t('pve.expenseTypes.ammo'),
  crab_beacon: 'CRAB Beacons',
  other: t('pve.expenseTypes.other'),
}))

const typeColors: Record<string, string> = {
  fuel: CHART_COLORS.danger,
  ammo: CHART_COLORS.warning,
  crab_beacon: CHART_SERIES[2],
  other: CHART_COLORS.neutral,
}

const chartData = computed(() => {
  const labels: string[] = []
  const values: number[] = []
  const colors: string[] = []

  Object.entries(props.data).forEach(([type, amount]) => {
    if (amount > 0) {
      labels.push(typeLabels.value[type] || type)
      values.push(amount / 1_000_000_000)
      colors.push(typeColors[type] || CHART_COLORS.neutral)
    }
  })

  return {
    labels,
    datasets: [
      {
        data: values,
        backgroundColor: colors,
        borderColor: CHART_COLORS.surface,
        borderWidth: 2,
      },
    ],
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'right' as const,
      labels: {
        color: CHART_COLORS.muted,
        padding: 15,
      },
    },
    tooltip: {
      callbacks: {
        label: (context: TooltipItem<'doughnut'>) => {
          const data = context.dataset.data as number[]
          const total = data.reduce((a, b) => a + b, 0)
          const value = context.raw as number
          const percentage = ((value / total) * 100).toFixed(1)
          return `${context.label}: ${value.toFixed(2)}B ISK (${percentage}%)`
        },
      },
    },
  },
}

const hasData = computed(() => Object.values(props.data).some((v) => v > 0))
</script>

<template>
  <div class="bg-gray-800 rounded-lg p-4">
    <h3 class="text-lg font-medium text-white mb-4">{{ t('pve.charts.expenseBreakdown') }}</h3>
    <div v-if="hasData" class="h-64">
      <Doughnut :data="chartData" :options="chartOptions" />
    </div>
    <div v-else class="h-64 flex items-center justify-center text-gray-500">
      {{ t('pve.noExpensesRecorded') }}
    </div>
  </div>
</template>
