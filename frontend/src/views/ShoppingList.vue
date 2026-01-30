<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { authFetch, safeJsonParse } from '@/services/api'
import MainLayout from '@/layouts/MainLayout.vue'
import ShoppingListResults from '@/components/shopping/ShoppingListResults.vue'
import type { ShoppingItem, ShoppingTotals } from '@/components/shopping/ShoppingListResults.vue'

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

interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
  solarSystemId: number | null
}

const authStore = useAuthStore()

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
const error = ref('')
const result = ref<ShoppingListResponse | null>(null)

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
  error.value = ''
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
</script>

<template>
  <MainLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-400">
            Liste de courses
          </h1>
          <p class="text-slate-400 text-sm mt-1">
            Collez une liste d'items pour calculer les prix et volumes
          </p>
        </div>
      </div>

      <!-- Input Section -->
      <div class="bg-slate-900/50 backdrop-blur border border-cyan-500/20 rounded-xl p-6">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">
              Collez votre liste d'items
            </label>
            <textarea
              v-model="inputText"
              rows="8"
              class="w-full bg-slate-800/50 border border-slate-700 rounded-lg px-4 py-3 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500/50 focus:ring-1 focus:ring-cyan-500/50 font-mono text-sm"
              placeholder="Tritanium&#9;10000
Pyerite&#9;5000
Megacyte 100
200x Nocxium
..."
            ></textarea>
            <p class="text-xs text-slate-500 mt-2">
              Formats supportes: "Item Name&#9;123", "Item Name 123", "123x Item Name", "Item Name x 123"
            </p>
          </div>

          <div class="flex flex-wrap items-end gap-4">
            <!-- Structure selector with search -->
            <div class="flex-1 min-w-[300px] max-w-[450px]">
              <label class="block text-sm font-medium text-slate-300 mb-1">
                Structure de comparaison (avec Jita)
              </label>

              <div class="relative">
                <input
                  v-model="structureSearchQuery"
                  type="text"
                  :placeholder="selectedStructure.id ? selectedStructure.name : 'C-J6MT - 1st Taj Mahgoon (défaut)'"
                  @focus="showStructureDropdown = true"
                  @blur="onStructureInputBlur"
                  :class="[
                    'w-full rounded-lg pl-3 pr-10 py-2 focus:outline-none',
                    selectedStructure.id
                      ? 'bg-slate-800/50 border-2 border-cyan-500/50 text-cyan-400 placeholder-cyan-400'
                      : 'bg-slate-800/50 border border-slate-700 text-slate-200 placeholder-slate-400 focus:border-cyan-500/50'
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
                    class="w-full px-3 py-2 text-left text-slate-200 hover:bg-slate-700/50 transition-colors border-b border-slate-700/50 last:border-0"
                  >
                    <div class="font-medium truncate">{{ struct.name }}</div>
                  </button>

                  <div
                    v-if="structureSearchQuery.length >= 3 && structureSearchResults.length === 0 && !isSearchingStructures"
                    class="px-3 py-2 text-slate-400 text-sm"
                  >
                    Aucune structure trouvee (verifiez le dock access)
                  </div>
                </div>
              </div>

            </div>

            <!-- Transport cost -->
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1">
                Transport (ISK/m³)
              </label>
              <input
                v-model.number="transportCostPerM3"
                type="number"
                min="0"
                step="100"
                class="w-28 bg-slate-800/50 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 focus:outline-none focus:border-cyan-500/50"
              />
            </div>

            <div class="flex-1"></div>

            <button
              @click="clear"
              :disabled="!hasInput && !result"
              class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-slate-200 text-sm font-medium transition-colors"
            >
              Effacer
            </button>
            <button
              @click="parseItems"
              :disabled="!hasInput || isLoading"
              class="px-6 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
            >
              <svg v-if="isLoading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Calculer les prix
            </button>
          </div>
        </div>
      </div>

      <!-- Error -->
      <div v-if="error" class="bg-red-900/30 border border-red-500/30 rounded-xl p-4 text-red-400">
        {{ error }}
      </div>

      <!-- Results -->
      <ShoppingListResults
        v-if="result"
        :items="result.items"
        :totals="result.totals"
        :structure-name="result.structureName || 'C-J6MT Keepstar'"
        :not-found="result.notFound"
        :price-error="result.priceError"
        :structure-accessible="result.structureAccessible"
        :structure-from-cache="result.structureFromCache"
        :structure-last-sync="result.structureLastSync"
        :is-syncing="isSyncing"
        @sync-structure="syncStructureMarket"
      />
    </div>
  </MainLayout>
</template>
