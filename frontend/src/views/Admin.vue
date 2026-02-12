<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import { useFormatters } from '@/composables/useFormatters'
import MainLayout from '@/layouts/MainLayout.vue'
import { Bar, Doughnut, Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler
)

const router = useRouter()
const adminStore = useAdminStore()
const { formatIsk, formatTimeSince } = useFormatters()

const hasAccess = ref(false)
const isCheckingAccess = ref(true)
const actionLoading = ref<string | null>(null)
const actionMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)

onMounted(async () => {
  isCheckingAccess.value = true
  hasAccess.value = await adminStore.checkAccess()
  isCheckingAccess.value = false

  if (!hasAccess.value) {
    router.push('/dashboard')
    return
  }

  await adminStore.fetchAll()
})

const stats = computed(() => adminStore.stats)
const queues = computed(() => adminStore.queues)
const charts = computed(() => adminStore.charts)

const registrationChartData = computed(() => {
  if (!charts.value?.registrations) return null
  return {
    labels: charts.value.registrations.labels,
    datasets: [
      {
        label: 'Inscriptions',
        data: charts.value.registrations.data,
        backgroundColor: 'rgba(6, 182, 212, 0.5)',
        borderColor: 'rgb(6, 182, 212)',
        borderWidth: 1,
      },
    ],
  }
})

const activityChartData = computed(() => {
  if (!charts.value?.activity) return null
  return {
    labels: charts.value.activity.labels,
    datasets: [
      {
        label: 'Connexions',
        data: charts.value.activity.logins,
        borderColor: 'rgb(34, 197, 94)',
        backgroundColor: 'rgba(34, 197, 94, 0.1)',
        tension: 0.3,
        fill: true,
      },
    ],
  }
})

const assetDistributionData = computed(() => {
  if (!charts.value?.assetDistribution) return null
  return {
    labels: charts.value.assetDistribution.labels,
    datasets: [
      {
        data: charts.value.assetDistribution.data,
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
      grid: {
        color: 'rgba(148, 163, 184, 0.1)',
      },
      ticks: {
        color: 'rgb(148, 163, 184)',
      },
    },
    y: {
      grid: {
        color: 'rgba(148, 163, 184, 0.1)',
      },
      ticks: {
        color: 'rgb(148, 163, 184)',
      },
    },
  },
}

const doughnutOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom' as const,
      labels: {
        color: 'rgb(148, 163, 184)',
      },
    },
  },
}

function formatNumber(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return n.toLocaleString('fr-FR')
}

async function refreshData() {
  await adminStore.fetchAll()
}

async function executeAction(actionName: string, actionFn: () => Promise<{ success: boolean; message: string }>) {
  actionLoading.value = actionName
  actionMessage.value = null

  try {
    const result = await actionFn()
    actionMessage.value = {
      type: result.success ? 'success' : 'error',
      text: result.message,
    }
    // Refresh data after action
    if (result.success) {
      setTimeout(() => adminStore.fetchAll(), 1000)
    }
  } catch (e) {
    actionMessage.value = {
      type: 'error',
      text: e instanceof Error ? e.message : 'Action failed',
    }
  } finally {
    actionLoading.value = null
    // Clear message after 5 seconds
    setTimeout(() => {
      actionMessage.value = null
    }, 5000)
  }
}
</script>

