<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import { useSlotsStore } from '@/stores/industry/slots'
import { useStockpileStore } from '@/stores/industry/stockpile'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { IndustryProject } from '@/stores/industry/types'

const { t } = useI18n()
const store = useIndustryStore()
const slotsStore = useSlotsStore()
const stockpileStore = useStockpileStore()
const { formatIsk } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const emit = defineEmits<{
  'view-project': [id: string]
}>()

// SVG ring chart constants (48x48 viewport, radius 19)
const RING_RADIUS = 19
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS // ~119.38

onMounted(async () => {
  await Promise.all([
    slotsStore.fetchSlots(),
    stockpileStore.fetchStockpileStatus(),
  ])
})

// --- KPI 1: Active Projects ---
const activeProjects = computed(() =>
  store.projects.filter(p => p.status === 'active'),
)
const activeProjectCount = computed(() => activeProjects.value.length)

// --- KPI 2: Estimated Profit ---
const estimatedProfit = computed(() =>
  activeProjects.value.reduce((sum, p) => sum + (p.profit ?? 0), 0),
)

// --- KPI 3: Slots Used ---
const slotsData = computed(() => slotsStore.data)

const totalSlotsUsed = computed(() => {
  if (!slotsData.value) return 0
  const kpis = slotsData.value.globalKpis
  return kpis.manufacturing.used + kpis.reaction.used + kpis.science.used
})

const totalSlotsMax = computed(() => {
  if (!slotsData.value) return 0
  const kpis = slotsData.value.globalKpis
  return kpis.manufacturing.max + kpis.reaction.max + kpis.science.max
})

const slotsPercent = computed(() => {
  if (totalSlotsMax.value === 0) return 0
  return Math.round((totalSlotsUsed.value / totalSlotsMax.value) * 100)
})

const slotsAvailable = computed(() => totalSlotsMax.value - totalSlotsUsed.value)

const slotsDashOffset = computed(() =>
  RING_CIRCUMFERENCE * (1 - slotsPercent.value / 100),
)

const slotsRingColor = computed(() => {
  if (slotsPercent.value > 80) return '#f87171' // red-400
  if (slotsPercent.value >= 50) return '#fbbf24' // amber-400
  return '#34d399' // emerald-400
})

const slotsTextColor = computed(() => {
  if (slotsPercent.value > 80) return 'text-red-400'
  if (slotsPercent.value >= 50) return 'text-amber-400'
  return 'text-emerald-400'
})

const slotsGlowClass = computed(() => {
  if (slotsPercent.value > 80) return 'ring-glow-red'
  if (slotsPercent.value >= 50) return 'ring-glow-amber'
  return 'ring-glow-emerald'
})

const slotsAvailableColor = computed(() => {
  if (slotsPercent.value > 80) return 'text-red-400/70'
  if (slotsPercent.value >= 50) return 'text-amber-400/70'
  return 'text-emerald-400/70'
})

// --- KPI 4: Pipeline Health ---
const pipelineHealth = computed(() =>
  stockpileStore.stockpileStatus?.kpis.pipelineHealth ?? 0,
)

const allStockpileItems = computed(() => {
  if (!stockpileStore.stockpileStatus) return []
  return Object.values(stockpileStore.stockpileStatus.stages).flatMap(s => s.items)
})

const totalStockpileTargets = computed(() => allStockpileItems.value.length)

const targetsMet = computed(() =>
  allStockpileItems.value.filter(i => i.percent >= 100).length,
)

const hasStockpileTargets = computed(() =>
  (stockpileStore.stockpileStatus?.targetCount ?? 0) > 0,
)

const pipelineDashOffset = computed(() =>
  RING_CIRCUMFERENCE * (1 - pipelineHealth.value / 100),
)

const pipelineRingColor = computed(() => {
  if (pipelineHealth.value >= 80) return '#34d399'
  if (pipelineHealth.value >= 50) return '#fbbf24'
  return '#f87171'
})

const pipelineTextColor = computed(() => {
  if (pipelineHealth.value >= 80) return 'text-emerald-400'
  if (pipelineHealth.value >= 50) return 'text-amber-400'
  return 'text-red-400'
})

