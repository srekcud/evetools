<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { authFetch, safeJsonParse } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { usePveStore } from '@/stores/pve'
import { useFormatters } from '@/composables/useFormatters'
import { usePveExpenseScanning } from '@/composables/usePveExpenseScanning'
import { usePveTypeSettings } from '@/composables/usePveTypeSettings'
import PveDashboard from '@/components/pve/PveDashboard.vue'
import IncomeChart from '@/components/pve/IncomeChart.vue'
import ProfitTrendChart from '@/components/pve/ProfitTrendChart.vue'
import ExpenseBreakdownChart from '@/components/pve/ExpenseBreakdownChart.vue'
import AddExpenseDialog from '@/components/ledger/pve/AddExpenseDialog.vue'
import AddLootSaleDialog from '@/components/ledger/pve/AddLootSaleDialog.vue'
import TypeConfigDialog from '@/components/ledger/pve/TypeConfigDialog.vue'
import ContractScanDialog from '@/components/ledger/pve/ContractScanDialog.vue'
import LootSaleScanDialog from '@/components/ledger/pve/LootSaleScanDialog.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import type { PveData, Expense } from '@/types/pve'

const props = defineProps<{
  selectedDays: number
}>()

const emit = defineEmits<{
  sync: []
}>()

const { t } = useI18n()
const authStore = useAuthStore()
const pveStore = usePveStore()
const { formatIskFull, formatDate, formatDateTime } = useFormatters()

// Template refs for child components
const addExpenseDialogRef = ref<InstanceType<typeof AddExpenseDialog> | null>(null)
const addLootSaleDialogRef = ref<InstanceType<typeof AddLootSaleDialog> | null>(null)

// Core state
const pveData = ref<PveData | null>(null)
const expenses = ref<Expense[]>([])
const isLoading = ref(false)
const isSyncing = ref(false)
const error = ref('')

// Dialog visibility
const showAddForm = ref(false)
const showAddLootSaleForm = ref(false)
const showAmmoConfig = ref(false)
const showLootConfig = ref(false)

// Submitting states
const isSubmitting = ref(false)
const isSubmittingLootSale = ref(false)

const expenseTypes = computed(() => [
  { value: 'fuel', label: 'Fuel' },
  { value: 'ammo', label: t('pve.expenseTypes.ammo') },
  { value: 'crab_beacon', label: 'Crab Beacon' },
  { value: 'other', label: t('pve.expenseTypes.other') },
])

// Composables
const scanning = usePveExpenseScanning(
  async () => { await Promise.all([fetchPveData(), fetchExpenses()]) },
  (message: string) => { error.value = message },
  () => { showLootConfig.value = true },
)

const typeSettings = usePveTypeSettings(
  (contracts: number, transactions: number) => {
    scanning.setDeclinedCounts(contracts, transactions)
  },
)

// --- Data fetching ---

