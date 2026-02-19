<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { CostEstimation, CopyCosts } from '@/stores/industry/types'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const props = defineProps<{
  projectId: string
}>()

const emit = defineEmits<{
  'switch-tab': [tab: string]
}>()

const { t } = useI18n()
const store = useIndustryStore()
const { formatIsk, formatIskFull, formatTimeSince } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const loading = ref(false)
const error = ref<string | null>(null)
const costData = ref<CostEstimation | null>(null)
const copyCostsData = ref<CopyCosts | null>(null)
const copyCostsLoading = ref(false)
const materialsExpanded = ref(false)
const savingCosts = ref(false)
const refreshing = ref(false)
const lastFetchedAt = ref<string | null>(null)

const MATERIALS_COLLAPSED_LIMIT = 12

// Derive activity type from project steps by stepId
function getActivityTypeForStep(stepId: string): string {
  const step = store.currentProject?.steps?.find(s => s.id === stepId)
  return step?.activityType ?? 'manufacturing'
}

// Computed: displayed materials (collapsed vs expanded)
const displayedMaterials = computed(() => {
  if (!costData.value) return []
  if (materialsExpanded.value || costData.value.materials.length <= MATERIALS_COLLAPSED_LIMIT) {
    return costData.value.materials
  }
  return costData.value.materials.slice(0, MATERIALS_COLLAPSED_LIMIT)
})

const hiddenMaterialsCount = computed(() => {
  if (!costData.value) return 0
  const total = costData.value.materials.length
  if (total <= MATERIALS_COLLAPSED_LIMIT) return 0
  return total - MATERIALS_COLLAPSED_LIMIT
})

const hiddenMaterialsTotal = computed(() => {
  if (!costData.value || hiddenMaterialsCount.value === 0) return 0
  return costData.value.materials
    .slice(MATERIALS_COLLAPSED_LIMIT)
    .reduce((sum, m) => sum + m.totalPrice, 0)
})

// Cost breakdown percentages
const materialPercent = computed(() => {
  if (!costData.value || costData.value.totalCost === 0) return 0
  return (costData.value.materialCost / costData.value.totalCost) * 100
})

const jobInstallPercent = computed(() => {
  if (!costData.value || costData.value.totalCost === 0) return 0
  return (costData.value.jobInstallCost / costData.value.totalCost) * 100
})

const bpcPercent = computed(() => {
  if (!costData.value || costData.value.totalCost === 0) return 0
  return (costData.value.bpoCost / costData.value.totalCost) * 100
})

// Sell price comparison
const project = computed(() => store.currentProject)
const sellPrice = computed(() => project.value?.sellPrice ?? null)
const runs = computed(() => project.value?.runs ?? 1)

const profitAmount = computed(() => {
  if (sellPrice.value == null || !costData.value) return null
  return sellPrice.value - costData.value.totalCost
})

const profitMargin = computed(() => {
  if (sellPrice.value == null || sellPrice.value === 0 || profitAmount.value == null) return null
  return (profitAmount.value / sellPrice.value) * 100
})

async function loadCostEstimation() {
  loading.value = true
  error.value = null
  try {
    const data = await store.fetchCostEstimation(props.projectId)
    costData.value = data
    lastFetchedAt.value = new Date().toISOString()
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('industry.costEstimation.error')
  } finally {
    loading.value = false
  }
}

async function refreshPrices() {
  refreshing.value = true
  try {
    await loadCostEstimation()
  } finally {
    refreshing.value = false
  }
}

async function saveCostsToProject() {
  if (!costData.value || !store.currentProject) return
  savingCosts.value = true
  try {
    await store.updateProject(props.projectId, {
      materialCost: Math.ceil(costData.value.materialCost),
      jobsCost: Math.ceil(costData.value.jobInstallCost),
    } as never)
  } finally {
    savingCosts.value = false
  }
}

function goToInvention() {
  emit('switch-tab', 'invention')
}