const pipelineGlowClass = computed(() => {
  if (pipelineHealth.value >= 80) return 'ring-glow-emerald'
  if (pipelineHealth.value >= 50) return 'ring-glow-amber'
  return 'ring-glow-red'
})

// --- Alerts ---
type DashboardAlert = {
  id: string
  type: 'amber' | 'red' | 'orange' | 'blue'
  icon: 'clock' | 'warning' | 'info-circle' | 'info'
  title: string
  description: string
  action?: () => void
}

const readyJobsCount = computed(() => {
  if (!slotsData.value) return 0
  return slotsData.value.characters
    .flatMap(c => c.jobs)
    .filter(j => j.timeLeftSeconds <= 0).length
})

const criticalStockpileItems = computed(() =>
  allStockpileItems.value.filter(i => i.percent < 25),
)

const negativeProfitProjects = computed(() =>
  activeProjects.value.filter(
    p => p.profitPercent != null && p.profitPercent < 0,
  ),
)

const alerts = computed<DashboardAlert[]>(() => {
  const result: DashboardAlert[] = []

  if (readyJobsCount.value > 0) {
    result.push({
      id: 'jobs-ready',
      type: 'amber',
      icon: 'clock',
      title: t('industry.dashboard.alerts.jobsReadyTitle', { count: readyJobsCount.value }),
      description: t('industry.dashboard.alerts.jobsReadyDesc'),
      action: () => { store.navigationIntent = { target: 'slots' } },
    })
  }

  for (const item of criticalStockpileItems.value.slice(0, 3)) {
    result.push({
      id: `stockpile-${item.typeId}`,
      type: 'red',
      icon: 'warning',
      title: t('industry.dashboard.alerts.stockpileCriticalTitle', { name: item.typeName, percent: item.percent }),
      description: t('industry.dashboard.alerts.stockpileCriticalDesc', {
        stock: item.stock.toLocaleString(),
        target: item.targetQuantity.toLocaleString(),
        cost: formatIsk(item.deficitCost),
      }),
      action: navigateToStockpile,
    })
  }

  for (const project of negativeProfitProjects.value.slice(0, 3)) {
    result.push({
      id: `negative-profit-${project.id}`,
      type: 'orange',
      icon: 'info-circle',
      title: t('industry.dashboard.alerts.negativeProfitTitle', { name: project.displayName }),
      description: t('industry.dashboard.alerts.negativeProfitDesc', {
        margin: project.profitPercent?.toFixed(1) ?? '0',
      }),
      action: () => emit('view-project', project.id),
    })
  }

  if (slotsAvailable.value > 0 && slotsData.value) {
    result.push({
      id: 'free-slots',
      type: 'blue',
      icon: 'info',
      title: t('industry.dashboard.alerts.freeSlotsTitle', { count: slotsAvailable.value }),
      description: t('industry.dashboard.alerts.freeSlotsDesc'),
      action: () => { store.navigationIntent = { target: 'slots' } },
    })
  }

  return result
})

const alertCount = computed(() => alerts.value.length)

// --- Quick Actions ---
const hasCriticalStockpile = computed(() => criticalStockpileItems.value.length > 0)

function navigateToNewProject(): void {
  store.navigationIntent = { target: 'projects', openCreateModal: true }
}

function navigateToProjects(): void {
  store.navigationIntent = { target: 'projects' }
}

function navigateToScanner(): void {
  store.navigationIntent = { target: 'batch' }
}

function navigateToStockpile(): void {
  store.navigationIntent = { target: 'stockpile' }
}

// --- Recent Projects ---
const recentProjects = computed<IndustryProject[]>(() => {
  const sorted = [...store.projects].sort((a, b) => {
    const dateA = new Date(a.createdAt).getTime()
    const dateB = new Date(b.createdAt).getTime()
    return dateB - dateA
  })
  return sorted.slice(0, 5)
})

function projectStatusClass(status: string): string {
  if (status === 'completed') return 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20'
  return 'bg-cyan-500/15 text-cyan-400 border-cyan-500/20'
}

function projectStatusLabel(status: string): string {
  if (status === 'completed') return t('industry.stepStatus.completed')
  return t('industry.stepStatus.active')
}

