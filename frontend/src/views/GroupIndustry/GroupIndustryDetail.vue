<script setup lang="ts">
import { ref, computed, onMounted, watch, useId, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupSettlementStore } from '@/stores/group-industry/settlement'
import { useGroupActivityStore } from '@/stores/group-industry/activity'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import { useSyncStore } from '@/stores/sync'
import { useEveImages } from '@/composables/useEveImages'
import { useToast } from '@/composables/useToast'
import { useTabQuery } from '@/composables/useTabQuery'
import BomTable from '@/components/group-industry/BomTable.vue'
import MaterialSourcing from '@/components/group-industry/MaterialSourcing.vue'
import CollapsibleSourcing from '@/components/group-industry/CollapsibleSourcing.vue'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import JobContainersTable from '@/components/group-industry/JobContainersTable.vue'
import ProjectSellOrdersSection from '@/components/group-industry/ProjectSellOrdersSection.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import SaleSuggestionsPanel from '@/components/group-industry/SaleSuggestionsPanel.vue'
import ContractSaleSuggestions from '@/components/group-industry/ContractSaleSuggestions.vue'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import SellForecastCard from '@/components/group-industry/SellForecastCard.vue'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import ProjectDiscordRecap from '@/components/group-industry/ProjectDiscordRecap.vue'
import ProjectTransfers from '@/components/group-industry/ProjectTransfers.vue'
import ClosingChecksList from '@/components/group-industry/ClosingChecksList.vue'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import PendingRequestsTable from '@/components/group-industry/PendingRequestsTable.vue'
import ShareLinkCard from '@/components/group-industry/ShareLinkCard.vue'
import ProjectPhaseRail from '@/components/group-industry/ProjectPhaseRail.vue'
import ProjectNextActionCard from '@/components/group-industry/ProjectNextActionCard.vue'
import ProjectPriceAlertBanner from '@/components/group-industry/ProjectPriceAlertBanner.vue'
import ProjectTabBar from '@/components/group-industry/ProjectTabBar.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import { useGroupChainStore } from '@/stores/group-industry/chain'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import ProjectBuyOrBuild from '@/components/group-industry/ProjectBuyOrBuild.vue'
import ProjectResearch from '@/components/group-industry/ProjectResearch.vue'
import ProjectCourier from '@/components/group-industry/ProjectCourier.vue'
import ProjectStructureHealth from '@/components/group-industry/ProjectStructureHealth.vue'
import ProjectBlacklistEditor from '@/components/group-industry/ProjectBlacklistEditor.vue'
import ProjectSurplus from '@/components/group-industry/ProjectSurplus.vue'
import IntermediateStockProposal from '@/components/group-industry/IntermediateStockProposal.vue'
import ProjectStructures from '@/components/group-industry/ProjectStructures.vue'
import ProjectInvention from '@/components/group-industry/ProjectInvention.vue'
import ProjectPlanning from '@/components/group-industry/ProjectPlanning.vue'
import ProjectJournal from '@/components/group-industry/ProjectJournal.vue'
import SaveProjectTemplateModal from '@/components/group-industry/SaveProjectTemplateModal.vue'
import SellStructureSelect from '@/components/group-industry/SellStructureSelect.vue'
import type { BomItem, GroupProject, GroupProjectStatus, ProjectCockpit } from '@/stores/group-industry/types'
import { nextProjectStatuses } from './nextProjectStatus'
import { groupJoinUrl } from './groupJoinUrl'
import { DETAIL_TABS, detailTabOfNextAction, type ProjectDetailTab } from './projectDetailTabs'
import { PROJECTS_HOME } from './projectsHome'
import { isSourcingCollapsed, isSourcingComplete, readSourcingOverride, writeSourcingOverride, type SourcingOverride } from './sourcingCollapse'
import { groupProjectRefreshes, refreshesActivityOn, type GroupProjectRefresh } from './groupProjectRefreshes'
import { useFlash } from '@/composables/useUpdateFlash'
import { useRefetchOnReconnect } from '@/composables/useRefetchOnReconnect'

