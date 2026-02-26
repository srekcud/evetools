<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useEveImages } from '@/composables/useEveImages'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import MainLayout from '@/layouts/MainLayout.vue'
import ProjectKpiCards from '@/components/group-industry/ProjectKpiCards.vue'
import ProjectCard from '@/components/group-industry/ProjectCard.vue'
import AvailableProjectCard from '@/components/group-industry/AvailableProjectCard.vue'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import LineRentalModal from '@/components/group-industry/LineRentalModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import type { GroupProject, GroupProjectStatus } from '@/stores/group-industry/types'

const router = useRouter()
const { t } = useI18n()
const authStore = useAuthStore()
const { getCorporationLogoUrl, onImageError } = useEveImages()
const groupProjectStore = useGroupProjectStore()

// --- Data ---

const myProjects = ref<GroupProject[]>([])
const availableProjects = ref<GroupProject[]>([])
const isLoading = ref(false)
const error = ref<string | null>(null)

// --- Tabs ---

type MainTab = 'my-projects' | 'available'
const activeTab = ref<MainTab>('my-projects')

// --- Status filter pills ---

type StatusFilter = 'all' | GroupProjectStatus
const statusFilter = ref<StatusFilter>('all')

const STATUS_FILTERS: StatusFilter[] = ['all', 'published', 'in_progress', 'selling', 'completed']

function statusFilterLabel(filter: StatusFilter): string {
  if (filter === 'all') return t('common.status.all')
  return t(`groupIndustry.status.${filter}`)
}

function statusFilterCount(filter: StatusFilter): number {
  if (filter === 'all') return myProjects.value.length
  return myProjects.value.filter(p => p.status === filter).length
}

const filteredProjects = computed(() => {
  if (statusFilter.value === 'all') return myProjects.value
  return myProjects.value.filter(p => p.status === statusFilter.value)
})

// --- Modals ---

const showNewProjectModal = ref(false)
const showLineRentalModal = ref(false)

function openNewProjectModal() {
  showNewProjectModal.value = true
}

function closeNewProjectModal() {
  showNewProjectModal.value = false
}

function openLineRentalModal() {
  showLineRentalModal.value = true
}

function closeLineRentalModal() {
  showLineRentalModal.value = false
}

// --- Actions ---

function navigateToProject(project: GroupProject) {
  router.push({ name: 'group-industry-detail', params: { id: project.id } })
}

async function handleJoinProject(shortLinkCode: string) {
  try {
    const project = await groupProjectStore.joinProject(shortLinkCode)
    if (project) {
      router.push({ name: 'group-industry-detail', params: { id: project.id } })
    }
  } catch {
    error.value = 'Failed to join project'
  }
}

async function handleProjectCreated() {
  showNewProjectModal.value = false
  await fetchProjects()
}

// --- Data fetching ---

async function fetchProjects() {
  isLoading.value = true
  error.value = null
  try {
    await groupProjectStore.fetchMyProjects()
    await groupProjectStore.fetchAvailableProjects()
    myProjects.value = groupProjectStore.myProjects
    availableProjects.value = groupProjectStore.availableProjects
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load projects'
  } finally {
    isLoading.value = false
  }
}

// --- Computed ---

const corporationName = computed(() => authStore.user?.corporationName ?? '')
const corporationId = computed(() => authStore.user?.corporationId ?? 0)

// --- Lifecycle ---

onMounted(() => {
  fetchProjects()
})
</script>

