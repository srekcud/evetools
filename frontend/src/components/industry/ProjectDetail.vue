<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { parseIskValue } from '@/composables/useIskParser'
import { formatDuration, useProjectTime } from '@/composables/useProjectTime'
import StepsTab from './StepsTab.vue'
import ShoppingTab from './ShoppingTab.vue'

const { t } = useI18n()

const props = defineProps<{
  projectId: string
}>()

const emit = defineEmits<{
  close: []
}>()

const store = useIndustryStore()
const { formatIsk, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const projectRef = computed(() => store.currentProject)
const { estimatedProjectTime } = useProjectTime(projectRef)

const activeTab = ref<'steps' | 'shopping'>('steps')

// Template refs to child components
const stepsTabRef = ref<InstanceType<typeof StepsTab> | null>(null)
const shoppingTabRef = ref<InstanceType<typeof ShoppingTab> | null>(null)

// Project name editing
const editingProjectName = ref(false)
const projectNameEdit = ref('')

function startEditProjectName() {
  projectNameEdit.value = store.currentProject?.name || store.currentProject?.productTypeName || ''
  editingProjectName.value = true
}

async function saveProjectName() {
  if (!editingProjectName.value) return
  editingProjectName.value = false
  const newName = projectNameEdit.value.trim()
  const nameToSave = newName && newName !== store.currentProject?.productTypeName ? newName : null
  if (nameToSave !== store.currentProject?.name) {
    await store.updateProject(props.projectId, { name: nameToSave } as never)
  }
}

function cancelProjectNameEdit() {
  editingProjectName.value = false
}

// Root products for multi-product display
const rootProducts = computed(() => {
  const steps = store.currentProject?.steps
  if (!steps || steps.length === 0) return []
  const productMap = new Map<number, { typeId: number; typeName: string; runs: number; count: number }>()
  for (const step of steps) {
    if (step.depth === 0 && (step.activityType === 'manufacturing' || step.activityType === 'reaction')) {
      const existing = productMap.get(step.productTypeId)
      if (existing) {
        existing.runs += step.runs
        existing.count++
      } else {
        productMap.set(step.productTypeId, {
          typeId: step.productTypeId,
          typeName: step.productTypeName,
          runs: step.runs,
          count: 1,
        })
      }
    }
  }
  return Array.from(productMap.values())
})

const totalRootStepCount = computed(() => rootProducts.value.reduce((sum, p) => sum + p.count, 0))
const isMultiProduct = computed(() => rootProducts.value.length > 1 || totalRootStepCount.value > 1)

// Costs panel data from child components
const shoppingTotals = computed(() => shoppingTabRef.value?.shoppingTotals ?? null)
const purchasesTotalCost = computed(() => shoppingTabRef.value?.projectPurchasesTotalCost ?? 0)

// BPC Kit modal
const showBpcKitModal = ref(false)
const bpcKitPrice = ref('')
const bpcKitLoading = ref(false)

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
    const kitPrice = parseIskValue(bpcKitPrice.value) ?? 0

    const bpcSteps = store.currentProject.steps.filter(s => s.activityType === 'copy' && s.depth > 0)
    for (const step of bpcSteps) {
      if (!step.purchased) {
        try {
          await store.toggleStepPurchased(props.projectId, step.id, true)
        } catch {
          // Ignore errors for individual steps
        }
      }
    }

    await store.updateProject(props.projectId, { bpoCost: kitPrice })
    closeBpcKitModal()
  } finally {
    bpcKitLoading.value = false
  }
}

// Inline cost editing
const editingCostField = ref<string | null>(null)
const editCostValue = ref('')

function startEditCost(field: string) {
  if (store.currentProject?.status === 'completed') return
  const value = (store.currentProject as Record<string, unknown>)?.[field]
  editCostValue.value = value !== null && value !== undefined ? String(value) : ''
  editingCostField.value = field
}

async function saveEditCost() {
  if (!editingCostField.value || !store.currentProject) return
  const field = editingCostField.value
  const numValue = parseIskValue(editCostValue.value)
  editingCostField.value = null
  await store.updateProject(props.projectId, { [field]: numValue } as never)
}

function cancelEditCost() {
  editingCostField.value = null
}