const { t } = useI18n()
const editNameFieldId = useId()
const editContainerNameFieldId = useId()
const editBrokerFeeFieldId = useId()
const editSalesTaxFieldId = useId()
const route = useRoute()
const router = useRouter()
const projectStore = useGroupProjectStore()
const chainStore = useGroupChainStore()
const contributionStore = useGroupContributionStore()
const distributionStore = useGroupDistributionStore()
const settlementStore = useGroupSettlementStore()
const activityStore = useGroupActivityStore()
const companionStore = useGroupCompanionStore()
const syncStore = useSyncStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { showToast } = useToast()

const projectId = computed(() => route.params.id as string)

const MINIMUM_TEAM_SIZE = 2
const TEAM_ONLY_TABS: readonly ProjectDetailTab[] = ['contributions', 'members']

const requestedTab = useTabQuery<ProjectDetailTab>(DETAIL_TABS, 'bom')
const membersLoaded = ref(false)

async function loadMembers(id: string): Promise<void> {
  await distributionStore.fetchMembers(id)
  membersLoaded.value = distributionStore.membersError === null && Array.isArray(distributionStore.members)
}

// Contribute modal state
const showContributeModal = ref(false)
const prefilledBomItem = ref<BomItem | undefined>(undefined)

// Edit modal state
const showEditModal = ref(false)
const editForm = ref({
  name: '',
  containerName: '',
  brokerFeePercent: null as number | null,
  salesTaxPercent: null as number | null,
  private: true,
  sellStructureId: null as number | null,
})
const isEditSaving = ref(false)
const editError = ref<string | null>(null)

// Delete confirm state
const showSaveTemplateModal = ref(false)
const showDeleteConfirm = ref(false)
const isDeleting = ref(false)

const showCompleteConfirm = ref(false)
const isAdvancingStatus = ref(false)
const statusError = ref<string | null>(null)

const project = computed(() => projectStore.currentProject)
const isOwnerOrAdmin = computed(() =>
  project.value?.myRole === 'owner' || project.value?.myRole === 'admin'
)
const isOwner = computed(() => project.value?.myRole === 'owner')
const isAcceptedMember = computed(() => project.value?.myStatus === 'accepted')
const isCompleted = computed(() => project.value?.status === 'completed')
const isWorkedOnAlone = computed(() => {
  const current = project.value
  if (!membersLoaded.value || !current) return false
  const hasPendingMember = distributionStore.members.some(member => member.status === 'pending')
  const pendingContributions = current.pendingContributionCount
  const hasContributionToReview = typeof pendingContributions === 'number' && pendingContributions > 0
  return current.membersCount < MINIMUM_TEAM_SIZE && !hasPendingMember && !hasContributionToReview
})
const activeTab = computed<ProjectDetailTab>({
  get: () => isWorkedOnAlone.value && TEAM_ONLY_TABS.includes(requestedTab.value) ? 'bom' : requestedTab.value,
  set: (tab) => { requestedTab.value = tab },
})
const nextStatuses = computed(() => (project.value ? nextProjectStatuses(project.value.status) : []))
const pendingStatus = ref<GroupProjectStatus | null>(null)

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

const cockpit = ref<ProjectCockpit | null>(null)

async function loadCockpit(id: string): Promise<void> {
  await projectStore.fetchCockpit(id)
  cockpit.value = projectStore.cockpit
}

onMounted(async () => {
  await Promise.all([
    projectStore.fetchProjectDetail(projectId.value),
    projectStore.fetchBom(projectId.value),
    loadCockpit(projectId.value),
    loadMembers(projectId.value),
  ])
  if (projectStore.currentProject?.containerName) {
    await projectStore.fetchContainerVerification(projectId.value)
  }
  if (activeTab.value === 'journal') {
    await activityStore.fetchActivity(projectId.value)
  }
  openContributionOfQuery()
})