<template>
  <MainLayout>
    <div class="max-w-7xl mx-auto px-6 py-8">

      <!-- Page header -->
      <div class="mb-6 card-enter" style="animation-delay: 0ms;">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-4">
            <div>
              <h1 class="text-2xl font-bold text-slate-100">{{ t('groupIndustry.title') }}</h1>
              <div class="flex items-center gap-2 mt-1">
                <img
                  v-if="corporationId > 0"
                  :src="getCorporationLogoUrl(corporationId, 32)"
                  alt="Corp"
                  class="w-5 h-5 rounded"
                  @error="onImageError"
                />
                <span class="text-sm text-slate-500">{{ corporationName }}</span>
              </div>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button
              class="btn-action px-3 py-2.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-300 text-sm"
              :title="t('groupIndustry.modals.lineRental.title')"
              @click="openLineRentalModal"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </button>
            <button
              class="btn-action px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
              @click="openNewProjectModal"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              {{ t('groupIndustry.actions.createProject') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Main Tabs: My Projects / Available -->
      <div class="border-b border-slate-800 mb-6 card-enter" style="animation-delay: 30ms;">
        <div class="flex gap-6">
          <button
            class="main-tab px-1 pb-3 text-sm font-medium"
            :class="activeTab === 'my-projects' ? 'active' : 'text-slate-500'"
            @click="activeTab = 'my-projects'"
          >
            {{ t('groupIndustry.tabs.myProjects') }}
            <span
              class="ml-1.5 text-xs px-1.5 py-0.5 rounded"
              :class="activeTab === 'my-projects' ? 'bg-cyan-500/15 text-cyan-400' : 'bg-slate-700 text-slate-400'"
            >
              {{ myProjects.length }}
            </span>
          </button>
          <button
            class="main-tab px-1 pb-3 text-sm font-medium"
            :class="activeTab === 'available' ? 'active' : 'text-slate-500'"
            @click="activeTab = 'available'"
          >
            {{ t('groupIndustry.tabs.available') }}
            <span
              class="ml-1.5 text-xs px-1.5 py-0.5 rounded"
              :class="activeTab === 'available' ? 'bg-cyan-500/15 text-cyan-400' : 'bg-slate-700 text-slate-400'"
            >
              {{ availableProjects.length }}
            </span>
            <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-sky-500/15 text-sky-400 border border-sky-500/20">
              {{ t('groupIndustry.tabs.sameCorp') }}
            </span>
          </button>
        </div>
      </div>

      <!-- Loading state -->
      <div v-if="isLoading" class="flex items-center justify-center py-20">
        <LoadingSpinner size="lg" />
      </div>

      <!-- Error state -->
      <div v-else-if="error" class="bg-red-500/10 border border-red-500/20 rounded-xl p-6 text-center">
        <p class="text-red-400 text-sm">{{ error }}</p>
        <button
          class="mt-3 px-4 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm text-slate-300 transition-colors"
          @click="fetchProjects"
        >
          {{ t('common.actions.retry') }}
        </button>
      </div>

      <!-- My Projects Tab -->
      <div v-else-if="activeTab === 'my-projects'" class="screen-enter">
        <!-- KPI Cards -->
        <ProjectKpiCards :projects="myProjects" />

        <!-- Status filter pills -->
        <div class="flex gap-2 mb-6 card-enter" style="animation-delay: 300ms;">
          <button
            v-for="filter in STATUS_FILTERS"
            :key="filter"
            class="filter-pill px-3 py-1.5 rounded-lg text-sm font-medium border border-transparent"
            :class="statusFilter === filter ? 'active' : 'text-slate-500'"
            @click="statusFilter = filter"
          >
            {{ statusFilterLabel(filter) }}
            <span class="ml-1 text-xs opacity-60">{{ statusFilterCount(filter) }}</span>
          </button>
        </div>

        <!-- Project Cards Grid -->
        <div v-if="filteredProjects.length > 0" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <ProjectCard
            v-for="project in filteredProjects"
            :key="project.id"
            :project="project"
            @click="navigateToProject"
          />
        </div>

        <!-- Empty state -->
        <div v-else class="bg-slate-900/80 rounded-xl border border-dashed border-slate-700 p-12 text-center">
          <svg class="w-12 h-12 mx-auto text-slate-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          <p class="text-slate-400 text-sm mb-2">{{ t('groupIndustry.empty.title') }}</p>
          <p class="text-slate-600 text-xs mb-4">{{ t('groupIndustry.empty.description') }}</p>
          <button
            class="px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium inline-flex items-center gap-2"
            @click="openNewProjectModal"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            {{ t('groupIndustry.actions.createProject') }}
          </button>
        </div>
      </div>

      <!-- Available Tab -->
      <div v-else-if="activeTab === 'available'" class="screen-enter">
        <!-- Info banner -->
        <div class="bg-slate-800/60 rounded-xl border border-slate-700/50 p-4 mb-6 flex items-start gap-3 card-enter" style="animation-delay: 60ms;">
          <div class="w-8 h-8 rounded-lg bg-cyan-500/10 flex items-center justify-center flex-shrink-0">
            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <p class="text-sm text-slate-400">{{ t('groupIndustry.available.infoBanner') }}</p>
        </div>

        <!-- Available Project Cards (2 columns) -->
        <div v-if="availableProjects.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <AvailableProjectCard
            v-for="project in availableProjects"
            :key="project.id"
            :project="project"
            @join="handleJoinProject"
          />
        </div>

        <!-- Empty state -->
        <div v-else class="bg-slate-900/80 rounded-xl border border-dashed border-slate-700 p-12 text-center">
          <svg class="w-12 h-12 mx-auto text-slate-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <p class="text-slate-400 text-sm mb-2">{{ t('groupIndustry.available.empty') }}</p>
          <p class="text-slate-600 text-xs">{{ t('groupIndustry.available.emptyHint') }}</p>
        </div>
      </div>

    </div>

    <!-- Modals -->
    <NewProjectModal
      :show="showNewProjectModal"
      @close="closeNewProjectModal"
      @created="handleProjectCreated"
    />
    <LineRentalModal
      :show="showLineRentalModal"
      @close="closeLineRentalModal"
    />
  </MainLayout>
</template>

<style scoped>
.main-tab {
  transition: all 0.15s ease;
  border-bottom: 2px solid transparent;
  cursor: pointer;
}
.main-tab:hover {
  color: #94a3b8;
}
.main-tab.active {
  color: #38bdf8;
  border-bottom-color: #38bdf8;
}

.filter-pill {
  transition: all 0.15s ease;
}
.filter-pill:hover {
  background: rgba(51, 65, 85, 0.8);
}
.filter-pill.active {
  background: rgba(14, 165, 233, 0.15);
  color: #38bdf8;
  border-color: rgba(56, 189, 248, 0.3);
}

.btn-action {
  transition: all 0.15s ease;
}
.btn-action:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
.btn-action:active {
  transform: translateY(0);
}

@keyframes cardSlideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
.card-enter {
  animation: cardSlideUp 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) backwards;
}

@keyframes screenFadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}
.screen-enter {
  animation: screenFadeIn 0.3s ease-out;
}
</style>
