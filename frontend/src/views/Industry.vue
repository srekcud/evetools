<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore, type SearchResult } from '@/stores/industry'

const { t } = useI18n()
import MainLayout from '@/layouts/MainLayout.vue'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import ProjectTable from '@/components/industry/ProjectTable.vue'
import ProjectDetail from '@/components/industry/ProjectDetail.vue'
import BlacklistConfig from '@/components/industry/BlacklistConfig.vue'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import SkillsConfig from '@/components/industry/SkillsConfig.vue'

const store = useIndustryStore()

// Create project form
interface ProductToAdd {
  typeId: number
  typeName: string
  runs: number
  meLevel: number
  teLevel: number
}

const selectedProduct = ref<SearchResult | null>(null)
const runs = ref(1)
const meLevel = ref(0)
const teLevel = ref(0)
const projectName = ref('')
const productsToAdd = ref<ProductToAdd[]>([])
const isCreating = ref(false)
const productSearchRef = ref<{ clear: () => void } | null>(null)

// Helper to clean type names (remove BPC suffix if present)
function cleanTypeName(name: string): string {
  return name.replace(/\s*\(BPC\)\s*$/i, '').trim()
}

// Detail view
const viewingProjectId = ref<string | null>(null)

// Main tabs
const mainTab = ref<'projects' | 'config'>('projects')

// Config sub-tabs
const configTab = ref<'skills' | 'structures' | 'blacklist'>('skills')

onMounted(() => {
  store.fetchProjects()
})

function onProductSelect(result: SearchResult) {
  selectedProduct.value = result
}