const refreshGroupProject: Record<GroupProjectRefresh, (id: string) => Promise<void>> = {
  bom: projectStore.fetchBom,
  contributions: contributionStore.fetchContributions,
  members: loadMembers,
  projectDetail: projectStore.fetchProjectDetail,
  sales: distributionStore.fetchSales,
  saleSuggestions: id => (isOwnerOrAdmin.value ? distributionStore.fetchSaleSuggestions(id) : Promise.resolve()),
  distribution: id => Promise.all([distributionStore.fetchDistribution(id), settlementStore.fetchForProject(id)]).then(() => undefined),
  settlement: settlementStore.fetchForProject,
  cockpit: loadCockpit,
  activity: activityStore.fetchActivity,
}

const { isFlashing: projectUpdated, flash: flashProject } = useFlash()

watch(
  () => syncStore.getGroupProjectEvent(projectId.value),
  (event) => {
    if (!event) return
    const refreshKinds = refreshesActivityOn(event.action)
      ? [...groupProjectRefreshes(event.action), 'activity' as const]
      : groupProjectRefreshes(event.action)
    const refreshes = refreshKinds.map(refresh => refreshGroupProject[refresh](projectId.value))
    Promise.all(refreshes).then(flashProject)
  },
)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== projectId.value) return
    Promise.all([
      refreshGroupProject.bom(projectId.value),
      refreshGroupProject.projectDetail(projectId.value),
      refreshGroupProject.cockpit(projectId.value),
    ]).then(flashProject)
  },
)

useRefetchOnReconnect(() => Promise.all([
  refreshGroupProject.bom(projectId.value),
  refreshGroupProject.projectDetail(projectId.value),
  refreshGroupProject.cockpit(projectId.value),
]).then(flashProject))

// Tab switching triggers lazy data loading
watch(activeTab, (tab) => {
  if (tab === 'contributions') {
    contributionStore.fetchContributions(projectId.value)
  } else if (tab === 'distribution') {
    distributionStore.fetchDistribution(projectId.value)
    settlementStore.fetchForProject(projectId.value)
  } else if (tab === 'members') {
    loadMembers(projectId.value)
  } else if (tab === 'journal') {
    activityStore.fetchActivity(projectId.value)
  }
})

function goBack(): void {
  router.push(PROJECTS_HOME)
}

async function copyShareLink(): Promise<void> {
  if (!project.value) return
  const link = groupJoinUrl(window.location.origin, project.value.shortLinkCode)
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

function openContributionOfQuery(): void {
  const lineId = route.query.contribute
  if (typeof lineId !== 'string') return
  const line = projectStore.bomItems.find(item => item.id === lineId)
  if (line !== undefined) openContributeModal(line)
  const { contribute: _consumed, ...remainingQuery } = route.query
  router.replace({ query: remainingQuery })
}

watch(() => route.query.contribute, openContributionOfQuery)

function openCompanion(): void {
  companionStore.open(projectId.value)
}

function feeOrNull(percent: number | string | null): number | null {
  return typeof percent === 'number' ? percent : null
}

const editFeesUnknown = computed(() => feeOrNull(editForm.value.brokerFeePercent) === null || feeOrNull(editForm.value.salesTaxPercent) === null)

const sellStructureChanged = computed(() => editForm.value.sellStructureId !== null && editForm.value.sellStructureId !== project.value?.sellStructureId)

const currentSellStructure = computed(() => {
  const current = project.value
  if (!current || current.sellStructureId === null) return null
  return { id: current.sellStructureId, name: current.sellStructureName ?? `Structure ${current.sellStructureId}` }
})

function openEditModal(): void {
  if (!project.value) return
  editForm.value = {
    name: project.value.name ?? '',
    containerName: project.value.containerName ?? '',
    brokerFeePercent: project.value.brokerFeePercent,
    salesTaxPercent: project.value.salesTaxPercent,
    private: project.value.private,
    sellStructureId: project.value.sellStructureId,
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
      brokerFeePercent: feeOrNull(editForm.value.brokerFeePercent),
      salesTaxPercent: feeOrNull(editForm.value.salesTaxPercent),
      private: editForm.value.private,
      ...(sellStructureChanged.value ? { sellStructureId: editForm.value.sellStructureId } : {}),
    } as Partial<GroupProject>)
    showEditModal.value = false
  } catch (e) {
    editError.value = e instanceof Error ? e.message : t('groupIndustry.detail.editError')
    isEditSaving.value = false
  }
}