async function onJobsStartDateChange(e: Event) {
  const input = e.target as HTMLInputElement
  const value = input.value
  if (!value) return
  await store.updateProject(props.projectId, { jobsStartDate: value + 'T00:00:00+00:00' } as never)
}

async function toggleProjectStatus() {
  if (!store.currentProject) return
  const newStatus = store.currentProject.status === 'completed' ? 'active' : 'completed'
  await store.updateProject(props.projectId, { status: newStatus })
  if (newStatus === 'completed') {
    activeTab.value = 'steps'
  }
}

async function switchTab(tab: 'steps' | 'shopping') {
  activeTab.value = tab
  if (tab === 'shopping') {
    await shoppingTabRef.value?.activate()
    await shoppingTabRef.value?.loadSuggestions()
  }
}

onMounted(async () => {
  await store.fetchProject(props.projectId)
  shoppingTabRef.value?.loadPersistedStock()
  if (shoppingTabRef.value?.parsedStock && shoppingTabRef.value.parsedStock.length > 0) {
    await shoppingTabRef.value?.reanalyzeStock()
  }
})
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between group">
      <div class="flex items-center gap-4">
        <button
          @click="emit('close')"
          class="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-200"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </button>
        <div v-if="store.currentProject" class="flex items-center gap-4">
          <!-- Single product: show one icon -->
          <img
            v-if="!isMultiProduct"
            :src="getTypeIconUrl(store.currentProject.productTypeId, 64)"
            class="w-14 h-14 rounded-lg border border-slate-700"
            @error="onImageError"
          />
          <!-- Multi-product: show stacked icons -->
          <div v-else class="flex -space-x-2">
            <img
              v-for="(product, index) in rootProducts.slice(0, 4)"
              :key="product.typeId"
              :src="getTypeIconUrl(product.typeId, 64)"
              class="w-14 h-14 rounded-lg border-2 border-slate-900"
              :style="{ zIndex: 10 - index }"
              :title="product.typeName"
              @error="onImageError"
            />
            <div
              v-if="rootProducts.length > 4"
              class="w-14 h-14 rounded-lg border-2 border-slate-900 bg-slate-700 flex items-center justify-center text-xs text-slate-300"
            >
              +{{ rootProducts.length - 4 }}
            </div>
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h3 v-if="!editingProjectName" class="text-2xl font-bold text-slate-100">
                {{ store.currentProject.displayName }}
              </h3>
              <input
                v-else
                v-model="projectNameEdit"
                type="text"
                class="text-lg font-semibold bg-slate-800 border border-cyan-500 rounded px-2 py-0.5 focus:outline-none"
                @keydown.enter="saveProjectName"
                @keydown.escape="cancelProjectNameEdit"
                @blur="saveProjectName"
                autofocus
              />
              <button
                v-if="!editingProjectName && store.currentProject.status !== 'completed'"
                @click="startEditProjectName"
                class="p-1 text-slate-500 hover:text-cyan-400 opacity-0 group-hover:opacity-100 transition-opacity"
                :title="t('industry.projectDetail.editName')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                </svg>
              </button>
            </div>
            <!-- Single product info -->
            <div v-if="!isMultiProduct" class="flex items-center gap-2 text-sm text-slate-500 flex-wrap">
              <span
                :class="[
                  'text-xs px-2 py-0.5 rounded',
                  store.currentProject.status === 'completed'
                    ? 'bg-emerald-500/20 text-emerald-400'
                    : 'bg-cyan-500/20 text-cyan-400',
                ]"
              >
                {{ store.currentProject.status === 'completed' ? t('industry.stepStatus.completed') : t('common.status.active') }}
              </span>
              <span v-if="store.currentProject.name" class="text-slate-400">{{ store.currentProject.productTypeName }}</span>
              <span class="text-slate-400">{{ store.currentProject.runs }} run{{ store.currentProject.runs > 1 ? 's' : '' }} · ME {{ store.currentProject.meLevel }} · TE {{ store.currentProject.teLevel }}</span>
              <span v-if="estimatedProjectTime" class="text-cyan-400 font-mono">{{ formatDuration(estimatedProjectTime) }}</span>
              <!-- Jobs start date -->
              <span class="text-slate-600">·</span>
              <label class="flex items-center gap-1 text-xs text-slate-500">
                <span>{{ t('industry.projectDetail.start') }}:</span>
                <input
                  type="date"
                  :value="store.currentProject.jobsStartDate ? store.currentProject.jobsStartDate.slice(0, 10) : ''"
                  :disabled="store.currentProject.status === 'completed'"
                  class="bg-slate-800 border border-slate-700 rounded px-1.5 py-0.5 text-xs text-slate-300 focus:border-cyan-500 focus:outline-none disabled:opacity-50"
                  @change="onJobsStartDateChange"
                />
              </label>
            </div>
            <!-- Multi-product info -->
            <div v-else class="text-sm text-slate-500">
              <div class="flex flex-wrap gap-x-3 gap-y-1">
                <span
                  v-for="product in rootProducts"
                  :key="product.typeId"
                  class="text-slate-400"
                >
                  {{ product.typeName }}
                  <span class="text-slate-500">
                    {{ product.count > 1 ? `${product.count} × ${product.runs / product.count} runs` : `×${product.runs}` }}
                  </span>
                </span>
              </div>
              <div class="flex items-center gap-2 mt-1">
                <p>{{ totalRootStepCount }} steps - {{ t('industry.projectDetail.createdOn') }} {{ formatDateTime(store.currentProject.createdAt) }}</p>
                <span class="text-slate-600">·</span>
                <label class="flex items-center gap-1 text-xs text-slate-500">
                  <span>{{ t('industry.projectDetail.start') }}:</span>
                  <input
                    type="date"
                    :value="store.currentProject.jobsStartDate ? store.currentProject.jobsStartDate.slice(0, 10) : ''"
                    :disabled="store.currentProject.status === 'completed'"
                    class="bg-slate-800 border border-slate-700 rounded px-1.5 py-0.5 text-xs text-slate-300 focus:border-cyan-500 focus:outline-none disabled:opacity-50"
                    @change="onJobsStartDateChange"
                  />
                </label>
                <span v-if="estimatedProjectTime" class="text-cyan-400 font-mono text-xs">{{ formatDuration(estimatedProjectTime) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button
          @click="toggleProjectStatus"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 transition-colors',
            store.currentProject?.status === 'completed'
              ? 'bg-amber-500/20 border border-amber-500/50 text-amber-400 hover:bg-amber-500/30'
              : 'bg-emerald-500/20 border border-emerald-500/50 text-emerald-400 hover:bg-emerald-500/30'
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
          {{ store.currentProject?.status === 'completed' ? t('industry.projectDetail.reactivate') : t('industry.stepStatus.completed') }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="store.isLoading && !store.currentProject" class="p-8 text-center text-slate-500">
      <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      {{ t('common.status.loading') }}
    </div>

    <!-- Content -->
    <div v-else-if="store.currentProject" class="p-6">
      <!-- Costs panel (8 columns) -->
      <div class="eve-card p-4 mb-6">
        <div class="grid grid-cols-4 md:grid-cols-8 gap-4">
          <!-- BPC Kit (editable) -->
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costs.bpcKit') }}</p>
            <input
              v-if="editingCostField === 'bpoCost'"
              v-model="editCostValue"
              type="text"
              placeholder="ex: 50M"
              class="w-full text-sm font-mono bg-slate-800 border border-cyan-500 rounded px-1.5 py-0.5 focus:outline-none"
              @keydown.enter="saveEditCost"
              @keydown.escape="cancelEditCost"
              @blur="saveEditCost"
              autofocus
            />
            <p v-else class="text-sm font-mono text-slate-200 editable" @click="startEditCost('bpoCost')" :title="t('industry.projectDetail.clickToEdit')">
              {{ store.currentProject.bpoCost !== null ? formatIsk(store.currentProject.bpoCost) : '-' }}
            </p>
          </div>
          <!-- Mat. estimé (readonly) -->
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costs.estimatedMat') }}</p>
            <p class="text-sm font-mono text-slate-400">
              {{ shoppingTotals?.best ? formatIsk(shoppingTotals.best) : '-' }}
            </p>
          </div>
          <!-- Mat. réel (readonly, from purchases) -->
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costs.actualMat') }}</p>
            <p class="text-sm font-mono text-emerald-400">
              {{ purchasesTotalCost > 0 ? formatIsk(purchasesTotalCost) : '-' }}
            </p>
          </div>
          <!-- Transport (editable) -->
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costs.transport') }}</p>
            <input
              v-if="editingCostField === 'transportCost'"
              v-model="editCostValue"
              type="text"
              placeholder="ex: 50M"
              class="w-full text-sm font-mono bg-slate-800 border border-cyan-500 rounded px-1.5 py-0.5 focus:outline-none"
              @keydown.enter="saveEditCost"
              @keydown.escape="cancelEditCost"
              @blur="saveEditCost"
              autofocus
            />
            <p v-else class="text-sm font-mono text-slate-200 editable" @click="startEditCost('transportCost')" :title="t('industry.projectDetail.clickToEdit')">
              {{ store.currentProject.transportCost !== null ? formatIsk(store.currentProject.transportCost) : '-' }}
            </p>
          </div>
          <!-- Jobs ESI (readonly) -->
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costs.esiJobs') }}</p>
            <p class="text-sm font-mono text-slate-400">
              {{ formatIsk(store.currentProject.jobsCost) }}
            </p>
          </div>
          <!-- Taxes (editable, hidden for personalUse) -->
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costs.taxes') }}</p>
            <template v-if="!store.currentProject.personalUse">
              <input
                v-if="editingCostField === 'taxAmount'"
                v-model="editCostValue"
                type="text"
                placeholder="ex: 50M"
                class="w-full text-sm font-mono bg-slate-800 border border-cyan-500 rounded px-1.5 py-0.5 focus:outline-none"
                @keydown.enter="saveEditCost"
                @keydown.escape="cancelEditCost"
                @blur="saveEditCost"
                autofocus
              />
              <p v-else class="text-sm font-mono text-slate-200 editable" @click="startEditCost('taxAmount')" :title="t('industry.projectDetail.clickToEdit')">
                {{ store.currentProject.taxAmount !== null ? formatIsk(store.currentProject.taxAmount) : '-' }}
              </p>
            </template>
            <p v-else class="text-sm font-mono text-slate-600">—</p>
          </div>
          <!-- Vente (editable, hidden for personalUse) -->
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costs.sell') }}</p>
            <template v-if="!store.currentProject.personalUse">
              <input
                v-if="editingCostField === 'sellPrice'"
                v-model="editCostValue"
                type="text"
                placeholder="ex: 1.5B"
                class="w-full text-sm font-mono bg-slate-800 border border-cyan-500 rounded px-1.5 py-0.5 focus:outline-none"
                @keydown.enter="saveEditCost"
                @keydown.escape="cancelEditCost"
                @blur="saveEditCost"
                autofocus
              />
              <p v-else class="text-sm font-mono text-slate-200 editable" @click="startEditCost('sellPrice')" :title="t('industry.projectDetail.clickToEdit')">
                {{ store.currentProject.sellPrice !== null ? formatIsk(store.currentProject.sellPrice) : '-' }}
              </p>
            </template>
            <p v-else class="text-sm font-mono text-slate-600">—</p>
          </div>
          <!-- Profit + % -->
          <div>
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('industry.costs.profit') }}</p>
            <template v-if="!store.currentProject.personalUse">
              <p
                class="text-lg font-bold font-mono"
                :class="
                  store.currentProject.profit !== null
                    ? store.currentProject.profit >= 0
                      ? 'text-emerald-400'
                      : 'text-red-400'
                    : 'text-slate-400'
                "
              >
                {{ store.currentProject.profit !== null ? formatIsk(store.currentProject.profit) : '-' }}
              </p>
              <p
                v-if="store.currentProject.profitPercent !== null"
                class="text-xs font-mono"
                :class="store.currentProject.profitPercent >= 0 ? 'text-emerald-400' : 'text-red-400'"
              >
                {{ store.currentProject.profitPercent }}%
              </p>
            </template>
            <p v-else class="text-lg font-mono text-slate-600">—</p>
          </div>
        </div>
      </div>

      <!-- Tabs (pill style) -->
      <div class="flex gap-2 mb-6">
        <button
          @click="switchTab('steps')"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
            activeTab === 'steps' ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200',
          ]"
        >
          {{ t('industry.project.steps') }}
        </button>
        <button
          @click="store.currentProject?.status !== 'completed' && switchTab('shopping')"
          :disabled="store.currentProject?.status === 'completed'"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
            store.currentProject?.status === 'completed'
              ? 'bg-slate-800 text-slate-600 cursor-not-allowed'
              : activeTab === 'shopping' ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-slate-200',
          ]"
          :title="store.currentProject?.status === 'completed' ? t('industry.projectDetail.completedTooltip') : ''"
        >
          {{ t('industry.projectDetail.procurement') }}
        </button>
      </div>

      <!-- Tab content -->
      <StepsTab
        v-show="activeTab === 'steps'"
        ref="stepsTabRef"
        :project-id="projectId"
        @open-bpc-modal="openBpcKitModal"
      />

      <ShoppingTab
        v-show="activeTab === 'shopping'"
        ref="shoppingTabRef"
        :project-id="projectId"
      />
    </div>
  </div>

  <!-- BPC Kit Modal -->
  <Teleport to="body">
    <div
      v-if="showBpcKitModal"
      class="fixed inset-0 z-50 flex items-center justify-center"
      @keydown.escape="closeBpcKitModal"
    >
      <div
        class="absolute inset-0 bg-black/70 backdrop-blur-sm"
        @click="closeBpcKitModal"
      ></div>
      <div class="relative bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
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
        <div class="px-6 py-4">
          <p class="text-sm text-slate-400 mb-4">
            {{ t('industry.projectDetail.bpcKitDescription') }}
          </p>
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">
              {{ t('industry.projectDetail.bpcKitPriceLabel') }}
            </label>
            <input
              v-model="bpcKitPrice"
              type="text"
              placeholder="ex: 50M, 1.5B, 500000 (vide = 0)"
              class="w-full px-4 py-3 bg-slate-800 border border-slate-600 rounded-lg text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 font-mono"
              @keydown.enter="confirmBpcKit"
              @keydown.escape="closeBpcKitModal"
              autofocus
            />
            <p class="text-xs text-slate-500 mt-2">
              {{ t('industry.projectDetail.acceptedFormats') }}
            </p>
          </div>
        </div>
        <div class="px-6 py-4 border-t border-slate-700 flex justify-end gap-3">
          <button
            @click="closeBpcKitModal"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium transition-colors"
          >
            {{ t('common.actions.cancel') }}
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
            {{ t('common.actions.confirm') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- Clear Stock Confirmation Modal -->
  <Teleport to="body">
    <div
      v-if="shoppingTabRef?.showClearStockModal"
      class="fixed inset-0 z-50 flex items-center justify-center"
      @keydown.escape="shoppingTabRef!.showClearStockModal = false"
    >
      <div
        class="absolute inset-0 bg-black/70 backdrop-blur-sm"
        @click="shoppingTabRef!.showClearStockModal = false"
      ></div>
      <div class="relative bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-700 flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-100 flex items-center gap-2">
            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            {{ t('industry.projectDetail.clearStockTitle') }}
          </h3>
          <button
            @click="shoppingTabRef!.showClearStockModal = false"
            class="p-1 hover:bg-slate-800 rounded text-slate-400 hover:text-slate-200"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="px-6 py-4">
          <p class="text-sm text-slate-400">
            {{ t('industry.projectDetail.clearStockDescription') }}
          </p>
          <ul v-if="shoppingTabRef?.parsedStock?.length" class="mt-3 max-h-60 overflow-y-auto space-y-1 pr-1">
            <li
              v-for="item in shoppingTabRef.parsedStock"
              :key="item.name"
              class="flex justify-between text-sm px-2 py-1 rounded bg-slate-800/50"
            >
              <span class="text-slate-300 truncate mr-3">{{ item.name }}</span>
              <span class="text-slate-500 tabular-nums shrink-0">× {{ item.quantity.toLocaleString() }}</span>
            </li>
          </ul>
        </div>
        <div class="px-6 py-4 border-t border-slate-700 flex justify-end gap-3">
          <button
            @click="shoppingTabRef!.showClearStockModal = false"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium transition-colors"
          >
            {{ t('common.actions.cancel') }}
          </button>
          <button
            @click="shoppingTabRef?.clearStock()"
            class="px-4 py-2 bg-red-600 hover:bg-red-500 rounded-lg text-white text-sm font-medium transition-colors flex items-center gap-2"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            {{ t('common.actions.clear') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
