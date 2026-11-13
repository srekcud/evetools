<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useFlash } from '@/composables/useUpdateFlash'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAdminStore } from '@/stores/admin'
import { useSyncStore } from '@/stores/sync'
import { useTabQuery } from '@/composables/useTabQuery'
import MainLayout from '@/layouts/MainLayout.vue'
import AdminStatusBanner from '@/components/admin/AdminStatusBanner.vue'
import AdminKpiGrid from '@/components/admin/AdminKpiGrid.vue'
import SchedulerHealthTable from '@/components/admin/SchedulerHealthTable.vue'
import EsiErrorBudgetCard from '@/components/admin/EsiErrorBudgetCard.vue'
import SdeBuildCard from '@/components/admin/SdeBuildCard.vue'
import EsiRateLimitCard from '@/components/admin/EsiRateLimitCard.vue'
import MercureCookieCard from '@/components/admin/MercureCookieCard.vue'
import CharactersToRepairCard from '@/components/admin/CharactersToRepairCard.vue'
import AdminCharactersCard from '@/components/admin/AdminCharactersCard.vue'
import UserSupportCard from '@/components/admin/UserSupportCard.vue'
import MaintenanceActions from '@/components/admin/MaintenanceActions.vue'
import FailedMessagesCard from '@/components/admin/FailedMessagesCard.vue'
import AdminChartsGrid from '@/components/admin/AdminChartsGrid.vue'
import PveCorpRevenue from '@/components/admin/PveCorpRevenue.vue'
import ModuleActiveUsersCard from '@/components/admin/ModuleActiveUsersCard.vue'
import type { MaintenanceActionKey } from '@/components/admin/maintenanceActionKeys'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
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

type AdminTab = 'health' | 'users' | 'statistics'

const ADMIN_TABS: readonly AdminTab[] = ['health', 'users', 'statistics']
const DEFAULT_ADMIN_TAB: AdminTab = 'health'

const activeTab = useTabQuery(ADMIN_TABS, DEFAULT_ADMIN_TAB)

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
const failedMessageCount = computed(() => queues.value?.queues.failed ?? null)

async function refreshData() {
  await adminStore.fetchAll()
}

