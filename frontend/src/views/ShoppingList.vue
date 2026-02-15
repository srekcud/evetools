<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { authFetch, safeJsonParse } from '@/services/api'
import MainLayout from '@/layouts/MainLayout.vue'
import ShoppingListResults from '@/components/shopping/ShoppingListResults.vue'
import AppraisalResults from '@/components/shopping/AppraisalResults.vue'
import type { ShoppingItem, ShoppingTotals } from '@/components/shopping/ShoppingListResults.vue'
import type { AppraisalItem, AppraisalTotals } from '@/components/shopping/AppraisalResults.vue'

interface ShoppingListResponse {
  items: ShoppingItem[]
  notFound: string[]
  totals: ShoppingTotals
  transportCostPerM3: number
  structureId: number | null
  structureName: string | null
  priceError: string | null
  structureAccessible?: boolean
  structureFromCache?: boolean
  structureLastSync?: string | null
}

interface AppraisalResponse {
  items: AppraisalItem[]
  notFound: string[]
  totals: AppraisalTotals
  priceError: string | null
}

interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
  solarSystemId: number | null
}

const { t } = useI18n()
const authStore = useAuthStore()

// Mode toggle
const mode = ref<'import' | 'appraisal'>('appraisal')

// State
const inputText = ref('')
const transportCostPerM3 = ref(1200)
const selectedStructure = ref<{ id: number | null; name: string }>({ id: null, name: '' })
const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
const isLoading = ref(false)
const isSyncing = ref(false)
const isSharing = ref(false)
const shareUrl = ref<string | null>(null)
const error = ref('')
const result = ref<ShoppingListResponse | null>(null)
const appraisalResult = ref<AppraisalResponse | null>(null)

// Computed
const hasInput = computed(() => inputText.value.trim().length > 0)

// Debounced structure search
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(structureSearchQuery, (query) => {
  if (searchTimeout) clearTimeout(searchTimeout)

  if (query.length < 3) {
    structureSearchResults.value = []
    return
  }

  searchTimeout = setTimeout(() => {
    searchStructures(query)
  }, 300)
})

async function searchStructures(query: string) {
  isSearchingStructures.value = true
  try {
    const response = await authFetch(`/api/shopping-list/search-structures?q=${encodeURIComponent(query)}`, {
      headers: {
        'Authorization': `Bearer ${authStore.token}`,
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    if (response.ok) {
      const data = await safeJsonParse<{ results: StructureSearchResult[] }>(response)
      structureSearchResults.value = data.results
      showStructureDropdown.value = true
    }
  } catch (e) {
    console.error('Structure search failed:', e)
  } finally {
    isSearchingStructures.value = false
  }
}

function selectStructure(structure: StructureSearchResult) {
  selectedStructure.value = { id: structure.id, name: structure.name }
  structureSearchQuery.value = ''
  structureSearchResults.value = []
  showStructureDropdown.value = false
}

function clearStructure() {
  selectedStructure.value = { id: null, name: '' }
  structureSearchQuery.value = ''
  structureSearchResults.value = []
}

function onStructureInputBlur() {
  setTimeout(() => {
    showStructureDropdown.value = false
  }, 200)
}

async function parseItems() {
  if (!hasInput.value) return

  isLoading.value = true
  error.value = ''
  result.value = null
  shareUrl.value = null

  try {
    const response = await authFetch('/api/shopping-list/parse', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${authStore.token}`,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        text: inputText.value,
        transportCost: transportCostPerM3.value,
        structureId: selectedStructure.value.id,
      }),
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response)
      throw new Error(data.error || 'Failed to parse items')
    }

    result.value = await safeJsonParse<ShoppingListResponse>(response)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'An error occurred'
  } finally {
    isLoading.value = false
  }
}

function clear() {
  inputText.value = ''
  result.value = null
  appraisalResult.value = null
  error.value = ''
  shareUrl.value = null
}

async function appraise() {
  if (!hasInput.value) return

  isLoading.value = true
  error.value = ''
  appraisalResult.value = null

  try {
    const response = await authFetch('/api/shopping-list/appraise', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${authStore.token}`,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        text: inputText.value,
      }),
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response)
      throw new Error(data.error || 'Appraisal failed')
    }

    appraisalResult.value = await safeJsonParse<AppraisalResponse>(response)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'An error occurred'
  } finally {
    isLoading.value = false
  }
}

