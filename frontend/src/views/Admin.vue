<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAdminStore } from '@/stores/admin'
import { useSyncStore } from '@/stores/sync'
import { useFormatters } from '@/composables/useFormatters'
import MainLayout from '@/layouts/MainLayout.vue'
import AdminKpiGrid from '@/components/admin/AdminKpiGrid.vue'
import SchedulerHealthTable from '@/components/admin/SchedulerHealthTable.vue'
import MaintenanceActions from '@/components/admin/MaintenanceActions.vue'
import AdminChartsGrid from '@/components/admin/AdminChartsGrid.vue'
import PveCorpRevenue from '@/components/admin/PveCorpRevenue.vue'
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

const { t } = useI18n()
const router = useRouter()
const adminStore = useAdminStore()
const syncStore = useSyncStore()
const { formatNumber } = useFormatters()

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

const syncActionMap: Record<string, { action: () => Promise<{ success: boolean; message: string }>; key: string }> = {
  'assets': { action: adminStore.syncAssets, key: 'sync-assets' },
  'industry': { action: adminStore.syncIndustry, key: 'sync-industry' },
  'pve': { action: adminStore.syncPve, key: 'sync-pve' },
  'wallet': { action: adminStore.syncWallet, key: 'sync-wallet' },
  'mining': { action: adminStore.syncMining, key: 'sync-mining' },
  'ansiblex': { action: adminStore.syncAnsiblex, key: 'sync-ansiblex' },
  'planetary': { action: adminStore.syncPlanetary, key: 'sync-planetary' },
  'market-jita': { action: adminStore.syncMarket, key: 'sync-market' },
  'market-structure': { action: adminStore.syncMarket, key: 'sync-market' },
}

// Sync action keys that should wait for Mercure completion
const syncKeys = new Set(Object.values(syncActionMap).map(v => v.key))

async function refreshData() {
  await adminStore.fetchAll()
}

async function executeAction(actionName: string, actionFn: () => Promise<{ success: boolean; message: string }>) {
  actionLoading.value = actionName
  actionMessage.value = null

  try {
    const result = await actionFn()

    // For sync actions, keep spinner alive until Mercure confirms completion
    if (result.success && syncKeys.has(actionName)) {
      actionMessage.value = {
        type: 'success',
        text: result.message + ` (${t('admin.sync.waiting')})`,
      }
      return
    }

    actionMessage.value = {
      type: result.success ? 'success' : 'error',
      text: result.message,
    }
    if (result.success) {
      setTimeout(() => adminStore.fetchAll(), 1000)
    }
  } catch (e) {
    actionMessage.value = {
      type: 'error',
      text: e instanceof Error ? e.message : t('admin.sync.actionFailed'),
    }
  } finally {
    if (!syncKeys.has(actionName)) {
      actionLoading.value = null
    }
    setTimeout(() => {
      actionMessage.value = null
    }, 5000)
  }
}

function handleSchedulerAction(key: string, action: () => Promise<{ success: boolean; message: string }>) {
  executeAction(key, action)
}

function handleMaintenanceAction(name: string, actionKey: string) {
  const actionMap: Record<string, () => Promise<{ success: boolean; message: string }>> = {
    retryFailed: adminStore.retryFailed,
    purgeFailed: adminStore.purgeFailed,
    clearCache: adminStore.clearCache,
  }
  const fn = actionMap[actionKey]
  if (fn) {
    executeAction(name, fn)
  }
}

// Watch for Mercure admin-sync events
watch(
  () => syncStore.adminSyncProgress,
  (progress) => {
    if (!progress) return

    if (progress.status === 'started') {
      adminStore.fetchStats()
    } else if (progress.status === 'completed') {
      const syncType = (progress.data as Record<string, unknown>)?.syncType as string
      actionLoading.value = null
      actionMessage.value = {
        type: 'success',
        text: `${t('admin.sync.syncCompleted', { type: syncType || '' })} : ${progress.message || 'OK'}`,
      }
      adminStore.fetchStats()
      syncStore.clearSyncStatus('admin-sync')
      setTimeout(() => { actionMessage.value = null }, 5000)
    } else if (progress.status === 'error') {
      const syncType = (progress.data as Record<string, unknown>)?.syncType as string
      actionLoading.value = null
      actionMessage.value = {
        type: 'error',
        text: `${t('admin.sync.syncError', { type: syncType || '' })} : ${progress.message || t('admin.sync.unknownError')}`,
      }
      adminStore.fetchStats()
      syncStore.clearSyncStatus('admin-sync')
      setTimeout(() => { actionMessage.value = null }, 8000)
    }
  }
)
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
        {{ t('common.actions.retry') }}
      </button>
    </div>

    <!-- Admin Dashboard -->
    <div v-else-if="hasAccess" class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold text-slate-100">{{ t('admin.title') }}</h2>
          <p class="text-sm text-slate-500">{{ t('admin.subtitle') }}</p>
        </div>
        <button
          @click="refreshData"
          class="px-4 py-2 bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-400 rounded-lg text-sm font-medium transition-colors flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ t('common.actions.refresh') }}
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

      <!-- KPI Grid (3 rows) -->
      <AdminKpiGrid :stats="stats" :queues="queues" />

      <!-- Scheduler Health + Maintenance Actions -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
          <SchedulerHealthTable
            :entries="stats?.schedulerHealth || []"
            :sync-action-map="syncActionMap"
            :action-loading="actionLoading"
            @execute-action="handleSchedulerAction"
          />
        </div>
        <MaintenanceActions
          :action-loading="actionLoading"
          @execute-action="handleMaintenanceAction"
        />
      </div>

      <!-- Scheduler Config -->
      <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
        <h3 class="text-lg font-semibold text-slate-200 mb-4">{{ t('admin.scheduler.title') }}</h3>
        <div class="flex items-center gap-4 text-sm text-slate-400">
          <div class="flex items-center gap-2">
            <div class="w-2 h-2 rounded-full bg-yellow-400"></div>
            <span>{{ t('admin.scheduler.syncScope') }} : <strong class="text-yellow-300">{{ formatNumber(stats?.characters?.activeSyncScope) }}</strong> {{ t('admin.scheduler.activeChars') }} / {{ formatNumber(stats?.characters?.total) }} total</span>
          </div>
          <div class="flex items-center gap-2">
            <div class="w-2 h-2 rounded-full bg-cyan-400"></div>
            <span>{{ t('admin.scheduler.throttleEsi') }}</span>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <AdminChartsGrid :charts="charts" />

      <!-- PVE Stats by Corporation -->
      <PveCorpRevenue :pve="stats?.pve" />
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