function projectProfitColor(profit: number | null): string {
  if (profit == null) return 'text-slate-500'
  return profit >= 0 ? 'text-emerald-400' : 'text-red-400'
}

// Approximate progress based on step job matching
function projectProgress(project: IndustryProject): number {
  if (project.status === 'completed') return 100
  const steps = project.steps
  if (!steps || steps.length === 0) return 0
  const completedSteps = steps.filter(
    s => s.purchased || s.inStock || s.esiJobStatus === 'delivered',
  ).length
  return Math.round((completedSteps / steps.length) * 100)
}

function cleanTypeName(name: string): string {
  return name.replace(/\s*\(BPC\)\s*$/i, '').trim()
}
</script>

<template>
  <!-- KPI Cards -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- KPI 1: Active Projects -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-4">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.dashboard.activeProjects') }}</p>
        <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span>
      </div>
      <p class="text-3xl font-bold text-cyan-400 font-mono" style="font-variant-numeric: tabular-nums;">
        {{ activeProjectCount }}
      </p>
    </div>

    <!-- KPI 2: Estimated Profit -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-4">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.dashboard.estimatedProfit') }}</p>
        <svg class="w-4 h-4 text-emerald-500/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
        </svg>
      </div>
      <p
        class="text-3xl font-bold font-mono"
        :class="estimatedProfit >= 0 ? 'text-emerald-400' : 'text-red-400'"
        style="font-variant-numeric: tabular-nums;"
      >
        <template v-if="estimatedProfit !== 0">{{ formatIsk(estimatedProfit) }}</template>
        <template v-else>&mdash;</template>
      </p>
      <p class="text-xs text-slate-600 mt-1">{{ t('industry.dashboard.acrossActiveProjects') }}</p>
    </div>

    <!-- KPI 3: Slots Used -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-4">
      <p class="text-xs text-slate-500 uppercase tracking-wider mb-3">{{ t('industry.dashboard.slotsUsed') }}</p>
      <div v-if="slotsData" class="flex items-center gap-3">
        <div class="relative w-12 h-12 flex-shrink-0" :class="slotsGlowClass">
          <svg viewBox="0 0 48 48" class="w-12 h-12" style="transform: rotate(-90deg)">
            <circle cx="24" cy="24" :r="RING_RADIUS" fill="none" stroke="rgb(30 41 59)" stroke-width="4" />
            <circle
              cx="24" cy="24" :r="RING_RADIUS" fill="none"
              :stroke="slotsRingColor"
              stroke-width="4"
              stroke-linecap="round"
              :stroke-dasharray="RING_CIRCUMFERENCE"
              :stroke-dashoffset="slotsDashOffset"
            />
          </svg>
          <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-[10px] font-mono font-bold" :class="slotsTextColor">{{ slotsPercent }}%</span>
          </div>
        </div>
        <div>
          <p class="text-2xl font-bold text-white font-mono" style="font-variant-numeric: tabular-nums;">
            {{ totalSlotsUsed }}<span class="text-slate-500 text-lg">/{{ totalSlotsMax }}</span>
          </p>
          <p class="text-xs" :class="slotsAvailableColor">
            {{ t('industry.dashboard.slotsAvailable', { count: slotsAvailable }) }}
          </p>
        </div>
      </div>
      <p v-else class="text-2xl font-bold text-slate-500">&mdash;</p>
    </div>

    <!-- KPI 4: Pipeline Health -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-4">
      <p class="text-xs text-slate-500 uppercase tracking-wider mb-3">{{ t('industry.dashboard.pipelineHealth') }}</p>
      <div v-if="hasStockpileTargets" class="flex items-center gap-3">
        <div class="relative w-12 h-12 flex-shrink-0" :class="pipelineGlowClass">
          <svg viewBox="0 0 48 48" class="w-12 h-12" style="transform: rotate(-90deg)">
            <circle cx="24" cy="24" :r="RING_RADIUS" fill="none" stroke="rgb(30 41 59)" stroke-width="4" />
            <circle
              cx="24" cy="24" :r="RING_RADIUS" fill="none"
              :stroke="pipelineRingColor"
              stroke-width="4"
              stroke-linecap="round"
              :stroke-dasharray="RING_CIRCUMFERENCE"
              :stroke-dashoffset="pipelineDashOffset"
            />
          </svg>
          <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-[10px] font-mono font-bold" :class="pipelineTextColor">{{ pipelineHealth }}%</span>
          </div>
        </div>
        <div>
          <p class="text-2xl font-bold font-mono" :class="pipelineTextColor" style="font-variant-numeric: tabular-nums;">
            {{ pipelineHealth }}%
          </p>
          <p class="text-xs text-slate-600">
            {{ t('industry.dashboard.targetsMet', { met: targetsMet, total: totalStockpileTargets }) }}
          </p>
        </div>
      </div>
      <p v-else class="text-2xl font-bold text-slate-500">&mdash;</p>
    </div>
  </div>

  <!-- Alerts Section -->
  <div class="bg-slate-900 rounded-xl border border-slate-800 mb-6">
    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        <h3 class="text-lg font-semibold text-slate-100">{{ t('industry.dashboard.alertsTitle') }}</h3>
        <span v-if="alertCount > 0" class="text-xs px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-400 font-medium">
          {{ alertCount }}
        </span>
      </div>
    </div>

    <!-- No alerts state -->
    <div v-if="alertCount === 0" class="px-6 py-6 flex items-center gap-3 justify-center">
      <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
      </svg>
      <p class="text-sm text-slate-400">{{ t('industry.dashboard.noAlerts') }}</p>
    </div>

    <!-- Alert rows -->
    <div v-else class="divide-y divide-slate-800/50">
      <div
        v-for="alert in alerts"
        :key="alert.id"
        class="alert-row px-6 py-3.5 flex items-center gap-4 cursor-pointer hover:bg-slate-800/50"
        :class="`alert-${alert.type}`"
        @click="alert.action?.()"
      >
        <!-- Icon -->
        <div
          class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
          :class="{
            'bg-amber-500/10': alert.type === 'amber',
            'bg-red-500/10': alert.type === 'red',
            'bg-orange-500/10': alert.type === 'orange',
            'bg-blue-500/10': alert.type === 'blue',
          }"
        >
          <!-- Clock icon -->
          <svg v-if="alert.icon === 'clock'" class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <!-- Warning triangle icon -->
          <svg v-else-if="alert.icon === 'warning'" class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <!-- Info-circle icon -->
          <svg v-else-if="alert.icon === 'info-circle'" class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <!-- Info icon -->
          <svg v-else class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
          <p class="text-sm text-slate-200">{{ alert.title }}</p>
          <p class="text-xs text-slate-500 mt-0.5">{{ alert.description }}</p>
        </div>

        <!-- Chevron -->
        <svg class="w-4 h-4 text-slate-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </div>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="flex flex-wrap gap-3 mb-6">
    <button
      @click="navigateToNewProject"
      class="btn-action px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
      </svg>
      {{ t('industry.dashboard.newProject') }}
    </button>
    <button
      v-if="hasCriticalStockpile"
      @click="navigateToStockpile"
      class="btn-action px-4 py-2.5 bg-amber-600 hover:bg-amber-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
      {{ t('industry.dashboard.restockCritical') }}
    </button>
    <button
      @click="navigateToScanner"
      class="btn-action px-4 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium flex items-center gap-2"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
      {{ t('industry.dashboard.scanProfits') }}
    </button>
    <button
      @click="navigateToStockpile"
      class="btn-action px-4 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium flex items-center gap-2"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
      </svg>
      {{ t('industry.dashboard.viewStockpile') }}
    </button>
  </div>

  <!-- Recent Projects Mini-table -->
  <div class="bg-slate-900 rounded-xl border border-slate-800">
    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
      <h3 class="text-lg font-semibold text-slate-100">{{ t('industry.dashboard.recentProjects') }}</h3>
      <button
        class="text-sm text-cyan-400 hover:text-cyan-300 font-medium flex items-center gap-1 transition-colors"
        @click="navigateToProjects"
      >
        {{ t('industry.dashboard.viewAllProjects') }}
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
        </svg>
      </button>
    </div>

    <!-- Empty state -->
    <div v-if="recentProjects.length === 0" class="px-6 py-8 text-center">
      <p class="text-sm text-slate-500">{{ t('industry.project.noProjectsDescription') }}</p>
    </div>

    <!-- Table -->
    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
            <th class="text-left py-3 px-6">{{ t('industry.table.product') }}</th>
            <th class="text-left py-3 px-3">{{ t('common.status.active') }}</th>
            <th class="text-right py-3 px-3">{{ t('industry.table.profit') }}</th>
            <th class="text-left py-3 px-6 w-48">{{ t('industry.slots.table.progress') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr
            v-for="project in recentProjects"
            :key="project.id"
            class="hover:bg-slate-800/50 cursor-pointer group"
            @click="emit('view-project', project.id)"
          >
            <!-- Product -->
            <td class="py-3 px-6">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded bg-slate-800 border border-slate-700 flex items-center justify-center overflow-hidden">
                  <img
                    :src="getTypeIconUrl(project.productTypeId, 64)"
                    :alt="project.displayName"
                    class="w-full h-full object-cover"
                    @error="onImageError"
                  />
                </div>
                <span class="text-slate-100 font-medium group-hover:text-cyan-400 transition-colors">
                  {{ cleanTypeName(project.displayName) }}
                </span>
              </div>
            </td>

            <!-- Status -->
            <td class="py-3 px-3">
              <span
                class="text-xs px-2 py-0.5 rounded border"
                :class="projectStatusClass(project.status)"
              >
                {{ projectStatusLabel(project.status) }}
              </span>
            </td>

            <!-- Profit -->
            <td
              class="py-3 px-3 text-right font-mono"
              :class="projectProfitColor(project.profit)"
              style="font-variant-numeric: tabular-nums;"
            >
              {{ project.profit != null ? formatIsk(project.profit) : '---' }}
            </td>

            <!-- Progress -->
            <td class="py-3 px-6">
              <div class="flex items-center gap-3">
                <div class="flex-1 h-2 bg-slate-700 rounded-full overflow-hidden">
                  <div
                    class="h-full rounded-full relative"
                    :class="project.status === 'completed' ? 'bg-emerald-500/60' : 'bg-cyan-500/60'"
                    :style="{ width: projectProgress(project) + '%' }"
                  >
                    <div
                      v-if="project.status !== 'completed' && projectProgress(project) > 0"
                      class="absolute inset-0 progress-shimmer rounded-full"
                    ></div>
                  </div>
                </div>
                <span
                  class="text-xs font-mono w-8 text-right"
                  :class="project.status === 'completed' ? 'text-emerald-400' : 'text-slate-500'"
                >
                  {{ projectProgress(project) }}%
                </span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

</template>

<style scoped>
/* Alert row left-border glow */
.alert-row {
  position: relative;
  transition: background-color 0.15s ease;
}
.alert-row::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 3px;
  border-radius: 3px 0 0 3px;
}
.alert-amber::before {
  background: #f59e0b;
  box-shadow: 0 0 8px rgba(245, 158, 11, 0.3);
}
.alert-red::before {
  background: #ef4444;
  box-shadow: 0 0 8px rgba(239, 68, 68, 0.3);
}
.alert-orange::before {
  background: #f97316;
  box-shadow: 0 0 8px rgba(249, 115, 22, 0.3);
}
.alert-blue::before {
  background: #3b82f6;
  box-shadow: 0 0 8px rgba(59, 130, 246, 0.3);
}

/* Ring chart ambient glow */
.ring-glow-amber {
  filter: drop-shadow(0 0 6px rgba(251, 191, 36, 0.15));
}
.ring-glow-emerald {
  filter: drop-shadow(0 0 6px rgba(52, 211, 153, 0.15));
}
.ring-glow-red {
  filter: drop-shadow(0 0 6px rgba(248, 113, 113, 0.15));
}

/* Button micro-interaction */
.btn-action {
  transition: all 0.15s ease;
}
.btn-action:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
.btn-action:active {
  transform: translateY(0);
}

/* Progress bar shimmer */
@keyframes shimmer {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
.progress-shimmer {
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.06), transparent);
  background-size: 200% 100%;
  animation: shimmer 3s ease-in-out infinite;
}
</style>