async function syncStructureMarket() {
  if (!result.value) return

  isSyncing.value = true
  try {
    const response = await authFetch('/api/shopping-list/sync-structure-market', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${authStore.token}`,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        structureId: result.value.structureId || selectedStructure.value.id,
      }),
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response)
      throw new Error(data.error || 'Sync failed')
    }

    // Refresh prices after sync
    await parseItems()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Sync failed'
  } finally {
    isSyncing.value = false
  }
}

async function shareList() {
  if (!result.value) return

  isSharing.value = true
  shareUrl.value = null

  try {
    const response = await authFetch('/api/shopping-list/share', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${authStore.token}`,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        items: result.value.items,
        notFound: result.value.notFound,
        totals: result.value.totals,
        transportCostPerM3: result.value.transportCostPerM3,
        structureId: result.value.structureId,
        structureName: result.value.structureName,
      }),
    })

    if (!response.ok) {
      const data = await safeJsonParse<{ error?: string }>(response)
      throw new Error(data.error || 'Failed to create share link')
    }

    const data = await safeJsonParse<{ shareUrl: string }>(response)
    shareUrl.value = data.shareUrl

    // Auto-copy to clipboard
    if (data.shareUrl) {
      navigator.clipboard.writeText(data.shareUrl)
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to share'
  } finally {
    isSharing.value = false
  }
}
</script>

<template>
  <MainLayout>
    <div class="space-y-6">
      <!-- Header with mode toggle -->
      <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
          <h1
            class="text-2xl font-bold text-transparent bg-clip-text"
            :class="mode === 'import' ? 'bg-linear-to-r from-cyan-400 to-blue-400' : 'bg-linear-to-r from-amber-400 to-yellow-400'"
          >
            {{ t('shopping.title') }}
          </h1>
          <p class="text-slate-400 text-sm mt-1">
            {{ mode === 'import' ? t('shopping.subtitle') : t('appraisal.subtitle') }}
          </p>
        </div>
        <div class="flex bg-slate-800 border border-slate-600/50 rounded-lg p-0.5 gap-0.5">
          <button
            @click="mode = 'appraisal'"
            :class="[
              'px-5 py-1.5 rounded-md text-[13px] font-medium transition-all flex items-center gap-1.5',
              mode === 'appraisal'
                ? 'bg-gradient-to-r from-amber-700 to-amber-600 text-white shadow-md shadow-amber-500/25'
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-700/30'
            ]"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
            {{ t('shopping.appraisalTab') }}
          </button>
          <button
            @click="mode = 'import'"
            :class="[
              'px-5 py-1.5 rounded-md text-[13px] font-medium transition-all flex items-center gap-1.5',
              mode === 'import'
                ? 'bg-gradient-to-r from-cyan-700 to-cyan-600 text-white shadow-md shadow-cyan-500/25'
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-700/30'
            ]"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            {{ t('shopping.importTab') }}
          </button>
        </div>
      </div>

      <!-- Input Section -->
      <div
        class="bg-slate-900 rounded-xl p-6 border"
        :class="mode === 'import' ? 'border-cyan-500/20' : 'border-amber-500/20'"
      >
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">
              {{ t('shopping.pasteLabel') }}
            </label>
            <textarea
              v-model="inputText"
              rows="8"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-3 text-slate-200 placeholder-slate-500 focus:outline-hidden focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/50 font-mono text-sm"
              placeholder="Tritanium&#9;10000