async function executeAction(
  actionName: string,
  actionFn: () => Promise<{ success: boolean; message: string }>,
  isSync = false,
) {
  let waitsForMercure = false
  actionLoading.value = actionName
  actionMessage.value = null

  try {
    const result = await actionFn()

    // For sync actions, keep spinner alive until Mercure confirms completion
    if (result.success && isSync) {
      waitsForMercure = true
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
    if (!waitsForMercure) {
      actionLoading.value = null
    }
    setTimeout(() => {
      actionMessage.value = null
    }, 5000)
  }
}

function handleSchedulerAction(type: string) {
  executeAction(type, () => adminStore.triggerSync(type), true)
}

function handleMaintenanceAction(name: string, actionKey: MaintenanceActionKey) {
  const actionMap: Record<MaintenanceActionKey, () => Promise<{ success: boolean; message: string }>> = {
    retryFailed: adminStore.retryFailed,
    purgeFailed: adminStore.purgeFailed,
    clearCache: adminStore.clearCache,
    checkMarketAlerts: adminStore.checkMarketAlerts,
    purgeNotifications: adminStore.purgeNotifications,
    purgeMarketHistory: adminStore.purgeMarketHistory,
  }
  executeAction(name, actionMap[actionKey])
}

const { isFlashing: statsUpdated, flash: flashStats } = useFlash()

const ADMIN_HEALTH_DEBOUNCE_MS = 1000

const failedMessagesRefreshSignal = ref<string | null>(null)
const pendingHealthRefreshes = new Map<string, ReturnType<typeof setTimeout>>()

function refreshOnceSettled(scope: string, refresh: () => void): void {
  const pending = pendingHealthRefreshes.get(scope)
  if (pending !== undefined) clearTimeout(pending)
  pendingHealthRefreshes.set(scope, setTimeout(() => {
    pendingHealthRefreshes.delete(scope)
    refresh()
  }, ADMIN_HEALTH_DEBOUNCE_MS))
}

watch(
  () => syncStore.adminHealthSignals['esi-error-budget'],
  (signal) => {
    if (!signal) return
    refreshOnceSettled('esi-error-budget', () => { adminStore.fetchStats().then(flashStats) })
  },
)

watch(
  () => syncStore.adminHealthSignals['sde-build'],
  (signal) => {
    if (!signal) return
    refreshOnceSettled('sde-build', () => { adminStore.fetchStats().then(flashStats) })
  },
)

watch(
  () => syncStore.adminHealthSignals['failed-messages'],
  (signal) => {
    if (!signal) return
    refreshOnceSettled('failed-messages', () => {
      adminStore.fetchQueues()
      failedMessagesRefreshSignal.value = signal
    })
  },
)

onUnmounted(() => {
  pendingHealthRefreshes.forEach((timer) => clearTimeout(timer))
  pendingHealthRefreshes.clear()
})

const selectedUserId = ref<string | null>(null)

async function openUserSheet(userId: string): Promise<void> {
  selectedUserId.value = null
  await nextTick()
  selectedUserId.value = userId
  document.getElementById('user-support')?.scrollIntoView({ behavior: 'smooth' })
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
      adminStore.fetchStats().then(flashStats)
      adminStore.fetchStatus()
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
      adminStore.fetchStatus()
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
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>

    <!-- Error state -->
    <div v-else-if="adminStore.error" class="bg-red-500/20 border border-red-500 p-4 rounded-lg">
      <p class="text-red-400">{{ adminStore.error }}</p>
      <button @click="refreshData" class="mt-2 px-4 py-2 bg-red-500/30 hover:bg-red-500/50 rounded-sm text-sm">
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

      <div class="border-b border-slate-800 overflow-x-auto">
        <div class="flex gap-6 min-w-max" role="tablist">
          <button
            v-for="tab in ADMIN_TABS"
            :key="tab"
            :data-testid="`admin-tab-${tab}`"
            type="button"
            role="tab"
            :aria-selected="activeTab === tab"
            class="px-1 pb-3 text-sm font-medium transition-colors border-b-2 whitespace-nowrap"
            :class="activeTab === tab ? 'border-cyan-400 text-cyan-400' : 'border-transparent text-slate-500 hover:text-slate-300'"
            @click="activeTab = tab"
          >
            {{ t(`admin.tabs.${tab}`) }}
          </button>
        </div>
      </div>

      <template v-if="activeTab === 'health'">
        <AdminStatusBanner :status="adminStore.status" />

        <div id="admin-section-kpis" class="relative rounded-xl" :class="{ 'hud-updated': statsUpdated }">
          <AdminKpiGrid :stats="stats" :queues="queues" scope="operations" />
        </div>

        <div id="admin-section-maintenance" class="space-y-6">
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div id="admin-section-scheduler-health" class="lg:col-span-2">
              <SchedulerHealthTable
                :entries="stats?.schedulerHealth || []"
                :action-loading="actionLoading"
                @execute-action="handleSchedulerAction"
              />
            </div>
            <div>
              <MaintenanceActions
                :action-loading="actionLoading"
                :failed-message-count="failedMessageCount"
                @execute-action="handleMaintenanceAction"
              />
            </div>
          </div>

          <FailedMessagesCard :refresh-signal="failedMessagesRefreshSignal" />
        </div>

        <EsiErrorBudgetCard v-if="stats?.esiErrorBudget" :budget="stats.esiErrorBudget" />

        <SdeBuildCard v-if="stats?.sdeBuild" :build="stats.sdeBuild" />

        <EsiRateLimitCard v-if="stats?.esiRateLimits" :groups="stats.esiRateLimits" />

        <MercureCookieCard v-if="stats?.mercureCookie" :mercure-cookie="stats.mercureCookie" />
      </template>

      <template v-else-if="activeTab === 'users'">
        <div class="relative rounded-xl" :class="{ 'hud-updated': statsUpdated }">
          <AdminKpiGrid :stats="stats" :queues="queues" scope="users" />
        </div>

        <CharactersToRepairCard v-if="stats?.charactersToRepair" :characters-to-repair="stats.charactersToRepair" @open-user="openUserSheet" />

        <AdminCharactersCard v-if="stats?.adminCharacters" :admin-characters="stats.adminCharacters" />

        <UserSupportCard :selected-user-id="selectedUserId" />
      </template>

      <template v-else>
        <div class="relative rounded-xl" :class="{ 'hud-updated': statsUpdated }">
          <AdminKpiGrid :stats="stats" :queues="queues" scope="statistics" />
        </div>
        <ModuleActiveUsersCard
          v-if="stats?.moduleActiveUsers"
          :modules="stats.moduleActiveUsers"
          :active-users-last30-days="stats.users.activeLastMonth"
        />
        <AdminChartsGrid :charts="charts" />
        <PveCorpRevenue :pve="stats?.pve" />
      </template>
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
