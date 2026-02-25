<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useIndustryStore, type SearchResult } from '@/stores/industry'
import { useAdminStore } from '@/stores/admin'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import ProjectTable from '@/components/industry/ProjectTable.vue'
import ProjectDetail from '@/components/industry/ProjectDetail.vue'
import BlacklistConfig from '@/components/industry/BlacklistConfig.vue'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import SkillsConfig from '@/components/industry/SkillsConfig.vue'
import FeesConfig from '@/components/industry/FeesConfig.vue'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import BatchScanTab from '@/components/industry/BatchScanTab.vue'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import StockpileDashboard from '@/components/industry/StockpileDashboard.vue'

const { t } = useI18n()
const route = useRoute()
const store = useIndustryStore()
const adminStore = useAdminStore()
const isAdmin = ref(false)

// Create project form
interface ProductToAdd {
  typeId: number
  typeName: string
  runs: number
  meLevel: number
  teLevel: number
  isT2: boolean
}

const selectedProduct = ref<SearchResult | null>(null)
const runs = ref(1)
const meLevel = ref(0)
const teLevel = ref(0)
const projectName = ref('')
const productsToAdd = ref<ProductToAdd[]>([])
const isCreating = ref(false)
const productSearchRef = ref<{ clear: () => void } | null>(null)

// Create modal state
const showCreateModal = ref(false)

function openCreateModal() {
  // Reset form when opening modal
  selectedProduct.value = null
  runs.value = 1
  meLevel.value = 0
  teLevel.value = 0
  projectName.value = ''
  productsToAdd.value = []
  showCreateModal.value = true
}

function closeCreateModal() {
  showCreateModal.value = false
}

// Helper to clean type names (remove BPC suffix if present)
function cleanTypeName(name: string): string {
  return name.replace(/\s*\(BPC\)\s*$/i, '').trim()
}

// Detail view
const viewingProjectId = ref<string | null>(null)

// Flat pill navigation (single level)
type MainTab = 'dashboard' | 'projects' | 'margins' | 'batch' | 'buy-vs-build' | 'pivot' | 'slots' | 'stockpile'
const VALID_TABS: MainTab[] = ['dashboard', 'projects', 'margins', 'batch', 'buy-vs-build', 'pivot', 'slots', 'stockpile']
const mainTab = ref<MainTab>('dashboard')

// Props for analysis tabs that accept an initial type ID
const analysisInitialTypeId = ref<number | undefined>(undefined)

// Config slide-over panel
const showConfigPanel = ref(false)
const expandedConfigSection = ref<'fees' | 'skills' | 'structures' | 'blacklist' | null>('fees')

function toggleConfigSection(section: 'fees' | 'skills' | 'structures' | 'blacklist') {
  expandedConfigSection.value = expandedConfigSection.value === section ? null : section
}

function onOpenConfigSection(section: 'fees' | 'skills' | 'structures' | 'blacklist') {
  showConfigPanel.value = true
  expandedConfigSection.value = section
}

// Navigation pill groups
type NavPill = { key: MainTab; label: () => string }
type NavGroup = { label: () => string; pills: NavPill[] }

const navGroups: NavGroup[] = [
  {
    label: () => t('industry.groups.overview'),
    pills: [
      { key: 'dashboard', label: () => t('industry.tabs.dashboard') },
      { key: 'projects', label: () => t('industry.tabs.projects') },
    ],
  },
  {
    label: () => t('industry.groups.analysis'),
    pills: [
      { key: 'margins', label: () => t('industry.tabs.margins') },
      { key: 'batch', label: () => t('industry.tabs.batch') },
      { key: 'buy-vs-build', label: () => t('industry.tabs.buyVsBuild') },
      { key: 'pivot', label: () => t('industry.tabs.pivot') },
    ],
  },
  {
    label: () => t('industry.groups.operations'),
    pills: [
      { key: 'slots', label: () => t('industry.tabs.slots') },
      { key: 'stockpile', label: () => t('industry.tabs.stockpile') },
    ],
  },
]

