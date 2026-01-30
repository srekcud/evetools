<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useIndustryStore, type SearchResult } from '@/stores/industry'
import MainLayout from '@/layouts/MainLayout.vue'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import ProjectTable from '@/components/industry/ProjectTable.vue'
import ProjectDetail from '@/components/industry/ProjectDetail.vue'
import BlacklistConfig from '@/components/industry/BlacklistConfig.vue'
import StructureConfig from '@/components/industry/StructureConfig.vue'

const store = useIndustryStore()

// Create project form
const selectedProduct = ref<SearchResult | null>(null)
const runs = ref(1)
const meLevel = ref(0)
const isCreating = ref(false)

// Detail view
const viewingProjectId = ref<string | null>(null)

// Main tabs
const mainTab = ref<'projects' | 'config'>('projects')

onMounted(() => {
  store.fetchProjects()
})

function onProductSelect(result: SearchResult) {
  selectedProduct.value = result
}

async function createProject() {
  if (!selectedProduct.value) return
  isCreating.value = true
  try {
    await store.createProject(selectedProduct.value.typeId, runs.value, meLevel.value)
    selectedProduct.value = null
    runs.value = 1
    meLevel.value = 0
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
</script>

<template>
  <MainLayout>
      <!-- Header -->
      <div class="mb-6 flex items-center justify-between">
        <p class="text-slate-400">Suivi de projets de construction</p>
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
            Projets
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
            Configuration
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
        <div class="space-y-6">
          <!-- Blacklist -->
          <div class="bg-slate-900 rounded-xl border border-slate-800 p-6">
            <BlacklistConfig />
          </div>

          <!-- Structures -->
          <div class="bg-slate-900 rounded-xl border border-slate-800 p-6">
            <StructureConfig />
          </div>
        </div>
      </template>

      <!-- Main view (projects) -->
      <template v-else>
        <!-- New project form -->
        <div class="bg-slate-900 rounded-xl border border-slate-800 p-6 mb-6">
          <h3 class="text-lg font-semibold text-slate-100 mb-4">Nouveau projet</h3>
          <div class="flex items-end gap-4">
            <div class="flex-1">
              <label class="block text-sm text-slate-400 mb-1">Produit</label>
              <ProductSearch @select="onProductSelect" />
              <p v-if="selectedProduct" class="text-xs text-cyan-400 mt-1">
                Sélectionné : {{ selectedProduct.typeName }}
              </p>
            </div>
            <div class="w-24">
              <label class="block text-sm text-slate-400 mb-1">Runs</label>
              <input
                v-model.number="runs"
                type="number"
                min="1"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-cyan-500"
              />
            </div>
            <div class="w-24">
              <label class="block text-sm text-slate-400 mb-1">ME</label>
              <input
                v-model.number="meLevel"
                type="number"
                min="0"
                max="10"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-cyan-500"
              />
            </div>
            <button
              @click="createProject"
              :disabled="!selectedProduct || isCreating"
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
              {{ isCreating ? 'Création...' : 'Créer' }}
            </button>
          </div>
        </div>

        <!-- Projects table -->
        <div class="bg-slate-900 rounded-xl border border-slate-800">
          <div class="px-6 py-4 border-b border-slate-800">
            <h3 class="text-lg font-semibold text-slate-100">Historique des projets</h3>
          </div>
          <div v-if="store.isLoading" class="p-8 text-center text-slate-500">
            <svg class="w-8 h-8 animate-spin text-cyan-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Chargement...
          </div>
          <ProjectTable v-else @view-project="viewProject" />
        </div>
      </template>
  </MainLayout>
</template>