Pyerite&#9;5000
Megacyte 100
200x Nocxium
..."
            ></textarea>
            <p class="text-xs text-slate-500 mt-2">
              {{ t('shopping.supportedFormats') }}
            </p>
          </div>

          <div class="flex flex-wrap items-end gap-4">
            <!-- Structure selector with search (import mode only) -->
            <template v-if="mode === 'import'">
              <div class="flex-1 min-w-[300px] max-w-[450px]">
                <label class="block text-sm font-medium text-slate-300 mb-1">
                  {{ t('shopping.structureLabel') }}
                </label>

                <div class="relative">
                  <input
                    v-model="structureSearchQuery"
                    type="text"
                    :placeholder="selectedStructure.id ? selectedStructure.name : 'C-J6MT - 1st Taj Mahgoon (defaut)'"
                    @focus="showStructureDropdown = true"
                    @blur="onStructureInputBlur"
                    :class="[
                      'w-full rounded-lg pl-3 pr-10 py-2 focus:outline-hidden',
                      selectedStructure.id
                        ? 'bg-slate-800 border-2 border-cyan-500/50 text-cyan-400 placeholder-cyan-400'
                        : 'bg-slate-800 border border-slate-700 text-slate-200 placeholder-slate-400 focus:border-cyan-500/50'
                    ]"
                  />

                  <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-1">
                    <svg v-if="isSearchingStructures" class="w-4 h-4 animate-spin text-cyan-400" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <button
                      v-else-if="selectedStructure.id || structureSearchQuery"
                      @mousedown.prevent="clearStructure"
                      class="text-slate-400 hover:text-slate-200"
                      title="Revenir au defaut"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </div>

                  <!-- Dropdown -->
                  <div
                    v-if="showStructureDropdown && (structureSearchResults.length > 0 || (structureSearchQuery.length >= 3 && !isSearchingStructures))"
                    class="absolute z-50 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-60 overflow-y-auto"
                  >
                    <button
                      v-for="struct in structureSearchResults"
                      :key="struct.id"
                      @mousedown.prevent="selectStructure(struct)"
                      class="w-full px-3 py-2 text-left text-slate-200 hover:bg-slate-700/50 transition-colors border-b border-slate-800 last:border-0"
                    >
                      <div class="font-medium truncate">{{ struct.name }}</div>
                    </button>

                    <div
                      v-if="structureSearchQuery.length >= 3 && structureSearchResults.length === 0 && !isSearchingStructures"
                      class="px-3 py-2 text-slate-400 text-sm"
                    >
                      {{ t('shopping.noStructureFound') }}
                    </div>
                  </div>
                </div>
              </div>

              <!-- Transport cost -->
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1">
                  {{ t('shopping.transportCost') }}
                </label>
                <input
                  v-model.number="transportCostPerM3"
                  type="number"
                  min="0"
                  step="100"
                  class="w-28 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 focus:outline-hidden focus:border-cyan-500/50"
                />
              </div>
            </template>

            <div class="flex-1"></div>

            <button
              @click="clear"
              :disabled="!hasInput && !result && !appraisalResult"
              class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-slate-200 text-sm font-medium transition-colors"
            >
              {{ t('common.actions.clear') }}
            </button>

            <!-- Import: Calculate button -->
            <button
              v-if="mode === 'import'"
              @click="parseItems"
              :disabled="!hasInput || isLoading"
              class="px-6 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
            >
              <svg v-if="isLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ t('shopping.calculate') }}
            </button>

            <!-- Appraisal: Appraise button -->
            <button
              v-if="mode === 'appraisal'"
              @click="appraise"
              :disabled="!hasInput || isLoading"
              class="px-6 py-2 bg-gradient-to-r from-amber-700 to-amber-600 hover:from-amber-600 hover:to-amber-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
            >
              <svg v-if="isLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
              {{ isLoading ? t('appraisal.appraising') : t('appraisal.appraise') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Error -->
      <div v-if="error" class="bg-red-900/30 border border-red-500/30 rounded-xl p-4 text-red-400">
        {{ error }}
      </div>

      <!-- Shopping Results -->
      <ShoppingListResults
        v-if="mode === 'import' && result"
        :items="result.items"
        :totals="result.totals"
        :structure-name="result.structureName || 'C-J6MT Keepstar'"
        :not-found="result.notFound"
        :price-error="result.priceError"
        :structure-accessible="result.structureAccessible"
        :structure-from-cache="result.structureFromCache"
        :structure-last-sync="result.structureLastSync"
        :is-syncing="isSyncing"
        :is-sharing="isSharing"
        :share-url="shareUrl"
        @sync-structure="syncStructureMarket"
        @share="shareList"
      />

      <!-- Appraisal Results -->
      <AppraisalResults
        v-if="mode === 'appraisal' && appraisalResult"
        :items="appraisalResult.items"
        :totals="appraisalResult.totals"
        :not-found="appraisalResult.notFound"
        :price-error="appraisalResult.priceError"
      />
    </div>
  </MainLayout>
</template>
