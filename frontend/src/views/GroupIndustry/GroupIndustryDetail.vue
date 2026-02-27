<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useSyncStore } from '@/stores/sync'
import { useEveImages } from '@/composables/useEveImages'
import { useToast } from '@/composables/useToast'
import BomTable from '@/components/group-industry/BomTable.vue'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import PendingRequestsTable from '@/components/group-industry/PendingRequestsTable.vue'
import ShareLinkCard from '@/components/group-industry/ShareLinkCard.vue'
import type { BomItem, GroupProject, GroupProjectStatus } from '@/stores/group-industry/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const projectStore = useGroupProjectStore()
const contributionStore = useGroupContributionStore()
const distributionStore = useGroupDistributionStore()
const syncStore = useSyncStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { showToast } = useToast()

const projectId = computed(() => route.params.id as string)

type DetailTab = 'bom' | 'contributions' | 'distribution' | 'members'
const activeTab = ref<DetailTab>('bom')

// Contribute modal state
const showContributeModal = ref(false)
const prefilledBomItem = ref<BomItem | undefined>(undefined)

// Edit modal state
const showEditModal = ref(false)
const editForm = ref({
  name: '',
  containerName: '',
  brokerFeePercent: 0,
  salesTaxPercent: 0,
})
const isEditSaving = ref(false)
const editError = ref<string | null>(null)

// Delete confirm state
const showDeleteConfirm = ref(false)
const isDeleting = ref(false)

const project = computed(() => projectStore.currentProject)
const isOwnerOrAdmin = computed(() =>
  project.value?.myRole === 'owner' || project.value?.myRole === 'admin'
)

const statusConfig: Record<GroupProjectStatus, { bg: string; text: string; border: string }> = {
  draft: { bg: 'bg-slate-700', text: 'text-slate-400', border: 'border-slate-600' },
  published: { bg: 'bg-violet-500/15', text: 'text-violet-400', border: 'border-violet-500/20' },
  in_progress: { bg: 'bg-cyan-500/15', text: 'text-cyan-400', border: 'border-cyan-500/20' },
  selling: { bg: 'bg-violet-500/15', text: 'text-violet-400', border: 'border-violet-500/20' },
  completed: { bg: 'bg-emerald-500/15', text: 'text-emerald-400', border: 'border-emerald-500/20' },
}

function statusLabel(status: GroupProjectStatus): string {
  return t(`groupIndustry.status.${status}`)
}

onMounted(async () => {
  await Promise.all([
    projectStore.fetchProjectDetail(projectId.value),
    projectStore.fetchBom(projectId.value),
  ])
})

// Watch for Mercure events that should trigger refetches
watch(
  () => syncStore.getSyncProgress(`group-project-${projectId.value}`),
  (progress) => {
    if (!progress) return
    if (progress.status === 'completed') {
      // Refresh relevant data based on event type
      const eventType = progress.data?.eventType as string | undefined
      if (eventType === 'contribution_submitted' || eventType === 'contribution_approved' || eventType === 'contribution_rejected') {
        projectStore.fetchBom(projectId.value)
        contributionStore.fetchContributions(projectId.value)
      } else if (eventType === 'member_joined' || eventType === 'member_left') {
        distributionStore.fetchMembers(projectId.value)
        projectStore.fetchProjectDetail(projectId.value)
      } else if (eventType === 'sale_recorded') {
        distributionStore.fetchSales(projectId.value)
        distributionStore.fetchDistribution(projectId.value)
      } else {
        // Generic refresh
        projectStore.fetchProjectDetail(projectId.value)
        projectStore.fetchBom(projectId.value)
      }
      syncStore.clearSyncStatus(`group-project-${projectId.value}`)
    }
  },
)

// Tab switching triggers lazy data loading
watch(activeTab, (tab) => {
  if (tab === 'contributions') {
    contributionStore.fetchContributions(projectId.value)
  } else if (tab === 'distribution') {
    distributionStore.fetchDistribution(projectId.value)
    distributionStore.fetchSales(projectId.value)
  } else if (tab === 'members') {
    distributionStore.fetchMembers(projectId.value)
  }
})

function goBack(): void {
  router.push('/group-industry')
}

async function copyShareLink(): Promise<void> {
  if (!project.value) return
  const link = `${window.location.origin}/group-industry/join/${project.value.shortLinkCode}`
  try {
    await navigator.clipboard.writeText(link)
    showToast('Link copied!', 'success')
  } catch {
    showToast('Failed to copy link', 'error')
  }
}

function openContributeModal(bomItem?: BomItem): void {
  prefilledBomItem.value = bomItem
  showContributeModal.value = true
}

