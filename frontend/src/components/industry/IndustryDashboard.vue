<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useSlotsStore } from '@/stores/industry/slots'
import { useStockpileStore } from '@/stores/industry/stockpile'
import { useFormatters } from '@/composables/useFormatters'
import { useIndustryJobsSync } from '@/composables/useIndustryJobsSync'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import { useUpdateFlash } from '@/composables/useUpdateFlash'

const { t } = useI18n()
const store = useIndustryStore()
const groupProjectStore = useGroupProjectStore()
const slotsStore = useSlotsStore()
const kpisUpdated = useUpdateFlash(() => slotsStore.data)
const stockpileStore = useStockpileStore()
const { formatIsk } = useFormatters()

useIndustryJobsSync(() => slotsStore.fetchSlots())

onMounted(async () => {
  await Promise.all([
    groupProjectStore.fetchMyProjects(),
    slotsStore.fetchSlots(),
    stockpileStore.fetchStockpileStatus(),
  ])
})

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

const slotsAvailable = computed(() => totalSlotsMax.value - totalSlotsUsed.value)

const allStockpileItems = computed(() => {
  if (!stockpileStore.stockpileStatus) return []
  return Object.values(stockpileStore.stockpileStatus.stages).flatMap(s => s.items)
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
        cost: item.deficitCost === null ? t('industry.inventionUnknown.label') : `${formatIsk(item.deficitCost)} ISK`,
      }),
      action: navigateToStockpile,
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

function navigateToScanner(): void {
  store.navigationIntent = { target: 'batch' }
}

function navigateToStockpile(): void {
  store.navigationIntent = { target: 'stockpile' }
}
</script>

<template>
  <ErrorBanner v-if="groupProjectStore.error" :message="groupProjectStore.error" class="mb-6" />

  <!-- Alerts Section -->
  <div class="relative bg-slate-900 rounded-xl border border-slate-800 mb-6" :class="{ 'hud-updated': kpisUpdated }">
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
        role="button"
        tabindex="0"
        @click="alert.action?.()"
        @keydown.enter="alert.action?.()"
        @keydown.space.prevent="alert.action?.()"
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
        <svg class="w-4 h-4 text-slate-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

  <div class="flex justify-end">
    <router-link
      :to="{ name: 'industry-projects' }"
      class="text-sm text-cyan-400 hover:text-cyan-300 font-medium flex items-center gap-1 transition-colors"
    >
      {{ t('industry.dashboard.viewAllProjects') }}
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
      </svg>
    </router-link>
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
  background: var(--color-warning);
  box-shadow: 0 0 8px rgba(245, 158, 11, 0.3);
}
.alert-red::before {
  background: var(--color-danger);
  box-shadow: 0 0 8px rgba(239, 68, 68, 0.3);
}
.alert-orange::before {
  background: var(--color-chart-9);
  box-shadow: 0 0 8px rgba(249, 115, 22, 0.3);
}
.alert-blue::before {
  background: var(--color-chart-6);
  box-shadow: 0 0 8px rgba(59, 130, 246, 0.3);
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