onMounted(async () => {
  isAdmin.value = await adminStore.checkAccess()

  // Parse tab from URL query, with backward compat for ?tab=margins
  const queryTab = route.query.tab as string | undefined
  if (queryTab && VALID_TABS.includes(queryTab as MainTab)) {
    mainTab.value = queryTab as MainTab
  }

  store.fetchProjects()
})

// NavigationIntent watcher for cross-tab navigation
watch(() => store.navigationIntent, (intent) => {
  if (!intent) return

  if (intent.target === 'projects') {
    mainTab.value = 'projects'
    if (intent.openCreateModal) {
      openCreateModal()
    }
    if (intent.prefill) {
      // Open modal with prefilled data
      showCreateModal.value = true
      selectedProduct.value = {
        typeId: intent.prefill.typeId,
        typeName: intent.prefill.typeName,
        isT2: false,
      }
      runs.value = intent.prefill.runs ?? 1
      meLevel.value = intent.prefill.me ?? 0
      teLevel.value = intent.prefill.te ?? 0
    }
  } else if (intent.target === 'margins') {
    mainTab.value = 'margins'
    analysisInitialTypeId.value = intent.typeId
  } else if (intent.target === 'batch') {
    mainTab.value = 'batch'
  } else if (intent.target === 'buy-vs-build') {
    mainTab.value = 'buy-vs-build'
    analysisInitialTypeId.value = intent.typeId
  } else if (intent.target === 'pivot') {
    mainTab.value = 'pivot'
    analysisInitialTypeId.value = intent.typeId
  } else if (intent.target === 'slots') {
    mainTab.value = 'slots'
  } else if (intent.target === 'stockpile') {
    mainTab.value = 'stockpile'
  }

  store.navigationIntent = null
})

function onProductSelect(result: SearchResult) {
  selectedProduct.value = result
  if (result.isT2) {
    meLevel.value = 2
    teLevel.value = 4
  }
}

// Add product to the list
function addProductToList() {
  if (!selectedProduct.value) return
  const isT2 = selectedProduct.value.isT2 ?? false
  productsToAdd.value.push({
    typeId: selectedProduct.value.typeId,
    typeName: cleanTypeName(selectedProduct.value.typeName),
    runs: runs.value,
    meLevel: isT2 ? 2 : meLevel.value,
    teLevel: isT2 ? 4 : teLevel.value,
    isT2,
  })
}

// Remove product from the list
function removeProductFromList(index: number) {
  productsToAdd.value.splice(index, 1)
}

// Create project with all products
async function createProject() {
  // If no products in list but one selected, add it first
  if (productsToAdd.value.length === 0 && selectedProduct.value) {
    addProductToList()
  }

  if (productsToAdd.value.length === 0) return

  isCreating.value = true
  try {
    // Create project with first product
    const firstProduct = productsToAdd.value[0]
    const project = await store.createProject(
      firstProduct.typeId,
      firstProduct.runs,
      firstProduct.meLevel,
      firstProduct.teLevel,
      store.defaultMaxJobDurationDays,
      projectName.value.trim() || null,
    )

    // Add remaining products as additional steps (with their own ME/TE)
    for (let i = 1; i < productsToAdd.value.length; i++) {
      const product = productsToAdd.value[i]
      await store.createStep(project.id, product.typeId, product.runs, product.meLevel, product.teLevel)
    }

    // Reset form and close modal
    selectedProduct.value = null
    runs.value = 1
    meLevel.value = 0
    teLevel.value = 0
    projectName.value = ''
    productsToAdd.value = []
    showCreateModal.value = false

    await store.fetchProjects()
  } finally {
    isCreating.value = false
  }
}

function viewProject(id: string) {
  viewingProjectId.value = id
}

function closeDetail() {
  viewingProjectId.value = null
  store.fetchProjects()
}