function openEditModal(): void {
  if (!project.value) return
  editForm.value = {
    name: project.value.name ?? '',
    containerName: project.value.containerName ?? '',
    brokerFeePercent: project.value.brokerFeePercent,
    salesTaxPercent: project.value.salesTaxPercent,
  }
  editError.value = null
  isEditSaving.value = false
  showEditModal.value = true
}

async function handleEditSave(): Promise<void> {
  isEditSaving.value = true
  editError.value = null
  try {
    await projectStore.updateProject(projectId.value, {
      name: editForm.value.name || null,
      containerName: editForm.value.containerName || null,
      brokerFeePercent: editForm.value.brokerFeePercent,
      salesTaxPercent: editForm.value.salesTaxPercent,
    } as Partial<GroupProject>)
    showEditModal.value = false
  } catch (e) {
    editError.value = e instanceof Error ? e.message : t('groupIndustry.detail.editError')
    isEditSaving.value = false
  }
}

async function handleDeleteProject(): Promise<void> {
  isDeleting.value = true
  await projectStore.deleteProject(projectId.value)
  if (!projectStore.error) {
    router.push({ name: 'group-industry' })
  }
  isDeleting.value = false
}

function handleLeaveProject(): void {
  showToast(t('groupIndustry.members.leaveSuccess'), 'success')
  router.push({ name: 'group-industry' })
}

function onContributionSubmitted(): void {
  showContributeModal.value = false
  projectStore.fetchBom(projectId.value)
  if (activeTab.value === 'contributions') {
    contributionStore.fetchContributions(projectId.value)
  }
}

const projectDisplayName = computed(() => {
  if (!project.value) return ''
  if (project.value.name) return project.value.name
  return project.value.items.map(i => `${i.runs}x ${i.typeName}`).join(', ')
})

const totalRuns = computed(() =>
  project.value?.items.reduce((sum, i) => sum + i.runs, 0) ?? 0
)

const bomItemCount = computed(() => projectStore.materials.length)
const jobCount = computed(() => projectStore.jobs.length)
const contributionCount = computed(() => contributionStore.contributions.length)
const memberCount = computed(() => project.value?.membersCount ?? 0)
</script>