async function loadCopyCosts() {
  copyCostsLoading.value = true
  try {
    copyCostsData.value = await store.fetchCopyCosts(props.projectId)
  } catch {
    // Non-critical, just skip
  } finally {
    copyCostsLoading.value = false
  }
}

// Expose activate for lazy loading from parent
async function activate() {
  if (!costData.value) {
    await loadCostEstimation()
  }
  if (!copyCostsData.value) {
    await loadCopyCosts()
  }
}

defineExpose({ activate, loadCostEstimation })
</script>

<template>
  <div>
    <!-- Loading state -->
    <div v-if="loading" class="text-center py-8 text-slate-500">
      <svg class="w-6 h-6 animate-spin mx-auto mb-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      {{ t('industry.costEstimation.loading') }}
    </div>

    <!-- Error state -->
    <div v-else-if="error" class="text-center py-8">
      <div class="bg-red-900/20 border border-red-500/30 rounded-xl p-6 max-w-md mx-auto">
        <svg class="w-10 h-10 mx-auto mb-3 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <p class="text-red-400 font-medium mb-2">{{ t('industry.costEstimation.error') }}</p>
        <p class="text-slate-400 text-sm mb-4">{{ error }}</p>
        <button
          @click="loadCostEstimation"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 mx-auto"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ t('common.actions.retry') }}
        </button>
      </div>
    </div>

    <!-- Main content -->
    <div v-else-if="costData" class="space-y-4">

      <!-- Summary Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Material Cost -->
        <div class="eve-card p-4">
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.costEstimation.materialCost') }}</p>
          <p class="text-xl font-mono text-slate-100 font-semibold">{{ costData.materialCost.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</p>
          <p class="text-xs text-slate-600 mt-1">{{ t('industry.costEstimation.fromShoppingTab') }}</p>
        </div>
        <!-- Job Install Cost -->
        <div class="eve-card p-4">
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.costEstimation.jobInstallCost') }}</p>
          <p class="text-xl font-mono text-slate-100 font-semibold">{{ costData.jobInstallCost.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</p>
          <p class="text-xs text-slate-600 mt-1">{{ t('industry.costEstimation.estimatedFromEsi') }}</p>
        </div>
        <!-- Invention Cost (only for T2) -->
        <div v-if="project?.isT2" class="eve-card p-4">
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.costEstimation.inventionCost') }}</p>
          <p class="text-xl font-mono text-slate-100 font-semibold">{{ costData.bpoCost.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</p>
          <p class="text-xs text-slate-600 mt-1">{{ t('industry.costEstimation.fromInventionTab') }}</p>
        </div>
        <!-- Total Production Cost -->
        <div class="eve-card p-4 border-cyan-500/30">
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-2">{{ t('industry.costEstimation.totalProductionCost') }}</p>
          <p class="text-xl font-mono text-cyan-400 font-bold">{{ costData.totalCost.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</p>
          <p class="text-xs text-slate-500 font-mono mt-1">{{ t('industry.costEstimation.perUnit') }}: <span class="text-slate-400">{{ costData.perUnit.toLocaleString(undefined, { maximumFractionDigits: 0 }) }} ISK</span></p>
        </div>
      </div>

      <!-- Section: Material Cost Breakdown -->
      <div class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.costEstimation.materialCostBreakdown') }}</h4>
          </div>
          <span class="text-xs text-slate-600">{{ t('industry.costEstimation.pricesFromJita') }}</span>
        </div>

        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-left py-2.5 px-4">{{ t('industry.costEstimation.material') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.costEstimation.quantity') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.costEstimation.unitPrice') }}</th>
              <th class="text-right py-2.5 px-4">{{ t('industry.costEstimation.total') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="mat in displayedMaterials"
              :key="mat.typeId"
              class="hover:bg-slate-800/50"
            >
              <td class="py-2.5 px-4">
                <div class="flex items-center gap-2">
                  <div class="w-5 h-5 rounded-sm bg-slate-800 border border-slate-700 overflow-hidden shrink-0">
                    <img
                      :src="getTypeIconUrl(mat.typeId, 32)"
                      alt=""
                      class="w-full h-full"
                      @error="onImageError"
                    />
                  </div>
                  <span class="text-slate-200">{{ mat.typeName }}</span>
                </div>
              </td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ mat.quantity.toLocaleString() }}</td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-400">{{ mat.unitPrice.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</td>
              <td class="py-2.5 px-4 text-right font-mono text-slate-200">{{ mat.totalPrice.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</td>
            </tr>
            <!-- Collapsed items hint -->
            <tr v-if="hiddenMaterialsCount > 0 && !materialsExpanded" class="hover:bg-slate-800/50">
              <td class="py-2 px-4" colspan="3">
                <button
                  @click="materialsExpanded = true"
                  class="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-400 transition-colors"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                  {{ t('industry.costEstimation.moreItems', { count: hiddenMaterialsCount }) }}
                </button>
              </td>
              <td class="py-2 px-4 text-right font-mono text-slate-500">{{ hiddenMaterialsTotal.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</td>
            </tr>
            <!-- Collapse button when expanded -->
            <tr v-if="materialsExpanded && costData.materials.length > MATERIALS_COLLAPSED_LIMIT" class="hover:bg-slate-800/50">
              <td class="py-2 px-4" colspan="4">
                <button
                  @click="materialsExpanded = false"
                  class="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-400 transition-colors"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                  {{ t('industry.costEstimation.collapse') }}
                </button>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t border-slate-700 bg-slate-800/30">
              <td colspan="3" class="py-3 px-4 text-right text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.costEstimation.totalMaterialCost') }}</td>
              <td class="py-3 px-4 text-right font-mono text-slate-100 font-bold text-base">{{ formatIskFull(costData.materialCost) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Section: Job Install Costs -->
      <div class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.costEstimation.jobInstallCosts') }}</h4>
        </div>

        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-center py-2.5 px-3 w-12">#</th>
              <th class="text-left py-2.5 px-3">{{ t('industry.costEstimation.product') }}</th>
              <th class="text-left py-2.5 px-3">{{ t('industry.costEstimation.activity') }}</th>
              <th class="text-left py-2.5 px-3">{{ t('industry.costEstimation.system') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.costEstimation.costIndex') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.costEstimation.runs') }}</th>
              <th class="text-right py-2.5 px-4">{{ t('industry.costEstimation.installCost') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="(step, index) in costData.jobInstallSteps"
              :key="step.stepId"
              class="hover:bg-slate-800/50"
            >
              <td class="py-2.5 px-3 text-center text-slate-600 font-mono text-xs">{{ index + 1 }}</td>
              <td class="py-2.5 px-3">
                <div class="flex items-center gap-2">
                  <div class="w-5 h-5 rounded-sm bg-slate-800 border border-slate-700 overflow-hidden shrink-0">
                    <img
                      :src="getTypeIconUrl(step.productTypeId, 32)"
                      alt=""
                      class="w-full h-full"
                      @error="onImageError"
                    />
                  </div>
                  <span class="text-slate-200">{{ step.productName }}</span>
                </div>
              </td>
              <td class="py-2.5 px-3">
                <span
                  :class="[
                    'text-xs px-1.5 py-0.5 rounded-sm',
                    getActivityTypeForStep(step.stepId) === 'reaction'
                      ? 'bg-purple-500/10 text-purple-400'
                      : 'bg-cyan-500/10 text-cyan-400'
                  ]"
                >
                  {{ getActivityTypeForStep(step.stepId) === 'reaction'
                    ? t('industry.costEstimation.reaction')
                    : t('industry.costEstimation.manufacturing')
                  }}
                </span>
              </td>
              <td class="py-2.5 px-3 text-slate-300">{{ step.systemName }}</td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-400">{{ (step.costIndex * 100).toFixed(2) }}%</td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ step.runs }}</td>
              <td class="py-2.5 px-4 text-right font-mono text-slate-200">{{ step.installCost.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t border-slate-700 bg-slate-800/30">
              <td colspan="6" class="py-3 px-4 text-right text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.costEstimation.totalJobInstallCost') }}</td>
              <td class="py-3 px-4 text-right font-mono text-slate-100 font-bold text-base">{{ formatIskFull(costData.jobInstallCost) }}</td>
            </tr>
          </tfoot>
        </table>

        <!-- Info note -->
        <div class="px-4 py-3 bg-cyan-900/10 border-t border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-xs text-cyan-300/70">{{ t('industry.costEstimation.jobInstallNote') }}</p>
        </div>
      </div>

      <!-- Section: BPC Copy Costs -->
      <div v-if="copyCostsData && copyCostsData.copies.length > 0" class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
          </svg>
          <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.costEstimation.bpcCopyCosts') }}</h4>
        </div>

        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-left py-2.5 px-4">{{ t('industry.bpcKitTab.blueprint') }}</th>
              <th class="text-center py-2.5 px-3">{{ t('industry.costEstimation.depth') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.costEstimation.runs') }}</th>
              <th class="text-right py-2.5 px-4">{{ t('industry.costEstimation.installCost') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="copy in copyCostsData.copies"
              :key="copy.blueprintTypeId + '-' + copy.depth"
              class="hover:bg-slate-800/50"
            >
              <td class="py-2.5 px-4">
                <div class="flex items-center gap-2">
                  <div class="w-5 h-5 rounded-sm bg-slate-800 border border-slate-700 overflow-hidden shrink-0">
                    <img
                      :src="getTypeIconUrl(copy.blueprintTypeId, 32)"
                      alt=""
                      class="w-full h-full"
                      @error="onImageError"
                    />
                  </div>
                  <span class="text-slate-200">{{ copy.blueprintName }}</span>
                </div>
              </td>
              <td class="py-2.5 px-3 text-center font-mono text-slate-500">{{ copy.depth }}</td>
              <td class="py-2.5 px-3 text-right font-mono text-slate-300">{{ copy.runs }}</td>
              <td class="py-2.5 px-4 text-right font-mono text-slate-200">{{ formatIskFull(copy.cost) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t border-slate-700 bg-slate-800/30">
              <td colspan="3" class="py-3 px-4 text-right text-xs text-slate-500 uppercase tracking-wider">{{ t('industry.costEstimation.totalCopyCost') }}</td>
              <td class="py-3 px-4 text-right font-mono text-slate-100 font-bold text-base">{{ formatIskFull(copyCostsData.totalCopyCost) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Loading state for copy costs -->
      <div v-else-if="copyCostsLoading" class="eve-card p-4 flex items-center gap-2 text-sm text-slate-500">
        <svg class="w-4 h-4 animate-spin text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ t('common.status.loading') }}
      </div>

      <!-- Section: Invention Cost (only for T2) -->
      <div v-if="project?.isT2" class="eve-card p-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <div>
              <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.costEstimation.inventionCostSection') }}</h4>
              <p class="text-xs text-slate-500">{{ t('industry.costEstimation.inventionCostSubtitle') }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <span class="font-mono text-lg text-slate-100 font-semibold">{{ formatIskFull(costData.bpoCost) }}</span>
            <button
              @click="goToInvention"
              class="p-1.5 hover:bg-slate-800 rounded-sm text-slate-500 hover:text-cyan-400 transition-colors"
              :title="t('industry.costEstimation.goToInvention')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Section: Sell Price Comparison (only show if sell price is set) -->
      <div v-if="sellPrice != null && sellPrice > 0 && !project?.personalUse" class="eve-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800 flex items-center gap-2">
          <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <h4 class="text-sm font-semibold text-slate-200">{{ t('industry.costEstimation.sellPriceComparison') }}</h4>
          <span class="text-xs text-slate-600 ml-2">{{ runs }} unit{{ runs > 1 ? 's' : '' }}</span>
        </div>

        <div class="p-4">
          <div class="grid grid-cols-4 gap-6 text-center">
            <div>
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costEstimation.totalCostLabel') }}</div>
              <div class="font-mono text-slate-200 text-lg">{{ costData.totalCost.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costEstimation.sellPriceLabel') }}</div>
              <div class="font-mono text-slate-200 text-lg">{{ sellPrice.toLocaleString(undefined, { maximumFractionDigits: 0 }) }}</div>
              <div v-if="runs > 1" class="text-xs text-slate-600 font-mono">{{ t('industry.costEstimation.perUnitShort', { price: formatIsk(sellPrice / runs) }) }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costEstimation.profitLabel') }}</div>
              <div
                class="font-mono text-lg font-bold"
                :class="profitAmount != null && profitAmount >= 0 ? 'text-emerald-400' : 'text-red-400'"
              >
                {{ profitAmount != null ? (profitAmount >= 0 ? '+' : '') + profitAmount.toLocaleString(undefined, { maximumFractionDigits: 0 }) : '---' }}
              </div>
            </div>
            <div>
              <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costEstimation.marginLabel') }}</div>
              <div
                class="font-mono text-xl font-bold"
                :class="profitMargin != null && profitMargin >= 0 ? 'text-emerald-400' : 'text-red-400'"
              >
                {{ profitMargin != null ? profitMargin.toFixed(1) + '%' : '---' }}
              </div>
            </div>
          </div>

          <!-- Breakdown bar -->
          <div class="mt-4 pt-4 border-t border-slate-800">
            <div class="flex items-center gap-1 text-xs text-slate-500 mb-2">
              <span>{{ t('industry.costEstimation.costBreakdown') }}</span>
            </div>
            <div class="h-3 rounded-full overflow-hidden flex bg-slate-800">
              <div
                class="bg-blue-500/60"
                :style="{ width: materialPercent + '%' }"
                :title="`${t('industry.costEstimation.materials')}: ${formatIsk(costData.materialCost)} (${materialPercent.toFixed(1)}%)`"
              ></div>
              <div
                class="bg-amber-500/60"
                :style="{ width: jobInstallPercent + '%' }"
                :title="`${t('industry.costEstimation.jobInstall')}: ${formatIsk(costData.jobInstallCost)} (${jobInstallPercent.toFixed(1)}%)`"
              ></div>
              <div
                class="bg-purple-500/60"
                :style="{ width: bpcPercent + '%' }"
                :title="`${t('industry.costEstimation.bpc')}: ${formatIsk(costData.bpoCost)} (${bpcPercent.toFixed(1)}%)`"
              ></div>
            </div>
            <div class="flex items-center gap-4 mt-2 text-xs">
              <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-blue-500/60"></span>
                <span class="text-slate-500">{{ t('industry.costEstimation.materials') }}</span>
                <span class="font-mono text-slate-400">{{ materialPercent.toFixed(1) }}%</span>
              </span>
              <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-amber-500/60"></span>
                <span class="text-slate-500">{{ t('industry.costEstimation.jobInstall') }}</span>
                <span class="font-mono text-slate-400">{{ jobInstallPercent.toFixed(1) }}%</span>
              </span>
              <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-purple-500/60"></span>
                <span class="text-slate-500">{{ t('industry.costEstimation.bpc') }}</span>
                <span class="font-mono text-slate-400">{{ bpcPercent.toFixed(1) }}%</span>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Bottom action bar -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <button
            v-if="project?.status !== 'completed'"
            @click="saveCostsToProject"
            :disabled="savingCosts"
            class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-colors"
          >
            <LoadingSpinner v-if="savingCosts" size="sm" />
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            {{ t('industry.costEstimation.saveCostsToProject') }}
          </button>
          <button
            @click="refreshPrices"
            :disabled="refreshing"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 disabled:opacity-50 rounded-lg text-slate-200 text-sm font-medium flex items-center gap-2 transition-colors"
          >
            <svg
              class="w-4 h-4"
              :class="{ 'animate-spin': refreshing }"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('industry.costEstimation.refreshPrices') }}
          </button>
        </div>
        <div v-if="lastFetchedAt" class="text-xs text-slate-600">
          {{ t('industry.costEstimation.lastUpdated') }}: {{ formatTimeSince(lastFetchedAt) }}
        </div>
      </div>

    </div>
  </div>
</template>