const jobsSection = ref<HTMLElement | null>(null)

function actOnNextAction(): void {
  const action = cockpit.value?.nextAction
  if (!action) return
  if (action.targetStatus !== null) {
    requestNextStatus(action.targetStatus)
    return
  }
  const bomLine = action.code === 'source_material' && action.bomLineId !== null
    ? projectStore.bomItems.find(item => item.id === action.bomLineId)
    : undefined
  if (bomLine) {
    openContributeModal(bomLine)
    return
  }
  if (action.tab !== null) {
    activeTab.value = detailTabOfNextAction(action.tab)
  }
  if (action.code === 'launch_job') {
    void nextTick(() => jobsSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }
}

function nextStatusLabel(target: GroupProjectStatus): string {
  if (project.value?.status === 'draft') {
    return t(`groupIndustry.detail.draftActions.${target}`)
  }
  return t('groupIndustry.detail.nextStatus', { status: statusLabel(target) })
}

function requestNextStatus(target: GroupProjectStatus): void {
  statusError.value = null
  pendingStatus.value = target
  if (target === 'completed') {
    settlementStore.fetchForProject(projectId.value)
    showCompleteConfirm.value = true
    return
  }
  advanceStatus()
}

async function advanceStatus(): Promise<void> {
  if (!pendingStatus.value) return
  isAdvancingStatus.value = true
  statusError.value = null
  try {
    await projectStore.updateProject(projectId.value, { status: pendingStatus.value })
    showCompleteConfirm.value = false
    await loadCockpit(projectId.value)
  } catch (e) {
    statusError.value = e instanceof Error ? e.message : t('groupIndustry.detail.editError')
  }
  isAdvancingStatus.value = false
}

async function handleDeleteProject(): Promise<void> {
  isDeleting.value = true
  await projectStore.deleteProject(projectId.value)
  if (!projectStore.error) {
    router.push(PROJECTS_HOME)
  }
  isDeleting.value = false
}

function handleLeaveProject(): void {
  showToast(t('groupIndustry.members.leaveSuccess'), 'success')
  router.push(PROJECTS_HOME)
}

function onBlacklistSaved(): void {
  projectStore.fetchBom(projectId.value)
  loadCockpit(projectId.value)
}

function onBuyOrBuildSwitched(): void {
  onBlacklistSaved()
  chainStore.fetchChain(projectId.value)
}

function onContributionSubmitted(): void {
  showContributeModal.value = false
  projectStore.fetchBom(projectId.value)
  loadCockpit(projectId.value)
  if (activeTab.value === 'contributions') {
    contributionStore.fetchContributions(projectId.value)
  }
}

const projectDisplayName = computed(() => {
  if (!project.value) return ''
  if (project.value.name) return project.value.name
  return project.value.items.map(i => `${i.runs}x ${i.typeName}`).join(', ')
})

const sourcingOverride = ref<SourcingOverride | null>(null)
const sourcingComplete = computed(() => isSourcingComplete(projectStore.materials))
const sourcingCollapsed = computed(() => isSourcingCollapsed(sourcingComplete.value, sourcingOverride.value))

watch(projectId, id => {
  sourcingOverride.value = readSourcingOverride(localStorage, id)
}, { immediate: true })

function toggleSourcing(): void {
  const next: SourcingOverride = sourcingCollapsed.value ? 'open' : 'closed'
  sourcingOverride.value = next
  writeSourcingOverride(localStorage, projectId.value, next)
}

const HEADER_VISIBLE_PRODUCTS = 8

const headerProducts = computed(() => project.value?.items.slice(0, HEADER_VISIBLE_PRODUCTS) ?? [])
const hiddenHeaderProducts = computed(() => project.value?.items.slice(HEADER_VISIBLE_PRODUCTS) ?? [])
const hiddenHeaderProductNames = computed(() => hiddenHeaderProducts.value.map(item => item.typeName).join(', '))

const totalRuns = computed(() =>
  project.value?.items.reduce((sum, i) => sum + i.runs, 0) ?? 0
)

const bomItemCount = computed(() => projectStore.materials.length)
const jobCount = computed(() => projectStore.jobs.length)
const materialsCount = computed(() =>
  `${bomItemCount.value} ${bomItemCount.value === 1 ? 'item' : 'items'} · ${jobCount.value} ${jobCount.value === 1 ? 'job' : 'jobs'}`
)
const contributionCount = computed(() => contributionStore.contributions.length)
const shareLinkLabel = computed(() => t(isWorkedOnAlone.value ? 'groupIndustry.detail.invite' : 'groupIndustry.detail.shareLink'))
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
      <IndustryModuleTabs active="projects" />

      <!-- Breadcrumb -->
      <div class="mb-4">
        <div class="flex items-center gap-2 text-sm">
          <button @click="goBack" class="text-slate-500 hover:text-slate-300 transition-colors flex items-center gap-1" :aria-label="t('common.actions.back')">
            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <button @click="goBack" class="text-slate-500 hover:text-slate-300 transition-colors cursor-pointer">
            {{ t('groupIndustry.title') }}
          </button>
          <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
          <span class="text-slate-300">{{ projectDisplayName }}</span>
        </div>
      </div>

      <!-- Project Header (compact) -->
      <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-5 mb-6">
        <div data-testid="project-header" class="flex flex-wrap items-center gap-x-5 gap-y-3">
          <!-- Item icons row -->
          <div data-testid="project-header-products" class="flex flex-row flex-wrap items-center gap-1 flex-shrink-0 max-w-full">
            <div
              v-for="item in headerProducts"
              :key="item.typeId"
              data-testid="project-header-product"
              class="w-8 h-8 rounded bg-slate-800 border border-slate-700 overflow-hidden"
            >
              <img
                :src="getTypeIconUrl(item.typeId, 32)"
                :alt="item.typeName"
                :title="item.typeName"
                class="w-full h-full object-cover"
                @error="onImageError"
              />
            </div>
            <span
              v-if="hiddenHeaderProducts.length > 0"
              data-testid="project-header-products-more"
              class="h-8 px-2 rounded bg-slate-800 border border-slate-700 text-xs font-medium text-slate-300 inline-flex items-center"
              :title="hiddenHeaderProductNames"
            >+{{ hiddenHeaderProducts.length }}</span>
          </div>

          <!-- Project info -->
          <div class="flex-1 min-w-[16rem]">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-1">
              <h1 data-testid="project-header-title" class="text-xl font-bold text-slate-100 break-words min-w-0">{{ projectDisplayName }}</h1>
              <span
                class="text-xs px-2.5 py-1 rounded-lg font-medium border"
                :class="[statusConfig[project.status].bg, statusConfig[project.status].text, statusConfig[project.status].border]"
              >
                {{ statusLabel(project.status) }}
              </span>
              <span
                v-if="project.private"
                class="text-xs px-2.5 py-1 rounded-lg font-medium border bg-amber-500/15 text-amber-400 border-amber-500/20"
              >{{ t('groupIndustry.private') }}</span>
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
          <div class="flex flex-wrap gap-2 max-w-full">
            <button
              @click="copyShareLink"
              class="px-3 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm flex items-center gap-2 transition-all hover:-translate-y-px"
              :title="shareLinkLabel"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
              {{ shareLinkLabel }}
            </button>
            <button
              v-if="isAcceptedMember && !isCompleted"
              type="button"
              data-testid="open-companion"
              class="px-3 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm transition-all hover:-translate-y-px"
              :title="t('groupIndustry.companion.openTitle')"
              @click="openCompanion"
            >
              {{ t('groupIndustry.companion.open') }}
            </button>
            <button
              v-if="isAcceptedMember"
              data-testid="save-as-template"
              class="px-3 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm transition-all hover:-translate-y-px"
              @click="showSaveTemplateModal = true"
            >
              {{ t('groupIndustry.detail.saveAsTemplate') }}
            </button>
            <template v-if="isOwnerOrAdmin">
              <button
                v-for="target in nextStatuses"
                :key="target"
                class="px-3 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-all hover:-translate-y-px disabled:opacity-50"
                :disabled="isAdvancingStatus"
                @click="requestNextStatus(target)"
              >
                {{ nextStatusLabel(target) }}
              </button>
            </template>
            <button
              v-if="isOwnerOrAdmin"
              class="px-3 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm transition-all hover:-translate-y-px"
              :title="t('groupIndustry.detail.edit')"
              @click="openEditModal"
              :aria-label="t('groupIndustry.detail.edit')"
            >
              <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
            </button>
            <button
              v-if="isOwnerOrAdmin && !isCompleted"
              class="px-3 py-2 bg-slate-700 hover:bg-red-600/80 rounded-lg text-slate-400 hover:text-white text-sm transition-all hover:-translate-y-px"
              :title="t('groupIndustry.detail.delete')"
              @click="showDeleteConfirm = true"
              :aria-label="t('groupIndustry.detail.delete')"
            >
              <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            </button>
          </div>
        </div>
        <p v-if="statusError && !showCompleteConfirm" class="mt-3 text-sm text-red-400">{{ statusError }}</p>
      </div>

      <template v-if="cockpit">
        <ProjectPhaseRail :phases="cockpit.phases" />
        <ProjectNextActionCard :next-action="cockpit.nextAction" :disabled="isAdvancingStatus" @act="actOnNextAction" />
        <ProjectPriceAlertBanner v-if="cockpit.priceAlert !== null" :price-alert="cockpit.priceAlert" @view-sales="activeTab = 'sales'" />
      </template>
      <p v-else-if="projectStore.cockpitError" class="mb-6 text-xs text-slate-500">
        {{ t('groupIndustry.cockpit.unavailable') }}
      </p>

      <ProjectTabBar
        v-model="activeTab"
        :materials-count="materialsCount"
        :contribution-count="contributionCount"
        :member-count="memberCount"
        :hidden-tabs="isWorkedOnAlone ? TEAM_ONLY_TABS : []"
      />

      <!-- Tab Content: Materials & Jobs -->
      <div v-if="activeTab === 'bom'" class="relative rounded-xl" :class="{ 'hud-updated': projectUpdated }">
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

        <ProjectBlacklistEditor
          :project-id="projectId"
          :status="project.status"
          :can-edit="isOwnerOrAdmin"
          :blacklist-group-ids="project.blacklistGroupIds"
          :blacklist-type-ids="project.blacklistTypeIds"
          @saved="onBlacklistSaved"
        />

        <CollapsibleSourcing
          :complete="sourcingComplete"
          :collapsed="sourcingCollapsed"
          :material-count="projectStore.materials.length"
          @toggle="toggleSourcing"
          @open-chain="activeTab = 'chain'"
        >
          <!-- Container Banner -->
          <ContainerBanner
            v-if="project.containerName"
            :container-name="project.containerName"
            :project-id="projectId"
            :can-verify="isOwnerOrAdmin"
          />

          <ProjectCourier :project-id="projectId" />
        </CollapsibleSourcing>

        <IntermediateStockProposal
          v-if="isOwner && !isCompleted"
          :project-id="projectId"
          :project-status="project.status"
          @validated="onContributionSubmitted"
        />

        <!-- Materials Table -->
        <BomTable @contribute="openContributeModal" />

        <MaterialSourcing v-if="!sourcingCollapsed" :project-id="projectId" />

        <ProjectSurplus v-if="isCompleted" :project-id="projectId" class="mt-6" />

        <!-- Jobs Table -->
        <div ref="jobsSection" data-testid="project-jobs-section">
          <JobsTable @contribute="openContributeModal" />
        </div>
      </div>

      <!-- Tab Content: Contributions -->
      <div v-else-if="activeTab === 'contributions'" class="relative rounded-xl" :class="{ 'hud-updated': projectUpdated }">
        <ContributionTable :project-id="projectId" :is-admin="isOwnerOrAdmin">
          <template #actions>
            <button
              v-if="!isCompleted"
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
        <JobContainersTable v-if="isOwnerOrAdmin" />
      </div>

      <!-- Tab Content: Distribution -->
      <div v-else-if="activeTab === 'distribution'" class="relative rounded-xl" :class="{ 'hud-updated': projectUpdated }">
        <FinancialCascade />
        <FinancialSummary v-if="!project?.personalUse" />
        <SellForecastCard v-if="!project?.personalUse" :project-id="projectId" />
        <DistributionTable />
        <ProjectTransfers :project-id="projectId" />
        <ProjectDiscordRecap class="mt-8" />
      </div>

      <div v-else-if="activeTab === 'sales'" class="relative rounded-xl" :class="{ 'hud-updated': projectUpdated }">
        <ProjectSellOrdersSection :project-id="projectId" />
        <SalesTable :project-id="projectId" :is-admin="isOwnerOrAdmin" />
        <SaleSuggestionsPanel v-if="isOwnerOrAdmin" :project-id="projectId" :recording-disabled="isCompleted" />
        <ContractSaleSuggestions v-if="isOwnerOrAdmin" :project-id="projectId" :recording-disabled="isCompleted" />
      </div>

      <div v-else-if="activeTab === 'chain'" class="relative rounded-xl space-y-6">
        <ProductionChain :project-id="projectId" />
        <ProjectBuyOrBuild
          :project-id="projectId"
          :can-switch="isOwnerOrAdmin && !isCompleted"
          :blacklist-type-ids="project.blacklistTypeIds"
          @switched="onBuyOrBuildSwitched"
        />
        <ProjectResearch :project-id="projectId" />
        <ProjectStructures
          :project-id="projectId"
          :can-switch="isOwnerOrAdmin && !isCompleted"
          @switched="onBuyOrBuildSwitched"
        />
        <ProjectStructureHealth :project-id="projectId" />
      </div>

      <div v-else-if="activeTab === 'planning'" class="relative rounded-xl">
        <ProjectPlanning :project-id="projectId" />
      </div>

      <div v-else-if="activeTab === 'invention'" class="relative rounded-xl">
        <ProjectInvention :project-id="projectId" />
      </div>

      <div v-else-if="activeTab === 'journal'" class="relative rounded-xl">
        <div
          v-if="activityStore.error !== null"
          data-testid="project-journal-error"
          role="alert"
          class="mb-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
        >
          <p class="font-semibold">{{ t('groupIndustry.journal.loadError') }}</p>
          <p class="mt-1 break-words">{{ activityStore.error }}</p>
        </div>
        <ProjectJournal
          :events="activityStore.events"
          :running-jobs="activityStore.runningJobs"
          :live="syncStore.isConnected"
        />
      </div>

      <!-- Tab Content: Members -->
      <div v-else-if="activeTab === 'members'" class="relative rounded-xl" :class="{ 'hud-updated': projectUpdated }">
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

        <MemberTable :project-id="projectId" :is-admin="isOwnerOrAdmin" :is-owner="isOwner" :owner-corporation-id="project.ownerCorporationId" @left="handleLeaveProject" />
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

    <SaveProjectTemplateModal
      v-if="project"
      :show="showSaveTemplateModal"
      :project-id="projectId"
      :default-name="projectDisplayName"
      @close="showSaveTemplateModal = false"
      @saved="showSaveTemplateModal = false"
    />

    <!-- Edit Project Modal -->
    <BaseModal
      :show="showEditModal"
      :title="t('groupIndustry.detail.editProject')"
      panel-class="border-slate-700 max-w-md"
      @close="showEditModal = false"
    >
      <!-- Header -->
      <template #title="{ titleId }">
        <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
          <h2 :id="titleId" class="text-lg font-bold text-slate-100">{{ t('groupIndustry.detail.editProject') }}</h2>
          <button
            class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
            @click="showEditModal = false"
            :aria-label="t('common.actions.close')"
          >
            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </template>

      <!-- Body -->
      <div class="px-6 py-5 space-y-4">
        <div>
          <label :for="editNameFieldId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.detail.editFields.name') }}</label>
          <input
            :id="editNameFieldId"
            v-model="editForm.name"
            type="text"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none"
            :placeholder="t('groupIndustry.detail.editFields.namePlaceholder')"
          />
        </div>
        <div>
          <label :for="editContainerNameFieldId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.detail.editFields.containerName') }}</label>
          <input
            :id="editContainerNameFieldId"
            v-model="editForm.containerName"
            type="text"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none"
            :placeholder="t('groupIndustry.detail.editFields.containerPlaceholder')"
          />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label :for="editBrokerFeeFieldId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.detail.editFields.brokerFee') }}</label>
            <input
              :id="editBrokerFeeFieldId"
              v-model.number="editForm.brokerFeePercent"
              type="number"
              step="0.01"
              min="0"
              max="100"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none"
            />
          </div>
          <div>
            <label :for="editSalesTaxFieldId" class="block text-sm font-medium text-slate-400 mb-1">{{ t('groupIndustry.detail.editFields.salesTax') }}</label>
            <input
              :id="editSalesTaxFieldId"
              v-model.number="editForm.salesTaxPercent"
              type="number"
              step="0.01"
              min="0"
              max="100"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none"
            />
          </div>
        </div>
        <p v-if="editFeesUnknown" class="text-xs text-amber-400">{{ t('groupIndustry.detail.editFields.feesUnknownHint') }}</p>
        <SellStructureSelect
          v-model="editForm.sellStructureId"
          :current-structure="currentSellStructure"
          :disabled="isCompleted"
          :preselect-preferred="false"
        />
        <p v-if="editForm.sellStructureId === null" data-testid="sell-structure-unset" class="text-xs text-slate-500">{{ t('groupIndustry.sellStructure.notSet') }}</p>
        <div>
          <label class="flex items-center gap-2.5 cursor-pointer">
            <input
              v-model="editForm.private"
              data-testid="edit-project-private"
              type="checkbox"
              class="w-3.5 h-3.5 rounded border-slate-600 bg-slate-800 accent-cyan-500 cursor-pointer"
            />
            <span class="text-sm font-medium text-slate-400">{{ t('groupIndustry.detail.editFields.private') }}</span>
          </label>
          <p class="text-xs text-slate-500 mt-1.5">{{ t('groupIndustry.modals.newProject.privateHint') }}</p>
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
    </BaseModal>

    <!-- Delete Confirm Modal -->
    <BaseModal
      :show="showDeleteConfirm"
      :title="t('groupIndustry.detail.deleteConfirm.title')"
      panel-class="border-slate-700 max-w-sm"
      @close="showDeleteConfirm = false"
    >
      <!-- Header -->
      <template #title="{ titleId }">
        <div class="px-6 py-5 border-b border-slate-800">
          <h2 :id="titleId" class="text-lg font-bold text-slate-100">{{ t('groupIndustry.detail.deleteConfirm.title') }}</h2>
        </div>
      </template>

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
    </BaseModal>

    <BaseModal
      :show="showCompleteConfirm"
      :title="t('groupIndustry.detail.completeConfirm.title')"
      panel-class="border-slate-700 max-w-lg"
      @close="showCompleteConfirm = false"
    >
      <template #title="{ titleId }">
        <div class="px-6 py-5 border-b border-slate-800">
          <h2 :id="titleId" class="text-lg font-bold text-slate-100">{{ t('groupIndustry.detail.completeConfirm.title') }}</h2>
        </div>
      </template>

      <div class="px-6 py-5">
        <p class="text-sm text-slate-400">{{ t('groupIndustry.detail.completeConfirm.message') }}</p>
        <template v-if="settlementStore.settlement">
          <h3 class="mt-4 mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ t('groupIndustry.detail.completeConfirm.checksTitle') }}</h3>
          <ClosingChecksList :checks="settlementStore.settlement.checks" />
        </template>
        <p v-if="statusError" class="mt-3 text-sm text-red-400">{{ statusError }}</p>
      </div>

      <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-end gap-3">
        <button
          class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300 text-sm font-medium transition-colors"
          @click="showCompleteConfirm = false"
        >
          {{ t('common.actions.cancel') }}
        </button>
        <button
          class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-all disabled:opacity-50"
          :disabled="isAdvancingStatus"
          @click="advanceStatus"
        >
          <svg v-if="isAdvancingStatus" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ t('groupIndustry.detail.completeConfirm.confirm') }}
        </button>
      </div>
    </BaseModal>
  </MainLayout>
</template>