<template>
  <MainLayout>
    <!-- Loading -->
    <div v-if="projectStore.loading && !project" class="flex items-center justify-center py-20">
      <svg class="w-8 h-8 animate-spin text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
    </div>

    <div v-else-if="project">
      <!-- Breadcrumb -->
      <div class="mb-4">
        <div class="flex items-center gap-2 text-sm">
          <button @click="goBack" class="text-slate-500 hover:text-slate-300 transition-colors flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <button @click="goBack" class="text-slate-500 hover:text-slate-300 transition-colors cursor-pointer">
            {{ t('groupIndustry.title') }}
          </button>
          <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
          <span class="text-slate-300">{{ projectDisplayName }}</span>
        </div>
      </div>

      <!-- Project Header (compact) -->
      <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-5 mb-6">
        <div class="flex items-center gap-5">
          <!-- Item icons row -->
          <div class="flex flex-row gap-1 flex-shrink-0">
            <div
              v-for="item in project.items"
              :key="item.typeId"
              class="w-8 h-8 rounded bg-slate-800 border border-slate-700 overflow-hidden"
            >
              <img
                :src="getTypeIconUrl(item.typeId, 32)"
                :alt="item.typeName"
                class="w-full h-full object-cover"
                @error="onImageError"
              />
            </div>
          </div>

          <!-- Project info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3 mb-1">
              <h1 class="text-xl font-bold text-slate-100">{{ projectDisplayName }}</h1>
              <span
                class="text-xs px-2.5 py-1 rounded-lg font-medium border"
                :class="[statusConfig[project.status].bg, statusConfig[project.status].text, statusConfig[project.status].border]"
              >
                {{ statusLabel(project.status) }}
              </span>
              <span
                v-if="project.containerName"
                class="text-xs px-2 py-0.5 rounded-lg bg-violet-500/12 text-violet-400 border border-violet-500/25 font-medium inline-flex items-center gap-1.5"
              >
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                {{ project.containerName }}
              </span>
            </div>
            <p class="text-sm text-slate-500">
              {{ project.items.length }} {{ project.items.length === 1 ? 'item' : 'items' }}
              &middot; {{ totalRuns }} runs
              &middot; Manager: {{ project.ownerCharacterName }}
              &middot; {{ project.membersCount }} {{ project.membersCount === 1 ? 'contributor' : 'contributors' }}
            </p>
          </div>

          <!-- Actions -->
          <div class="flex gap-2 flex-shrink-0">
            <button
              @click="copyShareLink"
              class="px-3 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm flex items-center gap-2 transition-all hover:-translate-y-px"
              :title="t('groupIndustry.detail.shareLink')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
              {{ t('groupIndustry.detail.shareLink') }}
            </button>
            <button
              v-if="isOwnerOrAdmin"
              class="px-3 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm transition-all hover:-translate-y-px"
              :title="t('groupIndustry.detail.edit')"
              @click="openEditModal"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            </button>
            <button
              v-if="isOwnerOrAdmin"
              class="px-3 py-2 bg-slate-700 hover:bg-red-600/80 rounded-lg text-slate-400 hover:text-white text-sm transition-all hover:-translate-y-px"
              :title="t('groupIndustry.detail.delete')"
              @click="showDeleteConfirm = true"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Tab Bar -->
      <div class="border-b border-slate-800 mb-6">
        <div class="flex gap-6">
          <button
            @click="activeTab = 'bom'"
            class="px-1 pb-3 text-sm font-medium transition-colors border-b-2"
            :class="activeTab === 'bom' ? 'text-sky-400 border-sky-400' : 'text-slate-500 border-transparent hover:text-slate-400'"
          >
            {{ t('groupIndustry.tabs.materialsJobs') }}
            <span
              class="ml-1.5 text-xs px-1.5 py-0.5 rounded"
              :class="activeTab === 'bom' ? 'bg-cyan-500/15 text-cyan-400' : 'bg-slate-700 text-slate-400'"
            >
              {{ bomItemCount }} {{ bomItemCount === 1 ? 'item' : 'items' }} &middot; {{ jobCount }} {{ jobCount === 1 ? 'job' : 'jobs' }}
            </span>
          </button>
          <button
            @click="activeTab = 'contributions'"
            class="px-1 pb-3 text-sm font-medium transition-colors border-b-2"
            :class="activeTab === 'contributions' ? 'text-sky-400 border-sky-400' : 'text-slate-500 border-transparent hover:text-slate-400'"
          >
            {{ t('groupIndustry.tabs.contributions') }}
            <span
              class="ml-1.5 text-xs px-1.5 py-0.5 rounded"
              :class="activeTab === 'contributions' ? 'bg-cyan-500/15 text-cyan-400' : 'bg-slate-700 text-slate-400'"
            >
              {{ contributionCount }}
            </span>
          </button>
          <button
            @click="activeTab = 'distribution'"
            class="px-1 pb-3 text-sm font-medium transition-colors border-b-2"
            :class="activeTab === 'distribution' ? 'text-sky-400 border-sky-400' : 'text-slate-500 border-transparent hover:text-slate-400'"
          >
            {{ t('groupIndustry.tabs.distribution') }}
          </button>
          <button
            @click="activeTab = 'members'"
            class="px-1 pb-3 text-sm font-medium transition-colors border-b-2"
            :class="activeTab === 'members' ? 'text-sky-400 border-sky-400' : 'text-slate-500 border-transparent hover:text-slate-400'"
          >
            {{ t('groupIndustry.tabs.members') }}
            <span
              class="ml-1.5 text-xs px-1.5 py-0.5 rounded"
              :class="activeTab === 'members' ? 'bg-cyan-500/15 text-cyan-400' : 'bg-slate-700 text-slate-400'"
            >
              {{ memberCount }}
            </span>
          </button>
        </div>
      </div>

      <!-- Tab Content: Materials & Jobs -->
      <div v-if="activeTab === 'bom'">
        <!-- BOM description -->
        <p class="text-xs text-slate-500 mb-4">
          Leaf materials for {{ project.items.length }} items
          (<template v-for="(item, idx) in project.items" :key="item.typeId">
            <span class="text-slate-400">{{ item.typeName }}</span> x{{ item.runs }}<template v-if="idx < project.items.length - 1">, </template>
          </template>)
          <template v-if="project.blacklistGroupIds.length > 0">
            &middot; <span class="text-amber-400/60">{{ project.blacklistGroupIds.length }} categories blacklisted</span>
          </template>
          &middot; <span class="text-slate-400">Components &amp; BPCs in Jobs below</span>
        </p>

        <!-- Container Banner -->
        <ContainerBanner
          v-if="project.containerName"
          :container-name="project.containerName"
          :project-id="projectId"
        />

        <!-- Materials Table -->
        <BomTable @contribute="openContributeModal" />

        <!-- Jobs Table -->
        <JobsTable />
      </div>

      <!-- Tab Content: Contributions -->
      <div v-else-if="activeTab === 'contributions'">
        <ContributionTable :project-id="projectId" :is-admin="isOwnerOrAdmin">
          <template #actions>
            <button
              @click="openContributeModal()"
              class="px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-all hover:-translate-y-px"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Add Contribution
            </button>
          </template>
        </ContributionTable>
      </div>

      <!-- Tab Content: Distribution -->
      <div v-else-if="activeTab === 'distribution'">
        <SalesTable :project-id="projectId" :is-admin="isOwnerOrAdmin" />
        <FinancialSummary />
        <DistributionTable />
      </div>

      <!-- Tab Content: Members -->
      <div v-else-if="activeTab === 'members'">
        <!-- Header with member summary and copy link button -->
        <div class="flex items-center justify-between mb-6">
          <p class="text-sm text-slate-400">
            {{ distributionStore.members.filter(m => m.status === 'accepted').length }} active members
            <template v-if="distributionStore.members.filter(m => m.status === 'pending').length > 0">
              &middot; {{ distributionStore.members.filter(m => m.status === 'pending').length }} pending
            </template>
          </p>
          <button
            @click="copyShareLink"
            class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium flex items-center gap-2 transition-all hover:-translate-y-px"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
            </svg>
            Copy Join Link
          </button>
        </div>

        <MemberTable :project-id="projectId" :is-admin="isOwnerOrAdmin" @left="handleLeaveProject" />
        <PendingRequestsTable :project-id="projectId" :is-admin="isOwnerOrAdmin" />
        <ShareLinkCard v-if="project.shortLinkCode" :short-link-code="project.shortLinkCode" />
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="projectStore.error" class="text-center py-20">
      <p class="text-red-400 text-sm">{{ projectStore.error }}</p>
      <button @click="goBack" class="mt-4 text-sm text-slate-400 hover:text-cyan-400 transition-colors">
        &larr; {{ t('groupIndustry.detail.backToList') }}
      </button>
    </div>

    <!-- Contribute Modal -->
    <ContributeModal
      :show="showContributeModal"
      :project-id="projectId"
      :prefilled-bom-item="prefilledBomItem"
      @close="showContributeModal = false"
      @submitted="onContributionSubmitted"
    />

    <!-- Edit Project Modal -->
    <Teleport to="body">
      <div v-if="showEditModal" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showEditModal = false"></div>
        <div class="relative flex items-center justify-center min-h-screen p-4 pointer-events-none">
          <div class="bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-md pointer-events-auto">
            <!-- Header -->
            <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
              <h2 class="text-lg font-bold text-slate-100">{{ t('groupIndustry.detail.editProject') }}</h2>
              <button
                class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
                @click="showEditModal = false"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <!-- Body -->
            <div class="px-6 py-5 space-y-4">
              <div>
                <label class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.detail.editFields.name') }}</label>
                <input
                  v-model="editForm.name"
                  type="text"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none"
                  :placeholder="t('groupIndustry.detail.editFields.namePlaceholder')"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.detail.editFields.containerName') }}</label>
                <input
                  v-model="editForm.containerName"
                  type="text"
                  class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none"
                  :placeholder="t('groupIndustry.detail.editFields.containerPlaceholder')"
                />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.detail.editFields.brokerFee') }}</label>
                  <input
                    v-model.number="editForm.brokerFeePercent"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.detail.editFields.salesTax') }}</label>
                  <input
                    v-model.number="editForm.salesTaxPercent"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none"
                  />
                </div>
              </div>

              <p v-if="editError" class="text-sm text-red-400">{{ editError }}</p>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-end gap-3">
              <button
                class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300 text-sm font-medium transition-colors"
                @click="showEditModal = false"
              >
                {{ t('common.actions.cancel') }}
              </button>
              <button
                class="px-6 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-all disabled:opacity-50"
                :disabled="isEditSaving"
                @click="handleEditSave"
              >
                <svg v-if="isEditSaving" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                {{ t('common.actions.save') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Delete Confirm Modal -->
    <Teleport to="body">
      <div v-if="showDeleteConfirm" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="showDeleteConfirm = false"></div>
        <div class="relative flex items-center justify-center min-h-screen p-4 pointer-events-none">
          <div class="bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-sm pointer-events-auto">
            <!-- Header -->
            <div class="px-6 py-5 border-b border-slate-800">
              <h2 class="text-lg font-bold text-slate-100">{{ t('groupIndustry.detail.deleteConfirm.title') }}</h2>
            </div>

            <!-- Body -->
            <div class="px-6 py-5">
              <p class="text-sm text-slate-400">{{ t('groupIndustry.detail.deleteConfirm.message') }}</p>
              <p v-if="projectStore.error" class="mt-3 text-sm text-red-400">{{ projectStore.error }}</p>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-end gap-3">
              <button
                class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300 text-sm font-medium transition-colors"
                @click="showDeleteConfirm = false"
              >
                {{ t('common.actions.cancel') }}
              </button>
              <button
                class="px-6 py-2.5 bg-red-600 hover:bg-red-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-all disabled:opacity-50"
                :disabled="isDeleting"
                @click="handleDeleteProject"
              >
                <svg v-if="isDeleting" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                {{ t('common.actions.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </MainLayout>
</template>
