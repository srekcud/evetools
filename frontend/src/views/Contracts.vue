<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { authFetch, safeJsonParse } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import MainLayout from '@/layouts/MainLayout.vue'

const { t } = useI18n()
const authStore = useAuthStore()
const { formatIsk, formatDate, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

interface ContractItem {
  typeId: number
  typeName: string
  quantity: number
  jitaPrice: number | null
  jitaValue: number | null
  delvePrice: number | null
  delveValue: number | null
}

interface Contract {
  contractId: number
  type: string
  status: string
  title: string
  price: number
  reward: number
  volume: number
  dateIssued: string
  dateExpired: string | null
  dateCompleted: string | null
  issuerId: number
  assigneeId: number | null
  acceptorId: number
  forCorporation: boolean
  isSeller: boolean
  items: ContractItem[]
  itemCount: number
  // Jita comparison
  jitaValue: number | null
  jitaDiff: number | null
  jitaDiffPercent: number | null
  // Delve comparison (C-J6MT)
  delveValue: number | null
  delveDiff: number | null
  delveDiffPercent: number | null
  // Similar contracts comparison
  similarCount: number
  lowestSimilar: number | null
  avgSimilar: number | null
  similarDiff: number | null
  similarDiffPercent: number | null
  // Overall
  isCompetitive: boolean | null
}

// State
const contracts = ref<Contract[]>([])
const isLoading = ref(false)
const error = ref('')
const selectedStatus = ref('outstanding')
const expandedContract = ref<number | null>(null)

const statusOptions = computed(() => [
  { value: 'outstanding', label: t('contracts.filters.outstanding') },
  { value: 'finished', label: t('contracts.filters.finished') },
  { value: 'all', label: t('contracts.filters.all') },
])

async function fetchContracts() {
  isLoading.value = true
  error.value = ''

  try {
    const response = await authFetch(`/api/contracts?status=${selectedStatus.value}`, {
      headers: { 'Authorization': `Bearer ${authStore.token}` }
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response)
      throw new Error(data.error || 'Failed to fetch contracts')
    }

    const data = await safeJsonParse<{ contracts: Contract[] }>(response)
    contracts.value = data.contracts
  } catch (e: any) {
    error.value = e.message || t('common.errors.loadFailed')
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

function getStatusLabelKey(status: string): string {
  const keys: Record<string, string> = {
    outstanding: 'contracts.statuses.outstanding',
    finished: 'contracts.statuses.finished',
    completed: 'contracts.statuses.completed',
    cancelled: 'contracts.statuses.cancelled',
    rejected: 'contracts.statuses.rejected',
    failed: 'contracts.statuses.failed',
    deleted: 'contracts.statuses.deleted',
    reversed: 'contracts.statuses.reversed',
    in_progress: 'contracts.statuses.inProgress',
  }
  return keys[status] ?? status
}

const STATUS_COLORS: Record<string, string> = {
  outstanding: 'bg-cyan-500/20 text-cyan-400',
  finished: 'bg-emerald-500/20 text-emerald-400',
  completed: 'bg-emerald-500/20 text-emerald-400',
  cancelled: 'bg-red-500/20 text-red-400',
  rejected: 'bg-red-500/20 text-red-400',
  failed: 'bg-red-500/20 text-red-400',
  deleted: 'bg-red-500/20 text-red-400',
}

function getStatusLabel(status: string): string {
  const key = getStatusLabelKey(status)
  return key.startsWith('contracts.') ? t(key) : key
}

function getStatusColor(status: string): string {
  return STATUS_COLORS[status] ?? 'bg-slate-500/20 text-slate-400'
}

function getDiffColor(diff: number | null, isSeller: boolean): string {
  if (diff === null) return 'text-slate-400'
  // For sellers: lower price = better (negative diff is good)
  // For buyers: higher price = worse
  const isGood = isSeller ? diff <= 0 : diff >= 0
  return isGood ? 'text-emerald-400' : 'text-red-400'
}

function toggleExpand(contractId: number): void {
  expandedContract.value = expandedContract.value === contractId ? null : contractId
}

const sellContracts = computed(() => contracts.value.filter(c => c.isSeller))

const competitiveCount = computed(() => contracts.value.filter(c => c.isCompetitive === true).length)
const nonCompetitiveCount = computed(() => contracts.value.filter(c => c.isCompetitive === false).length)

onMounted(() => {
  fetchContracts()
})

function onStatusChange() {
  fetchContracts()
}
</script>

<template>
  <MainLayout>
      <!-- Header -->
      <div class="flex items-center justify-end mb-6">
        <div class="flex items-center gap-3">
          <!-- Status selector -->
          <select
            v-model="selectedStatus"
            @change="onStatusChange"
            class="bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
          >
            <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
          <!-- Refresh button -->
          <button
            @click="fetchContracts"
            :disabled="isLoading"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 disabled:opacity-50"
          >
            <svg :class="['w-4 h-4', isLoading && 'animate-spin']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            {{ isLoading ? t('common.status.loading') : t('common.actions.refresh') }}
          </button>
        </div>
      </div>

      <!-- Error -->
      <div v-if="error" class="mb-6 p-4 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400 flex items-center justify-between">
        <span>{{ error }}</span>
        <button @click="error = ''" class="text-red-400 hover:text-red-300">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <!-- Loading -->
      <div v-if="isLoading && contracts.length === 0" class="flex flex-col items-center justify-center py-20">
        <svg class="w-10 h-10 animate-spin text-cyan-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        <p class="text-slate-400">{{ t('contracts.loading') }}</p>
        <p class="text-slate-500 text-sm mt-1">{{ t('contracts.loadingHint') }}</p>
      </div>

      <template v-else>
        <!-- Summary cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
          <!-- Total contracts -->
          <div class="group relative bg-slate-900 rounded-2xl p-5 border border-slate-800 overflow-hidden transition-all duration-300 hover:border-cyan-500/40 hover:shadow-lg hover:shadow-cyan-500/10 hover:-translate-y-1">
            <div class="absolute inset-0 bg-linear-to-r from-transparent via-cyan-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
            <div class="relative">
              <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">{{ t('contracts.kpi.totalContracts') }}</p>
              <p class="text-3xl font-bold text-cyan-400">{{ contracts.length }}</p>
            </div>
          </div>

          <!-- Sell contracts -->
          <div class="group relative bg-slate-900 rounded-2xl p-5 border border-slate-800 overflow-hidden transition-all duration-300 hover:border-amber-500/40 hover:shadow-lg hover:shadow-amber-500/10 hover:-translate-y-1">
            <div class="absolute inset-0 bg-linear-to-r from-transparent via-amber-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
            <div class="relative">
              <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">{{ t('contracts.kpi.sales') }}</p>
              <p class="text-3xl font-bold text-amber-400">{{ sellContracts.length }}</p>
            </div>
          </div>

          <!-- Competitive -->
          <div class="group relative bg-slate-900 rounded-2xl p-5 border border-slate-800 overflow-hidden transition-all duration-300 hover:border-emerald-500/40 hover:shadow-lg hover:shadow-emerald-500/10 hover:-translate-y-1">
            <div class="absolute inset-0 bg-linear-to-r from-transparent via-emerald-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
            <div class="relative">
              <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">{{ t('contracts.competitive') }}</p>
              <p class="text-3xl font-bold text-emerald-400">{{ competitiveCount }}</p>
            </div>
          </div>

          <!-- Non competitive -->
          <div class="group relative bg-slate-900 rounded-2xl p-5 border border-slate-800 overflow-hidden transition-all duration-300 hover:border-red-500/40 hover:shadow-lg hover:shadow-red-500/10 hover:-translate-y-1">
            <div class="absolute inset-0 bg-linear-to-r from-transparent via-red-500/10 to-transparent -translate-x-full group-hover:translate-x-full transition-transform duration-700 ease-in-out"></div>
            <div class="relative">
              <p class="text-slate-500 text-sm uppercase tracking-wider mb-1">{{ t('contracts.notCompetitive') }}</p>
              <p class="text-3xl font-bold text-red-400">{{ nonCompetitiveCount }}</p>
            </div>
          </div>
        </div>

        <!-- Contracts list -->
        <div v-if="contracts.length > 0" class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
          <div class="px-5 py-4 border-b border-slate-800">
            <h3 class="font-semibold">{{ t('contracts.itemExchange') }}</h3>
            <p class="text-sm text-slate-500">{{ t('contracts.jitaComparison') }}</p>
            <p class="text-xs text-slate-600 mt-1">
              {{ t('contracts.thresholds') }}
            </p>
          </div>

          <div class="divide-y divide-slate-800">
            <div
              v-for="contract in contracts"
              :key="contract.contractId"
              class="transition-colors hover:bg-slate-800/30"
            >
              <!-- Contract row -->
              <div
                @click="toggleExpand(contract.contractId)"
                class="px-5 py-4 cursor-pointer"
              >
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-4">
                    <!-- Expand icon -->
                    <svg
                      :class="['w-5 h-5 text-slate-500 transition-transform', expandedContract === contract.contractId && 'rotate-90']"
                      fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>

                    <!-- Contract info -->
                    <div>
                      <div class="flex items-center gap-2 mb-1">
                        <span :class="['text-xs px-2 py-0.5 rounded-sm', getStatusColor(contract.status)]">
                          {{ getStatusLabel(contract.status) }}
                        </span>
                        <span :class="['text-xs px-2 py-0.5 rounded-sm', contract.isSeller ? 'bg-amber-500/20 text-amber-400' : 'bg-blue-500/20 text-blue-400']">
                          {{ contract.isSeller ? t('contracts.sell') : t('contracts.buy') }}
                        </span>
                        <span class="text-xs text-slate-500">
                          #{{ contract.contractId }}
                        </span>
                      </div>
                      <p class="text-sm text-slate-300">
                        {{ contract.title || `${contract.itemCount} item(s)` }}
                      </p>
                      <p class="text-xs text-slate-500 mt-1">
                        {{ formatDateTime(contract.dateIssued) }}
                        <span v-if="contract.dateExpired"> - {{ t('contracts.expires') }}: {{ formatDate(contract.dateExpired) }}</span>
                      </p>
                    </div>
                  </div>

                  <!-- Price comparison -->
                  <div class="text-right">
                    <div class="flex items-center gap-4">
                      <!-- Contract price -->
                      <div>
                        <p class="text-xs text-slate-500 uppercase">{{ t('contracts.contractPrice') }}</p>
                        <p class="text-lg font-bold text-slate-200">{{ formatIsk(contract.price) }}</p>
                      </div>

                      <!-- Jita value -->
                      <div v-if="contract.jitaValue">
                        <p class="text-xs text-slate-500 uppercase">Jita</p>
                        <p class="text-sm font-medium text-slate-400">{{ formatIsk(contract.jitaValue) }}</p>
                        <p v-if="contract.jitaDiffPercent !== null" :class="['text-xs', getDiffColor(contract.jitaDiff, contract.isSeller)]">
                          {{ contract.jitaDiff && contract.jitaDiff >= 0 ? '+' : '' }}{{ contract.jitaDiffPercent?.toFixed(0) }}%
                        </p>
                      </div>

                      <!-- C-J6MT value -->
                      <div v-if="contract.delveValue">
                        <p class="text-xs text-slate-500 uppercase">C-J6MT</p>
                        <p class="text-sm font-medium text-slate-400">{{ formatIsk(contract.delveValue) }}</p>
                        <p v-if="contract.delveDiffPercent !== null" :class="['text-xs', getDiffColor(contract.delveDiff, contract.isSeller)]">
                          {{ contract.delveDiff && contract.delveDiff >= 0 ? '+' : '' }}{{ contract.delveDiffPercent?.toFixed(0) }}%
                        </p>
                      </div>

                      <!-- Similar contracts -->
                      <div v-if="contract.similarCount > 0" class="min-w-24">
                        <p class="text-xs text-slate-500 uppercase">{{ t('contracts.similar') }} ({{ contract.similarCount }})</p>
                        <p class="text-sm font-medium text-slate-400">{{ formatIsk(contract.lowestSimilar!) }}</p>
                        <p v-if="contract.similarDiffPercent !== null" :class="['text-xs', getDiffColor(contract.similarDiff, contract.isSeller)]">
                          {{ contract.similarDiff && contract.similarDiff >= 0 ? '+' : '' }}{{ contract.similarDiffPercent?.toFixed(0) }}%
                        </p>
                      </div>

                      <!-- Competitive indicator -->
                      <div class="min-w-10">
                        <svg v-if="contract.isCompetitive === true" class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <svg v-else-if="contract.isCompetitive === false" class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span v-else class="text-xs text-slate-600">-</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Expanded items -->
              <div
                v-if="expandedContract === contract.contractId"
                class="px-5 pb-4 bg-slate-800/30"
              >
                <div class="border-t border-slate-800 pt-4">
                  <p class="text-xs text-slate-500 uppercase tracking-wider mb-3">{{ t('contracts.contractContents') }}</p>
                  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div
                      v-for="item in contract.items"
                      :key="item.typeId"
                      class="flex items-center gap-3 bg-slate-800/50 rounded-lg p-3"
                    >
                      <img
                        :src="getTypeIconUrl(item.typeId, 32)"
                        :alt="item.typeName"
                        class="w-8 h-8 rounded-sm"
                        @error="onImageError"
                      />
                      <div class="flex-1 min-w-0">
                        <p class="text-sm text-slate-300 truncate">{{ item.typeName }}</p>
                        <p class="text-xs text-slate-500">x{{ item.quantity.toLocaleString() }}</p>
                      </div>
                      <div class="text-right flex gap-4">
                        <div v-if="item.jitaPrice">
                          <p class="text-xs text-slate-500">Jita</p>
                          <p class="text-xs text-slate-400">{{ formatIsk(item.jitaPrice) }}/u</p>
                        </div>
                        <div v-if="item.delvePrice">
                          <p class="text-xs text-slate-500">C-J6MT</p>
                          <p class="text-xs text-slate-400">{{ formatIsk(item.delvePrice) }}/u</p>
                        </div>
                        <div v-if="!item.jitaPrice && !item.delvePrice">
                          <p class="text-xs text-slate-600">N/A</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div v-else class="bg-slate-900 rounded-xl border border-slate-800 p-12 text-center">
          <svg class="w-16 h-16 mx-auto text-slate-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          <p class="text-slate-400 text-lg">{{ t('contracts.noContracts') }}</p>
          <p class="text-slate-500 text-sm mt-1">{{ t('contracts.noContractsHint') }}</p>
        </div>
      </template>
  </MainLayout>
</template>