// Add product to the list
function addProductToList() {
  if (!selectedProduct.value) return
  productsToAdd.value.push({
    typeId: selectedProduct.value.typeId,
    typeName: cleanTypeName(selectedProduct.value.typeName),
    runs: runs.value,
    meLevel: meLevel.value,
    teLevel: teLevel.value,
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

    // Reset form
    selectedProduct.value = null
    runs.value = 1
    meLevel.value = 0
    teLevel.value = 0
    projectName.value = ''
    productsToAdd.value = []

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
        <div v-if="!viewingProjectId" class="flex gap-2">
          <button
            @click="mainTab = 'projects'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium',
              mainTab === 'projects'
                ? 'bg-cyan-600 text-white'
                : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.tabs.projects') }}
          </button>
          <button
            @click="mainTab = 'config'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium',
              mainTab === 'config'
                ? 'bg-cyan-600 text-white'
                : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.tabs.config') }}
          </button>
        </div>
      </div>

      <!-- Error -->
      <div
        v-if="store.error"
        class="mb-6 p-4 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400 flex items-center justify-between"
      >
        <span>{{ store.error }}</span>
        <button @click="store.clearError()" class="text-red-400 hover:text-red-300">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Detail view -->
      <template v-if="viewingProjectId">
        <ProjectDetail :project-id="viewingProjectId" @close="closeDetail" />
      </template>

      <!-- Config view -->
      <template v-else-if="mainTab === 'config'">
        <!-- General settings -->
        <div class="flex items-center gap-4 mb-6 bg-slate-900 rounded-xl border border-slate-800 px-6 py-4">
          <div class="w-48">
            <label class="block text-xs text-slate-500 mb-1">{{ t('industry.config.maxJobDuration') }}</label>
            <input
              :value="store.defaultMaxJobDurationDays.toFixed(1)"
              @change="(e) => store.setDefaultMaxJobDurationDays(parseFloat((e.target as HTMLInputElement).value) || 2.0)"
              type="number"
              min="0.5"
              step="0.1"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-hidden focus:border-cyan-500"
            />
          </div>
          <p class="text-xs text-slate-500 self-end pb-1">
            {{ t('industry.config.maxJobDurationHint') }}
          </p>
        </div>

        <!-- Sub-tabs -->
        <div class="flex gap-2 mb-6">
          <button
            @click="configTab = 'skills'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
              configTab === 'skills'
                ? 'bg-cyan-600 text-white'
                : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.configTabs.skills') }}
          </button>
          <button
            @click="configTab = 'structures'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
              configTab === 'structures'
                ? 'bg-cyan-600 text-white'
                : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.configTabs.structures') }}
          </button>
          <button
            @click="configTab = 'blacklist'"
            :class="[
              'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
              configTab === 'blacklist'
                ? 'bg-cyan-600 text-white'
                : 'bg-slate-800 text-slate-400 hover:text-slate-200',
            ]"
          >
            {{ t('industry.configTabs.blacklist') }}
          </button>
        </div>

        <!-- Tab content -->
        <div v-if="configTab === 'skills'">
          <SkillsConfig />
        </div>
        <div v-else-if="configTab === 'structures'">
          <StructureConfig />
        </div>
        <div v-else>
          <BlacklistConfig />
        </div>
      </template>

      <!-- Main view (projects) -->
      <template v-else>
        <!-- New project form -->
        <div class="bg-slate-900 rounded-xl border border-slate-800 p-6 mb-6">
          <h3 class="text-lg font-semibold text-slate-100 mb-4">{{ t('industry.createProject.title') }}</h3>

          <!-- Project name (shown when multiple products or when filled) -->
          <div v-if="productsToAdd.length > 0 || projectName" class="mb-4">
            <label class="block text-sm text-slate-400 mb-1">{{ t('industry.createProject.projectName') }}</label>
            <input
              v-model="projectName"
              type="text"
              placeholder="Ex: Rorqual Fleet"
              class="w-64 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm focus:outline-hidden focus:border-cyan-500 placeholder-slate-600"
            />
          </div>

          <!-- Products list -->
          <div v-if="productsToAdd.length > 0" class="mb-4 space-y-2">
            <div
              v-for="(product, index) in productsToAdd"
              :key="index"
              class="flex items-center gap-3 bg-slate-800/50 rounded-lg px-4 py-2"
            >
              <span class="flex-1 text-slate-200">{{ product.typeName }}</span>
              <div class="flex items-center gap-1">
                <input
                  v-model.number="product.runs"
                  type="number"
                  min="1"
                  class="w-16 bg-slate-700 border border-slate-600 rounded-sm px-2 py-1 text-sm text-center"
                />
                <span class="text-slate-500 text-xs">runs</span>
              </div>
              <div class="flex items-center gap-1">
                <span class="text-slate-500 text-xs">ME</span>
                <input
                  v-model.number="product.meLevel"
                  type="number"
                  min="0"
                  max="10"
                  class="w-12 bg-slate-700 border border-slate-600 rounded-sm px-2 py-1 text-sm text-center"
                />
              </div>
              <div class="flex items-center gap-1">
                <span class="text-slate-500 text-xs">TE</span>
                <input
                  v-model.number="product.teLevel"
                  type="number"
                  min="0"
                  max="20"
                  class="w-12 bg-slate-700 border border-slate-600 rounded-sm px-2 py-1 text-sm text-center"
                />
              </div>
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

          <!-- Add product form -->
          <div class="flex items-end gap-4 flex-wrap">
            <div class="flex-1 relative min-w-[200px]">
              <label class="block text-sm text-slate-400 mb-1">
                {{ productsToAdd.length > 0 ? t('industry.createProject.addProduct') : t('industry.createProject.product') }}
                <span v-if="selectedProduct" class="text-cyan-400 ml-2">
                  — {{ selectedProduct.typeName }}
                </span>
              </label>
              <ProductSearch ref="productSearchRef" @select="onProductSelect" />
            </div>
            <div class="w-24">
              <label class="block text-sm text-slate-400 mb-1">{{ t('industry.createProject.runs') }}</label>
              <input
                v-model.number="runs"
                type="number"
                min="1"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm focus:outline-hidden focus:border-cyan-500"
              />
            </div>
            <div class="w-24">
              <label class="block text-sm text-slate-400 mb-1">{{ t('industry.createProject.me') }}</label>
              <input
                v-model.number="meLevel"
                type="number"
                min="0"
                max="10"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm focus:outline-hidden focus:border-cyan-500"
              />
            </div>
            <div class="w-24">
              <label class="block text-sm text-slate-400 mb-1">{{ t('industry.createProject.te') }}</label>
              <input
                v-model.number="teLevel"
                type="number"
                min="0"
                max="20"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm focus:outline-hidden focus:border-cyan-500"
              />
            </div>

            <!-- Add to list button (shown when products already in list) -->
            <button
              v-if="productsToAdd.length > 0"
              @click="addProductToList"
              :disabled="!selectedProduct"
              class="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-white text-sm font-medium disabled:opacity-50 flex items-center gap-2"
              :title="t('industry.createProject.addToList')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              {{ t('common.actions.add') }}
            </button>

            <!-- Create button -->
            <button
              @click="createProject"
              :disabled="(!selectedProduct && productsToAdd.length === 0) || isCreating"
              class="px-6 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium disabled:opacity-50 flex items-center gap-2"
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
              {{ isCreating ? t('industry.createProject.creating') : (productsToAdd.length > 0 ? t('industry.createProject.create') : t('common.actions.create')) }}
            </button>

            <!-- Add more button (shown when no products in list yet but one selected) -->
            <button
              v-if="productsToAdd.length === 0 && selectedProduct"
              @click="addProductToList"
              class="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-300 text-sm font-medium flex items-center gap-2"
              :title="t('industry.createProject.addAnother')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              {{ t('industry.createProject.multiProduct') }}
            </button>
          </div>
        </div>

        <!-- Projects table -->
        <div class="bg-slate-900 rounded-xl border border-slate-800">
          <div class="px-6 py-4 border-b border-slate-800">
            <h3 class="text-lg font-semibold text-slate-100">{{ t('industry.projectHistory') }}</h3>
          </div>
          <div v-if="store.isLoading" class="p-8 text-center text-slate-500">
            <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('common.status.loading') }}
          </div>
          <ProjectTable v-else @view-project="viewProject" @duplicate-project="duplicateProject" />
        </div>
      </template>
  </MainLayout>
</template>