async function duplicateProject(project: { productTypeId: number; runs: number; meLevel: number; teLevel: number }) {
  isCreating.value = true
  try {
    await store.createProject(
      project.productTypeId,
      project.runs,
      project.meLevel,
      project.teLevel,
      store.defaultMaxJobDurationDays,
    )
    await store.fetchProjects()
  } finally {
    isCreating.value = false
  }
}
</script>

<template>
  <MainLayout>
      <!-- Header -->
      <div class="mb-6 flex items-center justify-between">
        <p class="text-slate-400">{{ t('industry.subtitle') }}</p>
      </div>

      <!-- Flat pill navigation bar -->
      <div v-if="!viewingProjectId" class="flex items-center gap-1 bg-slate-900/50 rounded-xl border border-slate-800/60 px-3 py-2.5 mb-6">
        <template v-for="(group, groupIndex) in navGroups" :key="groupIndex">
          <!-- Divider between groups -->
          <div v-if="groupIndex > 0" class="nav-divider"></div>

          <!-- Group with label -->
          <div class="relative flex items-center gap-1" :class="groupIndex === 0 ? 'pr-1' : 'px-1'">
            <span class="group-label">{{ group.label() }}</span>
            <button
              v-for="pill in group.pills"
              :key="pill.key"
              @click="mainTab = pill.key"
              :class="[
                'px-3.5 py-1.5 rounded-lg text-sm font-medium transition-all duration-200',
                mainTab === pill.key
                  ? 'nav-pill-active'
                  : 'nav-pill-inactive',
              ]"
            >
              {{ pill.label() }}
            </button>
          </div>
        </template>

        <!-- Spacer -->
        <div class="flex-1"></div>

        <!-- Config gear button -->
        <button
          class="gear-btn nav-pill-inactive p-2 rounded-lg"
          :title="t('industry.tabs.config')"
          @click="showConfigPanel = !showConfigPanel"
        >
          <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
        </button>
      </div>

      <!-- Error -->
      <ErrorBanner v-if="store.error" :message="store.error" class="mb-6" @dismiss="store.clearError()" />

      <!-- Detail view -->
      <div v-if="viewingProjectId" key="detail">
        <ProjectDetail :project-id="viewingProjectId" @close="closeDetail" />
      </div>

      <!-- Dashboard -->
      <div v-else-if="mainTab === 'dashboard'" key="dashboard">
        <IndustryDashboard @view-project="viewProject" />
      </div>

      <!-- Margins -->
      <div v-else-if="mainTab === 'margins'" key="margins">
        <ProfitMarginTab />
      </div>

      <!-- Batch Scan -->
      <div v-else-if="mainTab === 'batch'" key="batch">
        <BatchScanTab />
      </div>

      <!-- Buy vs Build -->
      <div v-else-if="mainTab === 'buy-vs-build'" key="buy-vs-build">
        <BuyVsBuildTab :initial-type-id="analysisInitialTypeId" />
      </div>

      <!-- Pivot -->
      <div v-else-if="mainTab === 'pivot'" key="pivot">
        <PivotAdvisorTab :initial-type-id="analysisInitialTypeId" />
      </div>

      <!-- Slots -->
      <div v-else-if="mainTab === 'slots'" key="slots">
        <IndustrySlotsSection @open-config="onOpenConfigSection" />
      </div>

      <!-- Stockpile -->
      <div v-else-if="mainTab === 'stockpile'" key="stockpile">
        <StockpileDashboard />
      </div>

      <!-- Projects (default / fallback) -->
      <div v-else key="projects">
        <!-- Projects table card -->
        <div class="bg-slate-900 rounded-xl border border-slate-800">
          <!-- Table header with New Project button -->
          <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <h3 class="text-lg font-semibold text-slate-100">{{ t('industry.tabs.projects') }}</h3>
              <span class="text-xs px-2 py-0.5 rounded-full bg-slate-700 text-slate-400 font-mono">
                {{ store.projects.length }} {{ t('industry.tabs.projects').toLowerCase() }}
              </span>
            </div>
            <button
              @click="openCreateModal"
              class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-semibold flex items-center gap-2 transition-all hover:-translate-y-px hover:shadow-lg hover:shadow-black/30 active:translate-y-0"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              {{ t('industry.createProject.newProject') }}
            </button>
          </div>

          <!-- Loading -->
          <div v-if="store.isLoading" class="p-8 text-center text-slate-500">
            <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('common.status.loading') }}
          </div>

          <!-- Projects table -->
          <ProjectTable v-else @view-project="viewProject" @duplicate-project="duplicateProject" />
        </div>
      </div>

      <!-- Config Slide-Over Panel -->
      <Teleport to="body">
        <div v-if="showConfigPanel" class="fixed inset-0 z-50" @keydown.escape="showConfigPanel = false">
          <!-- Backdrop -->
          <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" @click="showConfigPanel = false"></div>

          <!-- Panel -->
          <div class="absolute top-0 right-0 bottom-0 w-full max-w-md bg-slate-900 border-l border-slate-700 shadow-2xl animate-slide-in-right overflow-y-auto">
            <!-- Header -->
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between sticky top-0 bg-slate-900 z-10">
              <h3 class="text-lg font-semibold text-white">{{ t('industry.tabs.config') }}</h3>
              <button
                @click="showConfigPanel = false"
                class="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-200 transition-colors"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <!-- Max job duration setting -->
            <div class="px-6 py-4 border-b border-slate-800">
              <label class="block text-xs text-slate-500 mb-1.5">{{ t('industry.config.maxJobDuration') }}</label>
              <div class="flex items-center gap-3">
                <input
                  :value="store.defaultMaxJobDurationDays.toFixed(1)"
                  @change="(e) => store.setDefaultMaxJobDurationDays(parseFloat((e.target as HTMLInputElement).value) || 2.0)"
                  type="number"
                  min="0.5"
                  step="0.1"
                  class="w-32 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
                />
                <p class="text-xs text-slate-500">{{ t('industry.config.maxJobDurationHint') }}</p>
              </div>
            </div>

            <!-- Accordion sections -->
            <div class="divide-y divide-slate-800">
              <!-- Fees -->
              <div>
                <button
                  @click="toggleConfigSection('fees')"
                  class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
                >
                  <span class="text-sm font-medium text-slate-200">{{ t('industry.configTabs.fees') }}</span>
                  <svg
                    class="w-4 h-4 text-slate-400 transition-transform duration-200"
                    :class="{ 'rotate-180': expandedConfigSection === 'fees' }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div v-if="expandedConfigSection === 'fees'" class="px-6 pb-4">
                  <FeesConfig />
                </div>
              </div>

              <!-- Skills -->
              <div>
                <button
                  @click="toggleConfigSection('skills')"
                  class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
                >
                  <span class="text-sm font-medium text-slate-200">{{ t('industry.configTabs.skills') }}</span>
                  <svg
                    class="w-4 h-4 text-slate-400 transition-transform duration-200"
                    :class="{ 'rotate-180': expandedConfigSection === 'skills' }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div v-if="expandedConfigSection === 'skills'" class="px-6 pb-4">
                  <SkillsConfig />
                </div>
              </div>

              <!-- Structures -->
              <div>
                <button
                  @click="toggleConfigSection('structures')"
                  class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
                >
                  <span class="text-sm font-medium text-slate-200">{{ t('industry.configTabs.structures') }}</span>
                  <svg
                    class="w-4 h-4 text-slate-400 transition-transform duration-200"
                    :class="{ 'rotate-180': expandedConfigSection === 'structures' }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div v-if="expandedConfigSection === 'structures'" class="px-6 pb-4">
                  <StructureConfig />
                </div>
              </div>

              <!-- Blacklist -->
              <div>
                <button
                  @click="toggleConfigSection('blacklist')"
                  class="w-full px-6 py-3.5 flex items-center justify-between hover:bg-slate-800/50 transition-colors"
                >
                  <span class="text-sm font-medium text-slate-200">{{ t('industry.configTabs.blacklist') }}</span>
                  <svg
                    class="w-4 h-4 text-slate-400 transition-transform duration-200"
                    :class="{ 'rotate-180': expandedConfigSection === 'blacklist' }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <div v-if="expandedConfigSection === 'blacklist'" class="px-6 pb-4">
                  <BlacklistConfig />
                </div>
              </div>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Create Project Modal -->
      <Teleport to="body">
        <div
          v-if="showCreateModal"
          class="fixed inset-0 z-50 flex items-center justify-center"
          @keydown.escape="closeCreateModal"
        >
          <!-- Backdrop -->
          <div class="absolute inset-0 bg-slate-950/85 backdrop-blur-sm" @click="closeCreateModal"></div>

          <!-- Modal card -->
          <div class="relative bg-slate-900 rounded-xl border border-slate-700 shadow-2xl w-full max-w-xl mx-4 animate-modal-in">
            <!-- Modal header -->
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
              <h3 class="text-lg font-semibold text-white">{{ t('industry.createProject.title') }}</h3>
              <button
                @click="closeCreateModal"
                class="p-1 hover:bg-slate-800 rounded text-slate-400 hover:text-slate-200 transition-colors"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <!-- Modal body -->
            <div class="px-6 py-5 space-y-5">
              <!-- Project name -->
              <div v-if="productsToAdd.length > 0 || projectName">
                <label class="block text-sm text-slate-400 mb-1.5">
                  {{ t('industry.createProject.projectName') }}
                  <span class="text-slate-600">({{ t('industry.createProject.optional') }})</span>
                </label>
                <input
                  v-model="projectName"
                  type="text"
                  :placeholder="t('industry.createProject.projectNamePlaceholder')"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 focus:outline-hidden focus:border-cyan-500 placeholder-slate-600 transition-colors"
                />
              </div>

              <!-- Products already added to the list -->
              <div v-if="productsToAdd.length > 0" class="space-y-2">
                <div
                  v-for="(product, index) in productsToAdd"
                  :key="index"
                  class="flex items-center gap-3 bg-slate-800/50 rounded-lg px-4 py-2"
                >
                  <span class="flex-1 text-slate-200">
                    {{ product.typeName }}
                    <span v-if="product.isT2" class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 bg-blue-500/20 text-blue-400 rounded-sm ml-1">T2</span>
                  </span>
                  <div class="flex items-center gap-1">
                    <input
                      v-model.number="product.runs"
                      type="number"
                      min="1"
                      class="w-16 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-sm text-center"
                    />
                    <span class="text-slate-500 text-xs">runs</span>
                  </div>
                  <span class="text-slate-500 text-xs">ME{{ product.meLevel }}/TE{{ product.teLevel }}</span>
                  <button
                    @click="removeProductFromList(index)"
                    class="p-1 text-slate-500 hover:text-red-400"
                    :title="t('industry.createProject.remove')"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Product search -->
              <div>
                <label class="block text-sm text-slate-400 mb-1.5">
                  {{ productsToAdd.length > 0 ? t('industry.createProject.addProduct') : t('industry.createProject.product') }}
                  <span v-if="productsToAdd.length === 0" class="text-red-400 ml-0.5">*</span>
                  <span v-if="selectedProduct" class="text-cyan-400 ml-2">
                    -- {{ selectedProduct.typeName }}
                  </span>
                </label>
                <ProductSearch ref="productSearchRef" @select="onProductSelect" />
              </div>

              <!-- Parameters row: Runs, ME, TE -->
              <div class="grid grid-cols-3 gap-4">
                <div>
                  <label class="block text-sm text-slate-400 mb-1.5">{{ t('industry.createProject.runs') }}</label>
                  <input
                    v-model.number="runs"
                    type="number"
                    min="1"
                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 text-center focus:outline-hidden focus:border-cyan-500 font-mono"
                  />
                </div>
                <div>
                  <label class="block text-sm text-slate-400 mb-1.5">
                    {{ t('industry.createProject.me') }}
                    <span v-if="selectedProduct?.isT2" class="text-xs text-blue-400 ml-1">T2</span>
                  </label>
                  <input
                    v-model.number="meLevel"
                    type="number"
                    min="0"
                    max="10"
                    :readonly="selectedProduct?.isT2"
                    :class="[
                      'w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 text-center focus:outline-hidden focus:border-cyan-500 font-mono',
                      selectedProduct?.isT2 ? 'cursor-not-allowed opacity-60' : '',
                    ]"
                  />
                </div>
                <div>
                  <label class="block text-sm text-slate-400 mb-1.5">
                    {{ t('industry.createProject.te') }}
                    <span v-if="selectedProduct?.isT2" class="text-xs text-blue-400 ml-1">T2</span>
                  </label>
                  <input
                    v-model.number="teLevel"
                    type="number"
                    min="0"
                    max="20"
                    :readonly="selectedProduct?.isT2"
                    :class="[
                      'w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-slate-200 text-center focus:outline-hidden focus:border-cyan-500 font-mono',
                      selectedProduct?.isT2 ? 'cursor-not-allowed opacity-60' : '',
                    ]"
                  />
                </div>
              </div>

              <!-- Add another product link -->
              <button
                v-if="selectedProduct && productsToAdd.length === 0"
                @click="addProductToList"
                class="flex items-center gap-2 text-sm text-slate-400 hover:text-cyan-400 transition-colors"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ t('industry.createProject.multiProduct') }}
              </button>

              <!-- Add to list button (when products already in list) -->
              <button
                v-if="productsToAdd.length > 0 && selectedProduct"
                @click="addProductToList"
                class="flex items-center gap-2 text-sm text-slate-400 hover:text-cyan-400 transition-colors"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ t('industry.createProject.addProduct') }}
              </button>
            </div>

            <!-- Modal footer -->
            <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-end gap-3">
              <button
                @click="closeCreateModal"
                class="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-300 text-sm font-medium transition-colors"
              >
                {{ t('common.actions.cancel') }}
              </button>
              <button
                @click="createProject"
                :disabled="(!selectedProduct && productsToAdd.length === 0) || isCreating"
                class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-semibold flex items-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed hover:-translate-y-px hover:shadow-lg hover:shadow-black/30 active:translate-y-0"
              >
                <svg
                  v-if="isCreating"
                  class="w-4 h-4 animate-spin"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ isCreating ? t('industry.createProject.creating') : t('industry.createProject.create') }}
              </button>
            </div>
          </div>
        </div>
      </Teleport>
  </MainLayout>