<template>
  <MainLayout>
    <!-- Loading state -->
    <div v-if="isCheckingAccess || adminStore.isLoading" class="flex justify-center items-center py-20">
      <svg class="animate-spin h-8 w-8 text-cyan-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
    </div>

    <!-- Error state -->
    <div v-else-if="adminStore.error" class="bg-red-500/20 border border-red-500 p-4 rounded-lg">
      <p class="text-red-400">{{ adminStore.error }}</p>
      <button @click="refreshData" class="mt-2 px-4 py-2 bg-red-500/30 hover:bg-red-500/50 rounded text-sm">
        Reessayer
      </button>
    </div>

    <!-- Admin Dashboard -->
    <div v-else-if="hasAccess" class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-100">Administration</h2>
          <p class="text-sm text-slate-500">Statistiques et metriques de la plateforme</p>
        </div>
        <button
          @click="refreshData"
          class="px-4 py-2 bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 rounded-lg text-sm font-medium transition-colors flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Actualiser
        </button>
      </div>

      <!-- Action Message -->
      <Transition name="fade">
        <div
          v-if="actionMessage"
          :class="[
            'p-4 rounded-lg border',
            actionMessage.type === 'success'
              ? 'bg-emerald-500/20 border-emerald-500/50 text-emerald-400'
              : 'bg-red-500/20 border-red-500/50 text-red-400'
          ]"
        >
          {{ actionMessage.text }}
        </div>
      </Transition>

      <!-- KPI Cards - Row 1: Users -->
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        <!-- Total Users -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-cyan-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Utilisateurs</span>
          </div>
          <p class="text-2xl font-bold text-slate-100">{{ formatNumber(stats?.users?.total) }}</p>
        </div>

        <!-- Valid Auth -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Auth valide</span>
          </div>
          <p class="text-2xl font-bold text-emerald-400">{{ formatNumber(stats?.users?.valid) }}</p>
        </div>

        <!-- Invalid Auth -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-red-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Auth invalide</span>
          </div>
          <p class="text-2xl font-bold text-red-400">{{ formatNumber(stats?.users?.invalid) }}</p>
        </div>

        <!-- Active 7d -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-blue-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Actifs 7j</span>
          </div>
          <p class="text-2xl font-bold text-blue-400">{{ formatNumber(stats?.users?.activeLastWeek) }}</p>
        </div>

        <!-- Active 30d -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-purple-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Actifs 30j</span>
          </div>
          <p class="text-2xl font-bold text-purple-400">{{ formatNumber(stats?.users?.activeLastMonth) }}</p>
        </div>
      </div>

      <!-- KPI Cards - Row 2: Characters & Tokens -->
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <!-- Total Characters -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-cyan-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Personnages</span>
          </div>
          <p class="text-2xl font-bold text-slate-100">{{ formatNumber(stats?.characters?.total) }}</p>
        </div>

        <!-- Tokens Total -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Tokens</span>
          </div>
          <p class="text-2xl font-bold text-amber-400">{{ formatNumber(stats?.tokens?.total) }}</p>
        </div>

        <!-- Tokens Healthy -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Tokens OK</span>
          </div>
          <p class="text-2xl font-bold text-emerald-400">{{ formatNumber(stats?.tokens?.healthy) }}</p>
        </div>

        <!-- Tokens Expiring -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-orange-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Expire 24h</span>
          </div>
          <p class="text-2xl font-bold text-orange-400">{{ formatNumber(stats?.tokens?.expiring24h) }}</p>
        </div>

        <!-- Tokens Expired -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-red-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Expires</span>
          </div>
          <p class="text-2xl font-bold text-red-400">{{ formatNumber(stats?.tokens?.expired) }}</p>
        </div>

        <!-- Sync Scope (active chars with tokens) -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-yellow-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Scope sync</span>
          </div>
          <p class="text-2xl font-bold text-yellow-400">{{ formatNumber(stats?.characters?.activeSyncScope) }}</p>
          <p class="text-xs text-slate-500 mt-1">/ {{ formatNumber(stats?.characters?.total) }} chars</p>
        </div>
      </div>

      <!-- KPI Cards - Row 3: Assets, Industry, Queues -->
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <!-- Total Assets -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-amber-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Assets</span>
          </div>
          <p class="text-2xl font-bold text-amber-400">{{ formatNumber(stats?.assets?.totalItems) }}</p>
        </div>

        <!-- Industry Projects -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-indigo-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Projets actifs</span>
          </div>
          <p class="text-2xl font-bold text-indigo-400">{{ formatNumber(stats?.industry?.activeProjects) }}</p>
        </div>

        <!-- Industry Jobs -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-violet-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Jobs ESI</span>
          </div>
          <p class="text-2xl font-bold text-violet-400">{{ formatNumber(stats?.industryJobs?.activeJobs) }}</p>
        </div>

        <!-- Structures -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-teal-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Structures</span>
          </div>
          <p class="text-2xl font-bold text-teal-400">{{ formatNumber(stats?.syncs?.structuresCached) }}</p>
        </div>

        <!-- Queue Async -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-cyan-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Queue</span>
          </div>
          <p class="text-2xl font-bold text-cyan-400">{{ queues?.queues?.async ?? '—' }}</p>
        </div>

        <!-- Queue Failed -->
        <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-red-500/20 flex items-center justify-center">
              <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
            </div>
            <span class="text-xs text-slate-500 uppercase">Failed</span>
          </div>
          <p class="text-2xl font-bold text-red-400">{{ queues?.queues?.failed ?? '—' }}</p>
        </div>
      </div>

      <!-- Sync Status & Actions -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Last Syncs -->
        <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
          <h3 class="text-lg font-semibold text-slate-200 mb-4">Dernieres synchronisations</h3>
          <div class="space-y-2">
            <div class="flex items-center justify-between p-3 bg-slate-900/50 rounded-lg">
              <span class="text-slate-400">Assets</span>
              <span class="text-slate-200 font-mono text-sm">{{ stats?.syncs?.lastAssetSync ? formatTimeSince(stats.syncs.lastAssetSync) : '—' }}</span>
            </div>
            <div class="flex items-center justify-between p-3 bg-slate-900/50 rounded-lg">
              <span class="text-slate-400">Jobs industrie</span>
              <span class="text-slate-200 font-mono text-sm">{{ stats?.syncs?.lastIndustrySync ? formatTimeSince(stats.syncs.lastIndustrySync) : '—' }}</span>
            </div>
            <div class="flex items-center justify-between p-3 bg-slate-900/50 rounded-lg">
              <span class="text-slate-400">Wallet</span>
              <div class="text-right">
                <span class="text-slate-200 font-mono text-sm">{{ stats?.syncs?.lastWalletSync ? formatTimeSince(stats.syncs.lastWalletSync) : '—' }}</span>
                <span class="text-slate-500 text-xs ml-2">({{ formatNumber(stats?.syncs?.walletTransactionCount) }} tx)</span>
              </div>
            </div>
            <div class="flex items-center justify-between p-3 bg-slate-900/50 rounded-lg">
              <span class="text-slate-400">Mining</span>
              <span class="text-slate-200 font-mono text-sm">{{ stats?.syncs?.lastMiningSync ? formatTimeSince(stats.syncs.lastMiningSync) : '—' }}</span>
            </div>
            <div class="flex items-center justify-between p-3 bg-slate-900/50 rounded-lg">
              <span class="text-slate-400">Ansiblex</span>
              <span class="text-slate-200 font-mono text-sm">{{ formatNumber(stats?.syncs?.ansiblexCount) }} gates</span>
            </div>
          </div>
        </div>

        <!-- Admin Actions -->
        <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
          <h3 class="text-lg font-semibold text-slate-200 mb-4">Actions</h3>
          <div class="grid grid-cols-3 gap-3">
            <button
              @click="executeAction('sync-assets', adminStore.syncAssets)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'sync-assets'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              Sync Assets
            </button>

            <button
              @click="executeAction('sync-market', adminStore.syncMarket)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'sync-market'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              Sync Market
            </button>

            <button
              @click="executeAction('sync-industry', adminStore.syncIndustry)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'sync-industry'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
              </svg>
              Sync Industry
            </button>

            <button
              @click="executeAction('sync-pve', adminStore.syncPve)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-green-500/20 hover:bg-green-500/30 text-green-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'sync-pve'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
              </svg>
              Sync PVE
            </button>

            <button
              @click="executeAction('sync-wallet', adminStore.syncWallet)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-teal-500/20 hover:bg-teal-500/30 text-teal-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'sync-wallet'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
              </svg>
              Sync Wallet
            </button>

            <button
              @click="executeAction('sync-mining', adminStore.syncMining)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'sync-mining'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
              </svg>
              Sync Mining
            </button>

            <button
              @click="executeAction('sync-ansiblex', adminStore.syncAnsiblex)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-purple-500/20 hover:bg-purple-500/30 text-purple-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'sync-ansiblex'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
              </svg>
              Sync Ansiblex
            </button>

            <button
              @click="executeAction('retry-failed', adminStore.retryFailed)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-orange-500/20 hover:bg-orange-500/30 text-orange-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'retry-failed'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              Retry Failed
            </button>

            <button
              @click="executeAction('purge-failed', adminStore.purgeFailed)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-red-500/20 hover:bg-red-500/30 text-red-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'purge-failed'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
              Purge Failed
            </button>

            <button
              @click="executeAction('clear-cache', adminStore.clearCache)"
              :disabled="actionLoading !== null"
              class="flex items-center justify-center gap-2 px-4 py-3 bg-slate-500/20 hover:bg-slate-500/30 text-slate-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <svg v-if="actionLoading === 'clear-cache'" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
              Clear Cache
            </button>
          </div>
        </div>
      </div>

      <!-- Scheduler Config -->
      <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
        <h3 class="text-lg font-semibold text-slate-200 mb-4">Scheduler & Rate Limiting</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
          <div class="p-3 bg-slate-900/50 rounded-lg text-center">
            <p class="text-xs text-slate-500 uppercase mb-1">Industry</p>
            <p class="text-lg font-bold text-violet-400">30 min</p>
          </div>
          <div class="p-3 bg-slate-900/50 rounded-lg text-center">
            <p class="text-xs text-slate-500 uppercase mb-1">PVE</p>
            <p class="text-lg font-bold text-green-400">1h</p>
          </div>
          <div class="p-3 bg-slate-900/50 rounded-lg text-center">
            <p class="text-xs text-slate-500 uppercase mb-1">Mining</p>
            <p class="text-lg font-bold text-amber-400">1h</p>
          </div>
          <div class="p-3 bg-slate-900/50 rounded-lg text-center">
            <p class="text-xs text-slate-500 uppercase mb-1">Wallet</p>
            <p class="text-lg font-bold text-cyan-400">1h</p>
          </div>
        </div>
        <div class="flex items-center gap-4 text-sm text-slate-400">
          <div class="flex items-center gap-2">
            <div class="w-2 h-2 rounded-full bg-yellow-400"></div>
            <span>Sync scope : <strong class="text-yellow-300">{{ formatNumber(stats?.characters?.activeSyncScope) }}</strong> chars actifs (login &lt; 7j) / {{ formatNumber(stats?.characters?.total) }} total</span>
          </div>
          <div class="flex items-center gap-2">
            <div class="w-2 h-2 rounded-full bg-cyan-400"></div>
            <span>Throttle ESI : progressif sous 20 errors remain, pause sous 5</span>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Registrations Chart -->
        <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
          <h3 class="text-lg font-semibold text-slate-200 mb-4">Inscriptions par semaine</h3>
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
          <h3 class="text-lg font-semibold text-slate-200 mb-4">Connexions (7 derniers jours)</h3>
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
          <h3 class="text-lg font-semibold text-slate-200 mb-4">Repartition des assets</h3>
          <div class="h-48">
            <Doughnut
              v-if="assetDistributionData"
              :data="assetDistributionData"
              :options="doughnutOptions"
            />
          </div>
        </div>
      </div>

      <!-- PVE Stats by Corporation -->
      <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-slate-200">Revenus PVE par Corporation (30j)</h3>
          <div class="flex items-center gap-2">
            <div class="w-10 h-10 rounded-lg bg-green-500/20 flex items-center justify-center">
              <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
              </svg>
            </div>
            <div class="text-right">
              <p class="text-xl font-bold text-green-400">{{ formatIsk(stats?.pve?.totalIncome30d || 0) }}</p>
              <p class="text-xs text-slate-500">Total 30 jours</p>
            </div>
          </div>
        </div>

        <div v-if="stats?.pve?.byCorporation?.length" class="space-y-2">
          <div
            v-for="(corp, index) in stats.pve.byCorporation"
            :key="corp.corporationId"
            class="flex items-center gap-3 p-3 bg-slate-900/50 rounded-lg"
          >
            <span class="text-slate-500 text-sm w-6">{{ index + 1 }}.</span>
            <img
              :src="`https://images.evetech.net/corporations/${corp.corporationId}/logo?size=32`"
              class="w-8 h-8 rounded"
              :alt="corp.corporationName"
            />
            <span class="flex-1 text-slate-200 truncate">{{ corp.corporationName }}</span>
            <span class="text-green-400 font-mono">{{ formatIsk(corp.total) }}</span>
          </div>
        </div>
        <p v-else class="text-slate-500 text-sm">Aucune donnee PVE</p>
      </div>
    </div>
  </MainLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