async function fetchPveData() {
  isLoading.value = true
  error.value = ''

  try {
    const response = await authFetch(`/api/pve/income?days=${props.selectedDays}`, {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (!response.ok) throw new Error('Failed to fetch PVE data')

    pveData.value = await safeJsonParse(response)
  } catch (e) {
    error.value = t('pve.errors.loadFailed')
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

async function fetchExpenses() {
  try {
    const response = await authFetch(`/api/pve/expenses?days=${props.selectedDays}`, {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (response.ok) {
      expenses.value = await safeJsonParse(response)
    }
  } catch (e) {
    console.error('Failed to fetch expenses:', e)
  }
}

// --- Sync ---

async function syncPveData() {
  isSyncing.value = true
  error.value = ''

  try {
    const response = await authFetch('/api/pve/sync', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({})
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response)
      throw new Error(data.error || 'Failed to sync')
    }

    await Promise.all([fetchPveData(), fetchExpenses()])
    emit('sync')
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : t('common.errors.syncFailed')
    console.error(e)
  } finally {
    isSyncing.value = false
  }
}

// --- Expense CRUD ---

async function handleAddExpense(payload: { type: string; description: string; amount: number; date: string }) {
  isSubmitting.value = true

  try {
    const response = await authFetch('/api/pve/expenses', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    })

    if (!response.ok) throw new Error('Failed to add expense')

    addExpenseDialogRef.value?.resetForm()
    showAddForm.value = false

    await Promise.all([fetchPveData(), fetchExpenses()])
  } catch (e) {
    error.value = t('pve.errors.addExpenseFailed')
    console.error(e)
  } finally {
    isSubmitting.value = false
  }
}

async function deleteExpense(id: string) {
  try {
    const response = await authFetch(`/api/pve/expenses/${id}`, {
      method: 'DELETE',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({})
    })

    if (!response.ok) throw new Error('Failed to delete expense')

    await Promise.all([fetchPveData(), fetchExpenses()])
  } catch (e) {
    error.value = t('common.errors.deleteFailed')
    console.error(e)
  }
}

function getExpenseTypeLabel(type: string): string {
  return expenseTypes.value.find(et => et.value === type)?.label || type
}

function getExpenseTypeColor(type: string): string {
  switch (type) {
    case 'fuel': return 'bg-orange-500/20 text-orange-400'
    case 'ammo': return 'bg-red-500/20 text-red-400'
    case 'crab_beacon': return 'bg-purple-500/20 text-purple-400'
    default: return 'bg-slate-500/20 text-slate-400'
  }
}

// --- Loot sale CRUD ---

async function handleAddLootSale(payload: { description: string; amount: number; date: string }) {
  isSubmittingLootSale.value = true

  try {
    const response = await authFetch('/api/pve/loot-sales', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    })

    if (!response.ok) throw new Error('Failed to add loot sale')

    addLootSaleDialogRef.value?.resetForm()
    showAddLootSaleForm.value = false

    await fetchPveData()
  } catch (e) {
    error.value = t('pve.errors.addLootSaleFailed')
    console.error(e)
  } finally {
    isSubmittingLootSale.value = false
  }
}

async function deleteLootSale(id: string) {
  try {
    const response = await authFetch(`/api/pve/loot-sales/${id}`, {
      method: 'DELETE',
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({})
    })

    if (!response.ok) throw new Error('Failed to delete loot sale')

    await fetchPveData()
  } catch (e) {
    error.value = t('common.errors.deleteFailed')
    console.error(e)
  }
}

// Watch for days change
watch(() => props.selectedDays, () => {
  fetchPveData()
  fetchExpenses()
})

onMounted(() => {
  fetchPveData()
  fetchExpenses()
  typeSettings.fetchSettings()
})

// Expose for parent
defineExpose({
  refresh: () => Promise.all([fetchPveData(), fetchExpenses()])
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <p class="text-slate-400">{{ t('pve.subtitle') }}</p>
        <p v-if="pveData?.lastSyncAt" class="text-xs text-slate-500 mt-1">
          {{ t('pve.lastSync') }} {{ formatDateTime(pveData.lastSyncAt) }}
        </p>
        <p v-else class="text-xs text-amber-500 mt-1">
          {{ t('pve.noSync') }}
        </p>
      </div>
      <div class="flex items-center gap-3">
        <button
          @click="showAmmoConfig = true"
          class="px-4 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-slate-300 text-sm font-medium flex items-center gap-2"
          :title="t('pve.configAmmo')"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          {{ t('pve.expenseTypes.ammo') }}
        </button>
        <button
          @click="syncPveData"
          :disabled="isSyncing"
          class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50"
          :title="t('pve.syncTooltip')"
        >
          <svg :class="['w-4 h-4', isSyncing ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          {{ isSyncing ? t('common.actions.syncing') : t('common.actions.refresh') }}
        </button>
        <button
          @click="scanning.scanContracts(selectedDays)"
          :disabled="scanning.isScanning.value"
          class="px-4 py-2 bg-purple-600 hover:bg-purple-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50"
        >
          <svg v-if="scanning.isScanning.value" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
          </svg>
          {{ scanning.isScanning.value ? t('pve.scanning') : t('pve.scanPurchases') }}
        </button>
        <button
          type="button"
          @click="showAddForm = true"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('pve.addExpense') }}
        </button>
      </div>
    </div>

    <!-- Error -->
    <ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />

    <!-- Loading -->
    <div v-if="isLoading" class="flex flex-col items-center justify-center py-20">
      <svg class="w-10 h-10 animate-spin text-cyan-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
      <p class="text-slate-400">{{ t('common.status.loading') }}</p>
    </div>

    <template v-else-if="pveData">
      <!-- Dashboard with Charts -->
      <div class="mb-8">
        <PveDashboard :days="selectedDays" />
      </div>

      <!-- Three columns: Bounties, Loot Sales, and Expenses -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Bounties list -->
        <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
          <div class="px-5 py-4 border-b border-slate-800">
            <h3 class="font-semibold">{{ t('pve.recentBounties') }}</h3>
            <p class="text-sm text-slate-500">{{ t('pve.npcRevenueLastDays', { days: pveData.period.days }) }}</p>
          </div>
          <div v-if="pveData.bounties.entries.length > 0" class="divide-y divide-slate-800 max-h-96 overflow-y-auto">
            <div
              v-for="entry in pveData.bounties.entries"
              :key="entry.id"
              class="px-5 py-3 flex items-center justify-between"
            >
              <div class="flex items-center gap-2">
                <span :class="[
                  'text-xs px-2 py-0.5 rounded-sm',
                  entry.refType === 'ess' ? 'bg-violet-500/20 text-violet-400' :
                  entry.refType === 'mission' ? 'bg-sky-500/20 text-sky-400' :
                  'bg-emerald-500/20 text-emerald-400'
                ]">{{ entry.refTypeLabel }}</span>
                <div>
                  <p class="text-sm text-slate-200">{{ entry.characterName }}</p>
                  <p class="text-xs text-slate-500">{{ formatDateTime(entry.date) }}</p>
                </div>
              </div>
              <span class="text-emerald-400 font-mono text-sm">+{{ formatIskFull(entry.amount) }}</span>
            </div>
          </div>
          <div v-else class="px-5 py-8 text-center text-slate-500">
            {{ t('pve.noBounties') }}
          </div>
        </div>

        <!-- Loot Sales list -->
        <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
          <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
            <div>
              <h3 class="font-semibold">{{ t('pve.lootSales') }}</h3>
            </div>
            <div class="flex gap-2">
              <button
                @click="showLootConfig = true"
                class="p-1.5 bg-slate-700 hover:bg-slate-600 rounded-sm text-white"
                :title="t('pve.configLootTypes')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
              </button>
              <button
                @click="scanning.scanLootSales(selectedDays)"
                :disabled="scanning.isScanningLoot.value"
                class="p-1.5 bg-amber-600 hover:bg-amber-500 rounded-sm text-white disabled:opacity-50"
                :title="t('pve.scanSales')"
              >
                <svg v-if="scanning.isScanningLoot.value" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
              </button>
              <button
                v-if="scanning.declinedContractsCount.value > 0 || scanning.declinedTransactionsCount.value > 0"
                @click="scanning.resetSeenContracts()"
                class="p-1.5 bg-slate-700 hover:bg-red-600 rounded-sm text-slate-400 hover:text-white"
                :title="t('pve.resetFilter', { count: scanning.declinedContractsCount.value + scanning.declinedTransactionsCount.value })"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
              </button>
              <button
                type="button"
                @click="showAddLootSaleForm = true"
                class="p-1.5 bg-slate-700 hover:bg-slate-600 rounded-sm text-white"
                :title="t('pve.addManually')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
              </button>
            </div>
          </div>
          <div v-if="pveData.lootSales.entries.length > 0" class="divide-y divide-slate-800 max-h-96 overflow-y-auto">
            <div
              v-for="sale in pveData.lootSales.entries"
              :key="sale.id"
              class="px-5 py-3 flex items-center justify-between group"
            >
              <div class="flex items-center gap-3">
                <span
                  class="text-xs px-2 py-1 rounded-sm"
                  :class="sale.type === 'corp_project' ? 'bg-cyan-500/20 text-cyan-400' : 'bg-amber-500/20 text-amber-400'"
                >
                  {{ sale.type === 'corp_project' ? t('pve.project') : 'Loot' }}
                </span>
                <div>
                  <p class="text-sm text-slate-200">{{ sale.description }}</p>
                  <p class="text-xs text-slate-500">{{ formatDate(sale.date) }}</p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <span class="text-amber-400 font-mono text-sm">+{{ formatIskFull(sale.amount) }}</span>
                <button
                  @click="deleteLootSale(sale.id)"
                  class="opacity-0 group-hover:opacity-100 p-1 hover:bg-red-500/20 rounded-sm text-slate-400 hover:text-red-400 transition-all"
                  title="Supprimer"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </div>
            </div>
          </div>
          <div v-else class="px-5 py-8 text-center text-slate-500">
            {{ t('pve.noSales') }}
          </div>
        </div>

        <!-- Expenses list -->
        <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
          <div class="px-5 py-4 border-b border-slate-800">
            <h3 class="font-semibold">{{ t('pve.expenses') }}</h3>
            <p class="text-sm text-slate-500">{{ t('pve.expensesDescription') }}</p>
          </div>
          <div v-if="expenses.length > 0" class="divide-y divide-slate-800 max-h-96 overflow-y-auto">
            <div
              v-for="expense in expenses"
              :key="expense.id"
              class="px-5 py-3 flex items-center justify-between group"
            >
              <div class="flex items-center gap-3">
                <span :class="['text-xs px-2 py-1 rounded-sm', getExpenseTypeColor(expense.type)]">
                  {{ getExpenseTypeLabel(expense.type) }}
                </span>
                <div>
                  <p class="text-sm text-slate-200">{{ expense.description }}</p>
                  <p class="text-xs text-slate-500">{{ formatDate(expense.date) }}</p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <span class="text-red-400 font-mono text-sm">-{{ formatIskFull(expense.amount) }}</span>
                <button
                  @click="deleteExpense(expense.id)"
                  class="opacity-0 group-hover:opacity-100 p-1 hover:bg-red-500/20 rounded-sm text-slate-400 hover:text-red-400 transition-all"
                  title="Supprimer"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </div>
            </div>
          </div>
          <div v-else class="px-5 py-8 text-center text-slate-500">
            {{ t('pve.noExpenses') }}
          </div>
        </div>
      </div>

      <!-- Charts Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        <IncomeChart
          v-if="pveStore.dailyStats.length > 0"
          :data="pveStore.dailyStats"
        />
        <ProfitTrendChart
          v-if="pveStore.dailyStats.length > 0"
          :data="pveStore.dailyStats"
        />
        <ExpenseBreakdownChart
          v-if="pveStore.stats && pveStore.stats.totals.expenses > 0"
          :data="{
            fuel: pveStore.stats.expensesByType?.fuel ?? 0,
            ammo: pveStore.stats.expensesByType?.ammo ?? 0,
            crab_beacon: pveStore.stats.expensesByType?.crab_beacon ?? 0,
            other: pveStore.stats.expensesByType?.other ?? 0,
          }"
        />
      </div>
    </template>

    <!-- Dialogs -->
    <AddExpenseDialog
      ref="addExpenseDialogRef"
      :visible="showAddForm"
      :is-submitting="isSubmitting"
      @close="showAddForm = false"
      @submit="handleAddExpense"
    />

    <AddLootSaleDialog
      ref="addLootSaleDialogRef"
      :visible="showAddLootSaleForm"
      :is-submitting="isSubmittingLootSale"
      @close="showAddLootSaleForm = false"
      @submit="handleAddLootSale"
    />

    <TypeConfigDialog
      :visible="showAmmoConfig"
      :title="t('pve.ammoConfigTitle')"
      :description="t('pve.ammoConfigDescription')"
      :search-placeholder="t('pve.searchAmmoPlaceholder')"
      accent-color="cyan"
      :configured-types="typeSettings.ammoTypes.value"
      :is-searching="typeSettings.isSearchingAmmo.value"
      :is-adding="typeSettings.isAddingAmmo.value"
      :search-results="typeSettings.ammoSearchResults.value"
      @close="showAmmoConfig = false"
      @search="typeSettings.searchAmmoTypes"
      @add="typeSettings.addAmmoType"
      @remove="typeSettings.removeAmmoType"
    />

    <TypeConfigDialog
      :visible="showLootConfig"
      :title="t('pve.lootTypesTitle')"
      :description="t('pve.lootTypesDescription')"
      :search-placeholder="t('pve.searchLootPlaceholder')"
      accent-color="amber"
      :configured-types="typeSettings.lootTypes.value"
      :is-searching="typeSettings.isSearchingLoot.value"
      :is-adding="typeSettings.isAddingLootType.value"
      :search-results="typeSettings.lootSearchResults.value"
      @close="showLootConfig = false"
      @search="typeSettings.searchLootTypes"
      @add="typeSettings.addLootType"
      @remove="typeSettings.removeLootType"
    >
      <template #empty-message>
        {{ t('pve.noLootTypeConfigured') }}
      </template>
    </TypeConfigDialog>

    <ContractScanDialog
      :visible="scanning.showScanResults.value"
      :scan-results="scanning.scanResults.value"
      :is-importing="scanning.isImporting.value"
      :expense-types="expenseTypes"
      @close="scanning.showScanResults.value = false"
      @import="scanning.importSelectedExpenses"
      @toggle-selection="scanning.toggleExpenseSelection"
      @select-all="scanning.selectAllExpenses"
      @deselect-all="scanning.deselectAllExpenses"
    />

    <LootSaleScanDialog
      :visible="scanning.showLootScanResults.value"
      :scan-results="scanning.lootScanResults.value"
      :is-importing="scanning.isImportingLoot.value"
      @close="scanning.showLootScanResults.value = false"
      @import-sale="scanning.importSingleLootSale"
      @ignore-sale="scanning.ignoreLootSale"
    />
  </div>
</template>
