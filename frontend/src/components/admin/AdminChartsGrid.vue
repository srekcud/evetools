<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bar, Doughnut, Line } from 'vue-chartjs'
import type { AdminChartData } from '@/stores/admin'

const props = defineProps<{
  charts: AdminChartData | null
}>()

const { t } = useI18n()

const registrationChartData = computed(() => {
  if (!props.charts?.registrations) return null
  return {
    labels: props.charts.registrations.labels,
    datasets: [
      {
        label: t('admin.charts.registrations'),
        data: props.charts.registrations.data,
        backgroundColor: 'rgba(6, 182, 212, 0.5)',
        borderColor: 'rgb(6, 182, 212)',
        borderWidth: 1,
      },
    ],
  }
})

const activityChartData = computed(() => {
  if (!props.charts?.activity) return null
  return {
    labels: props.charts.activity.labels,
    datasets: [
      {
        label: t('admin.charts.logins'),
        data: props.charts.activity.logins,
        borderColor: 'rgb(34, 197, 94)',
        backgroundColor: 'rgba(34, 197, 94, 0.1)',
        tension: 0.3,
        fill: true,
      },
    ],
  }
})

const assetDistributionData = computed(() => {
  if (!props.charts?.assetDistribution) return null
  return {
    labels: props.charts.assetDistribution.labels,
    datasets: [
      {
        data: props.charts.assetDistribution.data,
        backgroundColor: [
          'rgba(6, 182, 212, 0.7)',
          'rgba(245, 158, 11, 0.7)',
        ],
        borderColor: [
          'rgb(6, 182, 212)',
          'rgb(245, 158, 11)',
        ],
        borderWidth: 1,
      },
    ],
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: false,
    },
  },
  scales: {
    x: {
      grid: { color: 'rgba(148, 163, 184, 0.1)' },
      ticks: { color: 'rgb(148, 163, 184)' },
    },
    y: {
      grid: { color: 'rgba(148, 163, 184, 0.1)' },
      ticks: { color: 'rgb(148, 163, 184)' },
    },
  },
}

const doughnutOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom' as const,
      labels: { color: 'rgb(148, 163, 184)' },
    },
  },
}
</script>

<template>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Registrations Chart -->
    <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
      <h3 class="text-lg font-semibold text-slate-200 mb-4">{{ t('admin.charts.registrationsPerWeek') }}</h3>
      <div class="h-48">
        <Bar
          v-if="registrationChartData"
          :data="registrationChartData"
          :options="chartOptions"
        />
      </div>
    </div>

    <!-- Activity Chart -->
    <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
      <h3 class="text-lg font-semibold text-slate-200 mb-4">{{ t('admin.charts.loginsLast7d') }}</h3>
      <div class="h-48">
        <Line
          v-if="activityChartData"
          :data="activityChartData"
          :options="chartOptions"
        />
      </div>
    </div>

    <!-- Asset Distribution Chart -->
    <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
      <h3 class="text-lg font-semibold text-slate-200 mb-4">{{ t('admin.charts.assetDistribution') }}</h3>
      <div class="h-48">
        <Doughnut
          v-if="assetDistributionData"
          :data="assetDistributionData"
          :options="doughnutOptions"
        />
      </div>
    </div>
  </div>
</template>