</template>

<style scoped>
@keyframes modal-in {
  from { opacity: 0; transform: scale(0.95) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}
.animate-modal-in {
  animation: modal-in 0.25s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
}

@keyframes slide-in-right {
  from { transform: translateX(100%); }
  to { transform: translateX(0); }
}
.animate-slide-in-right {
  animation: slide-in-right 0.25s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
}

/* Nav pill active state with glow */
.nav-pill-active {
  background: linear-gradient(135deg, #0891b2, #0e7490);
  color: white;
  box-shadow: 0 0 12px rgba(8, 145, 178, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

.nav-pill-inactive {
  background: rgba(30, 41, 59, 0.6);
  color: #94a3b8;
  border: 1px solid rgba(51, 65, 85, 0.5);
  transition: all 0.2s ease;
}
.nav-pill-inactive:hover {
  background: rgba(30, 41, 59, 0.9);
  color: #e2e8f0;
  border-color: rgba(71, 85, 105, 0.8);
  transform: translateY(-1px);
}

/* Group label */
.group-label {
  font-size: 9px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: #475569;
  position: absolute;
  top: -14px;
  left: 4px;
  white-space: nowrap;
}

/* Divider between groups */
.nav-divider {
  width: 1px;
  height: 24px;
  background: linear-gradient(180deg, transparent, #334155 30%, #334155 70%, transparent);
  margin: 0 6px;
  align-self: center;
}

/* Config gear rotation on hover */
.gear-btn svg {
  transition: transform 0.4s ease;
}
.gear-btn:hover svg {
  transform: rotate(90deg);
}
</style>
