<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useIndustryStore } from '@/stores/industry'
import { useAuthStore } from '@/stores/auth'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { apiRequest, authFetch, safeJsonParse } from '@/services/api'
import type { ShoppingListItem, ShoppingListTotals } from '@/stores/industry'

interface StructureSearchResult {
  id: number
  name: string
  typeId: number | null
  solarSystemId: number | null
}
import StepTree from './StepTree.vue'
import StepHierarchyTree from './StepHierarchyTree.vue'
import ShoppingListResults from '@/components/shopping/ShoppingListResults.vue'

const props = defineProps<{
  projectId: string
}>()

const emit = defineEmits<{
  close: []
}>()

const store = useIndustryStore()
const authStore = useAuthStore()
const { formatIsk, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const activeTab = ref<'steps' | 'shopping'>('steps')
const stepsViewMode = ref<'flat' | 'tree'>('flat')
const shoppingList = ref<ShoppingListItem[]>([])
const shoppingStructureName = ref('')
const shoppingStructureAccessible = ref(false)
const shoppingStructureFromCache = ref(false)
const shoppingStructureLastSync = ref<string | null>(null)
const shoppingTotals = ref<ShoppingListTotals | null>(null)
const shoppingPriceError = ref<string | null>(null)
const shoppingLoading = ref(false)
const shoppingSyncing = ref(false)
const transportCostPerM3 = ref(1200)
const matchJobsLoading = ref(false)
const matchJobsWarning = ref<string | null>(null)

// BPC Kit modal
const showBpcKitModal = ref(false)
const bpcKitPrice = ref('')
const bpcKitLoading = ref(false)

// Structure search for shopping list
const DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon'
const selectedStructure = ref<{ id: number | null; name: string }>({ id: null, name: '' })
const structureSearchQuery = ref('')
const structureSearchResults = ref<StructureSearchResult[]>([])
const isSearchingStructures = ref(false)
const showStructureDropdown = ref(false)
let structureSearchTimeout: ReturnType<typeof setTimeout> | null = null

// Debounced structure search
watch(structureSearchQuery, (query) => {
  if (structureSearchTimeout) clearTimeout(structureSearchTimeout)

  if (query.length < 3) {
    structureSearchResults.value = []
    return
  }

  structureSearchTimeout = setTimeout(() => {
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

onMounted(() => {
  store.fetchProject(props.projectId)
})

async function togglePurchased(stepId: string, purchased: boolean) {
  await store.toggleStepPurchased(props.projectId, stepId, purchased)
  if (activeTab.value === 'shopping') {
    await loadShoppingList()
  }
}

async function matchJobs() {
  matchJobsLoading.value = true
  matchJobsWarning.value = null
  try {
    const warning = await store.matchJobs(props.projectId)
    matchJobsWarning.value = warning
    await store.fetchProject(props.projectId)
  } finally {
    matchJobsLoading.value = false
  }
}

// Round up to nearest million
function roundUpToMillion(value: number): number {
  return Math.ceil(value / 1_000_000) * 1_000_000
}

async function loadShoppingList() {
  shoppingLoading.value = true
  shoppingPriceError.value = null
  try {
    const structureId = selectedStructure.value.id ?? undefined
    const response = await store.fetchShoppingList(props.projectId, structureId, transportCostPerM3.value)
    if (response) {
      shoppingList.value = response.materials
      shoppingStructureName.value = response.structureName
      shoppingStructureAccessible.value = response.structureAccessible
      shoppingStructureFromCache.value = response.structureFromCache
      shoppingStructureLastSync.value = response.structureLastSync
      shoppingTotals.value = response.totals
      shoppingPriceError.value = response.priceError

      // Auto-fill materialCost if not set and best price available
      if (
        store.currentProject &&
        (store.currentProject.materialCost === null || store.currentProject.materialCost === 0) &&
        response.totals.best > 0
      ) {
        const roundedCost = roundUpToMillion(response.totals.best)
        await store.updateProject(props.projectId, { materialCost: roundedCost })
      }
    } else {
      shoppingList.value = []
      shoppingTotals.value = null
      shoppingStructureAccessible.value = false
      shoppingPriceError.value = store.error
    }
  } catch (e) {
    shoppingList.value = []
    shoppingTotals.value = null
    shoppingStructureAccessible.value = false
    shoppingPriceError.value = e instanceof Error ? e.message : 'Erreur lors du chargement'
  } finally {
    shoppingLoading.value = false
  }
}

async function syncStructureMarket() {
  shoppingSyncing.value = true
  try {
    const structureId = selectedStructure.value.id ?? undefined
    await apiRequest('/shopping-list/sync-structure-market', {
      method: 'POST',
      body: JSON.stringify({ structureId }),
    })

    // Refresh shopping list after sync
    await loadShoppingList()
  } catch (e) {
    shoppingPriceError.value = e instanceof Error ? e.message : 'Sync failed'
  } finally {
    shoppingSyncing.value = false
  }
}

// Apply shopping list best total as materialCost
async function applyAsMaterialCost() {
  if (!shoppingTotals.value || !store.currentProject) return
  const roundedCost = roundUpToMillion(shoppingTotals.value.best)
  await store.updateProject(props.projectId, { materialCost: roundedCost })
}

async function switchTab(tab: 'steps' | 'shopping') {
  activeTab.value = tab
  // Don't auto-load shopping list for completed projects to avoid unnecessary API calls
  if (tab === 'shopping' && shoppingList.value.length === 0 && store.currentProject?.status !== 'completed') {
    await loadShoppingList()
  }
}

async function deleteStepHandler(stepId: string) {
  await store.deleteStep(props.projectId, stepId)
}

async function addChildJobHandler(splitGroupId: string | null, stepId: string | null, runs: number) {
  await store.addChildJob(props.projectId, splitGroupId, stepId, runs)
}

async function updateStepRuns(stepId: string, runs: number) {
  await store.updateStepRuns(props.projectId, stepId, runs)
}

async function toggleProjectStatus() {
  if (!store.currentProject) return
  const newStatus = store.currentProject.status === 'completed' ? 'active' : 'completed'
  await store.updateProject(props.projectId, { status: newStatus })
  // Switch to steps tab when marking as completed (shopping tab is disabled)
  if (newStatus === 'completed') {
    activeTab.value = 'steps'
  }
}

function openBpcKitModal() {
  bpcKitPrice.value = ''
  showBpcKitModal.value = true
}

function closeBpcKitModal() {
  showBpcKitModal.value = false
  bpcKitPrice.value = ''
}

async function confirmBpcKit() {
  if (!store.currentProject?.steps) return

  bpcKitLoading.value = true
  try {
    // Parse the price using the ISK parser pattern
    let kitPrice: number | null = null
    if (bpcKitPrice.value.trim()) {
      const normalized = bpcKitPrice.value.trim().toLowerCase().replace(/,/g, '.')
      const match = normalized.match(/^([\d.]+)\s*([kmb])?$/)
      if (match) {
        let value = parseFloat(match[1])
        const suffix = match[2]
        if (suffix === 'k') value *= 1_000
        else if (suffix === 'm') value *= 1_000_000
        else if (suffix === 'b') value *= 1_000_000_000
        kitPrice = Math.round(value)
      }
    }

    // Mark all BPC steps as purchased
    const bpcSteps = store.currentProject.steps.filter(s => s.activityType === 'copy')
    for (const step of bpcSteps) {
      if (!step.purchased) {
        await store.toggleStepPurchased(props.projectId, step.id, true)
      }
    }

    // Update bpoCost if price was provided
    if (kitPrice !== null && kitPrice > 0) {
      await store.updateProject(props.projectId, { bpoCost: kitPrice })
    }

    closeBpcKitModal()
  } finally {
    bpcKitLoading.value = false
  }
}

// Check if there are any BPC steps
function hasBpcSteps(): boolean {
  return store.currentProject?.steps?.some(s => s.activityType === 'copy') ?? false
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
      <div class="flex items-center gap-4">
        <button
          @click="emit('close')"
          class="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-200"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </button>
        <div v-if="store.currentProject" class="flex items-center gap-3">
          <img
            :src="getTypeIconUrl(store.currentProject.productTypeId, 64)"
            class="w-10 h-10 rounded"
            @error="onImageError"
          />
          <div>
            <h3 class="text-lg font-semibold text-slate-100">
              {{ store.currentProject.productTypeName }}
            </h3>
            <p class="text-sm text-slate-500">
              {{ store.currentProject.runs }} runs - ME {{ store.currentProject.meLevel }}
              - Créé le {{ formatDateTime(store.currentProject.createdAt) }}
            </p>
          </div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button
          v-if="store.currentProject?.status !== 'completed'"
          @click="matchJobs"
          :disabled="matchJobsLoading"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-cyan-800 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium flex items-center gap-2"
        >
          <svg v-if="matchJobsLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
          </svg>
          {{ matchJobsLoading ? 'Synchronisation...' : 'Lier jobs ESI' }}
        </button>
        <button
          @click="toggleProjectStatus"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2',
            store.currentProject?.status === 'completed'
              ? 'bg-amber-600 hover:bg-amber-500 text-white'
              : 'bg-emerald-600 hover:bg-emerald-500 text-white'
          ]"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              v-if="store.currentProject?.status === 'completed'"
              stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
            <path
              v-else
              stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M5 13l4 4L19 7"
            />
          </svg>
          {{ store.currentProject?.status === 'completed' ? 'Réactiver' : 'Terminé' }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="store.isLoading" class="p-8 text-center text-slate-500">
      <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      Chargement...
    </div>

    <!-- Content -->
    <div v-else-if="store.currentProject" class="p-6">
      <!-- ESI Warning -->
      <div
        v-if="matchJobsWarning"
        class="mb-4 p-3 bg-amber-500/20 border border-amber-500/50 rounded-lg flex items-center gap-3"
      >
        <svg class="w-5 h-5 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <span class="text-amber-200 text-sm">{{ matchJobsWarning }}</span>
        <button
          @click="matchJobsWarning = null"
          class="ml-auto p-1 hover:bg-amber-500/30 rounded text-amber-400"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Coût total</p>
          <p class="text-lg font-mono text-slate-200 mt-1">
            {{ formatIsk(store.currentProject.totalCost) }}
          </p>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Coût jobs</p>
          <p class="text-lg font-mono text-slate-200 mt-1">
            {{ formatIsk(store.currentProject.jobsCost) }}
          </p>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Prix de vente</p>
          <p class="text-lg font-mono mt-1" :class="store.currentProject.personalUse ? 'text-slate-600' : 'text-slate-200'">
            {{ store.currentProject.personalUse ? 'N/A' : (store.currentProject.sellPrice !== null ? formatIsk(store.currentProject.sellPrice) : '-') }}
          </p>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700/50">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Profit</p>
          <p
            class="text-lg font-mono mt-1"
            :class="
              store.currentProject.personalUse
                ? 'text-slate-600'
                : store.currentProject.profit !== null
                  ? store.currentProject.profit >= 0
                    ? 'text-emerald-400'
                    : 'text-red-400'
                  : 'text-slate-400'
            "
          >
            {{
              store.currentProject.personalUse
                ? 'N/A'
                : store.currentProject.profit !== null
                  ? formatIsk(store.currentProject.profit)
                  : '-'
            }}
          </p>
        </div>
      </div>

      <!-- Tabs -->
      <div class="flex gap-4 mb-4 border-b border-slate-700">
        <button
          @click="switchTab('steps')"
          :class="[
            'pb-2 text-sm font-medium border-b-2 -mb-px',
            activeTab === 'steps' ? 'border-cyan-500 text-cyan-400' : 'border-transparent text-slate-500 hover:text-slate-300',
          ]"
        >
          Étapes de production
        </button>
        <button
          @click="store.currentProject?.status !== 'completed' && switchTab('shopping')"
          :disabled="store.currentProject?.status === 'completed'"
          :class="[
            'pb-2 text-sm font-medium border-b-2 -mb-px',
            store.currentProject?.status === 'completed'
              ? 'border-transparent text-slate-600 cursor-not-allowed'
              : activeTab === 'shopping' ? 'border-cyan-500 text-cyan-400' : 'border-transparent text-slate-500 hover:text-slate-300',
          ]"
          :title="store.currentProject?.status === 'completed' ? 'Non disponible pour les projets terminés' : ''"
        >
          Liste de courses
        </button>
      </div>

      <!-- Steps tab -->
      <div v-if="activeTab === 'steps'">
        <!-- Toolbar: BPC Kit + View toggle -->
        <div class="mb-4 flex items-center justify-between">
          <!-- BPC Kit button (hidden for completed projects) -->
          <div class="flex items-center gap-4">
            <button
              v-if="hasBpcSteps() && store.currentProject?.status !== 'completed'"
              @click="openBpcKitModal"
              class="flex items-center gap-2 text-sm px-3 py-1.5 bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/50 rounded-lg text-blue-400"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              BPC Kit
            </button>
          </div>

          <!-- View mode toggle -->
          <div class="flex items-center gap-1 bg-slate-800 rounded-lg p-1">
            <button
              @click="stepsViewMode = 'flat'"
              :class="[
                'px-3 py-1.5 text-xs font-medium rounded transition-colors',
                stepsViewMode === 'flat'
                  ? 'bg-cyan-600 text-white'
                  : 'text-slate-400 hover:text-slate-200'
              ]"
            >
              <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
                Liste
              </span>
            </button>
            <button
              @click="stepsViewMode = 'tree'"
              :class="[
                'px-3 py-1.5 text-xs font-medium rounded transition-colors',
                stepsViewMode === 'tree'
                  ? 'bg-cyan-600 text-white'
                  : 'text-slate-400 hover:text-slate-200'
              ]"
            >
              <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h4v4H3V4zm0 8h4v4H3v-4zm0 8h4v4H3v-4zm8-16h10M11 12h10M11 20h10" />
                </svg>
                Arbre
              </span>
            </button>
          </div>
        </div>

        <!-- Steps list (flat view) -->
        <div v-if="stepsViewMode === 'flat'">
          <div v-if="store.currentProject.steps && store.currentProject.steps.length > 0">
            <StepTree
              :steps="store.currentProject.steps"
              :readonly="store.currentProject.status === 'completed'"
              @toggle-purchased="togglePurchased"
              @update-step-runs="updateStepRuns"
              @delete-step="deleteStepHandler"
              @add-child-job="addChildJobHandler"
            />
          </div>
          <div v-else class="text-center py-8 text-slate-500">
            Aucune étape de production
          </div>
        </div>

        <!-- Steps tree (hierarchy view) -->
        <div v-else-if="stepsViewMode === 'tree'">
          <div v-if="store.currentProject.tree && store.currentProject.steps">
            <StepHierarchyTree
              :tree="store.currentProject.tree"
              :steps="store.currentProject.steps"
              :readonly="store.currentProject.status === 'completed'"
              @toggle-purchased="togglePurchased"
            />
          </div>
          <div v-else class="text-center py-8 text-slate-500">
            Arbre de production non disponible
          </div>
        </div>
      </div>

      <!-- Shopping list tab -->
      <div v-if="activeTab === 'shopping'">
        <div v-if="shoppingLoading" class="text-center py-8 text-slate-500">
          <svg class="w-6 h-6 animate-spin mx-auto mb-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Chargement des prix...
        </div>
        <!-- Error state with retry -->
        <div v-else-if="shoppingPriceError && shoppingList.length === 0" class="text-center py-8">
          <div class="bg-red-900/20 border border-red-500/30 rounded-xl p-6 max-w-md mx-auto">
            <svg class="w-10 h-10 mx-auto mb-3 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <p class="text-red-400 font-medium mb-2">Erreur de chargement</p>
            <p class="text-slate-400 text-sm mb-4">{{ shoppingPriceError }}</p>
            <button
              @click="loadShoppingList"
              class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 mx-auto"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              Réessayer
            </button>
          </div>
        </div>
        <div v-else-if="shoppingList.length === 0" class="text-center py-8 text-slate-500">
          Aucun materiau requis
        </div>
        <div v-else>
          <!-- Structure selector + Transport cost (disabled for completed projects) -->
          <div v-if="store.currentProject?.status !== 'completed'" class="mb-4 flex flex-wrap items-center gap-4">
            <!-- Structure selector -->
            <div class="relative min-w-[280px]">
              <label class="block text-xs text-slate-500 mb-1">Structure (avec Jita)</label>
              <div class="relative">
                <input
                  v-model="structureSearchQuery"
                  type="text"
                  :placeholder="selectedStructure.id ? selectedStructure.name : DEFAULT_STRUCTURE_NAME + ' (défaut)'"
                  :class="[
                    'w-full bg-slate-800 border rounded pl-3 pr-8 py-1.5 text-sm focus:outline-none',
                    selectedStructure.id
                      ? 'border-cyan-500/50 text-cyan-400 placeholder-cyan-400'
                      : 'border-slate-600 text-slate-200 placeholder-slate-400 focus:border-cyan-500/50'
                  ]"
                  @focus="showStructureDropdown = true"
                  @blur="onStructureInputBlur"
                />
                <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center">
                  <svg v-if="isSearchingStructures" class="w-4 h-4 animate-spin text-cyan-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <button
                    v-else-if="selectedStructure.id || structureSearchQuery"
                    @mousedown.prevent="clearStructure"
                    class="text-slate-400 hover:text-slate-200"
                    title="Revenir au défaut"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
                <!-- Dropdown -->
                <div
                  v-if="showStructureDropdown && (structureSearchResults.length > 0 || (structureSearchQuery.length >= 3 && !isSearchingStructures))"
                  class="absolute z-50 w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl max-h-48 overflow-y-auto"
                >
                  <button
                    v-for="struct in structureSearchResults"
                    :key="struct.id"
                    @mousedown.prevent="selectStructure(struct)"
                    class="w-full px-3 py-2 text-left text-sm text-slate-200 hover:bg-slate-700/50 transition-colors border-b border-slate-700/50 last:border-0"
                  >
                    <div class="truncate">{{ struct.name }}</div>
                  </button>
                  <div
                    v-if="structureSearchQuery.length >= 3 && structureSearchResults.length === 0 && !isSearchingStructures"
                    class="px-3 py-2 text-slate-400 text-sm"
                  >
                    Aucune structure trouvée
                  </div>
                </div>
              </div>
            </div>

            <!-- Transport cost -->
            <div>
              <label class="block text-xs text-slate-500 mb-1">Transport</label>
              <div class="flex items-center gap-2">
                <input
                  v-model.number="transportCostPerM3"
                  type="number"
                  min="0"
                  step="100"
                  class="w-24 px-2 py-1.5 bg-slate-800 border border-slate-600 rounded text-slate-200 text-sm font-mono focus:outline-none focus:border-cyan-500"
                />
                <span class="text-xs text-slate-500">ISK/m³</span>
              </div>
            </div>

            <!-- Recalculate button -->
            <div class="pt-4">
              <button
                @click="loadShoppingList"
                class="px-4 py-1.5 bg-cyan-600 hover:bg-cyan-500 rounded text-white text-sm font-medium"
              >
                Recalculer
              </button>
            </div>
          </div>

          <!-- Shared shopping list component -->
          <ShoppingListResults
            :items="shoppingList"
            :totals="shoppingTotals!"
            :structure-name="shoppingStructureName"
            :price-error="shoppingPriceError"
            :structure-accessible="shoppingStructureAccessible"
            :structure-from-cache="shoppingStructureFromCache"
            :structure-last-sync="shoppingStructureLastSync"
            :is-syncing="shoppingSyncing"
            :readonly="store.currentProject?.status === 'completed'"
            @sync-structure="syncStructureMarket"
          >
            <!-- Apply as material cost button (between summary and items table) -->
            <template #after-summary>
              <div v-if="shoppingTotals && shoppingTotals.best > 0" class="flex items-center gap-3 p-3 bg-slate-800/50 rounded-lg border border-slate-700">
                <span class="text-sm text-slate-400">
                  Meilleur prix total: <span class="font-mono text-slate-200">{{ formatIsk(shoppingTotals.best) }}</span>
                  → Arrondi au million: <span class="font-mono text-emerald-400">{{ formatIsk(roundUpToMillion(shoppingTotals.best)) }}</span>
                </span>
                <button
                  v-if="store.currentProject?.status !== 'completed'"
                  @click="applyAsMaterialCost"
                  class="ml-auto px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 rounded text-white text-sm font-medium flex items-center gap-2"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  Appliquer comme coût matériaux
                </button>
              </div>
            </template>
          </ShoppingListResults>
        </div>
      </div>
    </div>
  </div>

  <!-- BPC Kit Modal -->
  <Teleport to="body">
    <div
      v-if="showBpcKitModal"
      class="fixed inset-0 z-50 flex items-center justify-center"
    >
      <!-- Backdrop -->
      <div
        class="absolute inset-0 bg-black/70 backdrop-blur-sm"
        @click="closeBpcKitModal"
      ></div>

      <!-- Modal -->
      <div class="relative bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-100 flex items-center gap-2">
            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            BPC Kit
          </h3>
          <button
            @click="closeBpcKitModal"
            class="p-1 hover:bg-slate-800 rounded text-slate-400 hover:text-slate-200"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="px-6 py-4">
          <p class="text-sm text-slate-400 mb-4">
            Cette action va marquer toutes les étapes BPC comme achetées et définir le coût du kit.
          </p>

          <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">
              Prix du kit BPC
            </label>
            <input
              v-model="bpcKitPrice"
              type="text"
              placeholder="ex: 50M, 1.5B, 500000"
              class="w-full px-4 py-3 bg-slate-800 border border-slate-600 rounded-lg text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 font-mono"
              @keydown.enter="confirmBpcKit"
              @keydown.escape="closeBpcKitModal"
              autofocus
            />
            <p class="text-xs text-slate-500 mt-2">
              Formats acceptés : 50M, 1.5B, 500K, 1000000
            </p>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-700 flex justify-end gap-3">
          <button
            @click="closeBpcKitModal"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium transition-colors"
          >
            Annuler
          </button>
          <button
            @click="confirmBpcKit"
            :disabled="bpcKitLoading"
            class="px-4 py-2 bg-blue-600 hover:bg-blue-500 disabled:bg-blue-800 disabled:cursor-not-allowed rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
          >
            <svg v-if="bpcKitLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            Confirmer
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
