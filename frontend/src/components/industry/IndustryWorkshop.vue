<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useIndustryJobsSync } from '@/composables/useIndustryJobsSync'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { GroupProject, GroupProjectStatus, ProjectNextActionCode } from '@/stores/group-industry/types'
import { useIndustryStore } from '@/stores/industry'
import { useIndustryActionsStore, type IndustryAction } from '@/stores/industry/actions'
import { useSlotsStore } from '@/stores/industry/slots'
import { useStockpileStore } from '@/stores/industry/stockpile'
import type { StockpileItemStatus } from '@/stores/industry/types'
import { useSyncStore } from '@/stores/sync'
import { detailTabOfNextAction } from '@/views/GroupIndustry/projectDetailTabs'

type TeamFilter = 'all' | 'solo' | 'group'
type StockpileProposal = StockpileItemStatus & { suggestedProjectRuns: number }

const MAX_UPCOMING_JOBS = 8
const SECONDS_PER_HOUR = 3600
const SECONDS_PER_MINUTE = 60
const MINUTES_PAD = 2
const TEAM_FILTERS: TeamFilter[] = ['all', 'solo', 'group']
const SOLO_MEMBERS_COUNT = 1
const QUANTITY_CODES: readonly ProjectNextActionCode[] = ['review_contributions', 'source_material', 'launch_job', 'record_sale']
const LIFECYCLE_STEPS: Record<GroupProjectStatus, number> = {
  draft: 1,
  published: 2,
  in_progress: 3,
  selling: 4,
  completed: 5,
}
const LIFECYCLE_SEGMENTS = [1, 2, 3, 4, 5]
const STATUS_CLASSES: Record<GroupProjectStatus, string> = {
  draft: 'bg-slate-700 text-slate-400 border-slate-600',
  published: 'bg-violet-500/15 text-violet-400 border-violet-500/20',
  in_progress: 'bg-cyan-500/15 text-cyan-400 border-cyan-500/20',
  selling: 'bg-violet-500/15 text-violet-400 border-violet-500/20',
  completed: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
}

const { t, n } = useI18n()
const router = useRouter()
const actionsStore = useIndustryActionsStore()
const slotsStore = useSlotsStore()
const industryStore = useIndustryStore()
const stockpileStore = useStockpileStore()
const groupProjectStore = useGroupProjectStore()
const syncStore = useSyncStore()
const { getTypeIconUrl, onImageError } = useEveImages()

useIndustryJobsSync(() => slotsStore.fetchSlots())

onMounted(() => {
  void Promise.all([actionsStore.fetchActions(), slotsStore.fetchSlots(), groupProjectStore.fetchMyProjects(), stockpileStore.fetchStockpileStatus()])
})

watch(() => syncStore.getSyncProgress('industry-project'), () => {
  void groupProjectStore.fetchMyProjects()
})

function projectLabel(name: string | null): string {
  return name === null || name === '' ? t('industry.workshop.unnamedProject') : name
}

function actionPhrase(action: IndustryAction): string {
  const base = `industry.workshop.actions.${action.code}`
  const quantityIsUnknown = QUANTITY_CODES.includes(action.code) && action.quantity === null
  return t(quantityIsUnknown ? `${base}.textUnknownQuantity` : `${base}.text`, {
    typeName: action.typeName,
    quantity: action.quantity === null ? '' : n(action.quantity),
  })
}

const stockpileProposals = computed<StockpileProposal[]>(() =>
  Object.values(stockpileStore.stockpileStatus?.stages ?? {})
    .flatMap(stage => stage.items)
    .filter((item): item is StockpileProposal => item.suggestedProjectRuns !== null)
    .sort((left, right) => left.percent - right.percent),
)

const queueCount = computed(() =>
  actionsStore.count === null ? null : actionsStore.count + stockpileProposals.value.length,
)

const isQueueEmpty = computed(() =>
  actionsStore.isLoaded && actionsStore.actions.length === 0 && stockpileProposals.value.length === 0,
)

function createStockpileProject(proposal: StockpileProposal): void {
  industryStore.navigationIntent = {
    target: 'projects',
    prefill: { typeId: proposal.typeId, typeName: proposal.typeName, runs: proposal.suggestedProjectRuns },
  }
}

function openCockpit(projectId: string, action: IndustryAction | null = null): void {
  const location: RouteLocationRaw = { name: 'industry-project', params: { id: projectId } }
  if (action !== null && action.tab !== null) {
    router.push({ ...location, query: { tab: detailTabOfNextAction(action.tab) } })
    return
  }
  router.push(location)
}

const MIDDLE_MOUSE_BUTTON = 1

function openProjectInNewTab(projectId: string): void {
  window.open(router.resolve({ name: 'industry-project', params: { id: projectId } }).href, '_blank')
}

function openProjectRow(projectId: string, event: MouseEvent | KeyboardEvent): void {
  if (event.ctrlKey || event.metaKey) {
    openProjectInNewTab(projectId)
    return
  }
  openCockpit(projectId)
}

function openProjectRowOnMiddleClick(projectId: string, event: MouseEvent): void {
  if (event.button !== MIDDLE_MOUSE_BUTTON) return
  openProjectInNewTab(projectId)
}

const upcomingJobs = computed(() =>
  [...(slotsStore.data?.timeline ?? [])]
    .sort((left, right) => Date.parse(left.endDate) - Date.parse(right.endDate))
    .slice(0, MAX_UPCOMING_JOBS),
)

const hasLoadedJobs = computed(() => slotsStore.data !== null)

function formatTimeLeft(seconds: number): string {
  const hours = Math.floor(seconds / SECONDS_PER_HOUR)
  const minutes = Math.floor((seconds % SECONDS_PER_HOUR) / SECONDS_PER_MINUTE)
  if (hours > 0) return `${hours}h ${String(minutes).padStart(MINUTES_PAD, '0')}m`
  return `${minutes}m`
}

const sortedProjects = computed(() =>
  [...groupProjectStore.myProjects].sort((left, right) => {
    const leftIsDone = left.status === 'completed' ? 1 : 0
    const rightIsDone = right.status === 'completed' ? 1 : 0
    if (leftIsDone !== rightIsDone) return leftIsDone - rightIsDone
    return Date.parse(right.createdAt) - Date.parse(left.createdAt)
  }),
)

function isSolo(project: GroupProject): boolean {
  return project.membersCount === SOLO_MEMBERS_COUNT
}

function isGroup(project: GroupProject): boolean {
  return project.membersCount > SOLO_MEMBERS_COUNT
}

function filterCount(filter: TeamFilter): number {
  if (filter === 'solo') return sortedProjects.value.filter(isSolo).length
  if (filter === 'group') return sortedProjects.value.filter(isGroup).length
  return sortedProjects.value.length
}

const teamFilter = ref<TeamFilter>('all')

const visibleProjects = computed(() => {
  if (teamFilter.value === 'solo') return sortedProjects.value.filter(isSolo)
  if (teamFilter.value === 'group') return sortedProjects.value.filter(isGroup)
  return sortedProjects.value
})

const pickedProjectId = ref<string | null>(null)

const selectedProject = computed<GroupProject | null>(() =>
  visibleProjects.value.find(project => project.id === pickedProjectId.value) ?? visibleProjects.value[0] ?? null,
)

const selectedAction = computed<IndustryAction | null>(() =>
  actionsStore.actions.find(action => action.projectId === selectedProject.value?.id) ?? null,
)

function productLabel(project: GroupProject): string {
  const [firstItem] = project.items
  if (firstItem === undefined) return projectLabel(project.name)
  return `${firstItem.typeName} ×${firstItem.runs}`
}

function extraProductCount(project: GroupProject): number {
  return Math.max(project.items.length - 1, 0)
}

function statusLabel(status: GroupProjectStatus): string {
  return t(`groupIndustry.status.${status}`)
}
</script>

<template>
  <div class="space-y-6">
    <section data-testid="workshop-action-queue" class="bg-slate-900 rounded-xl border border-slate-800">
      <header class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-slate-800">
        <h2 class="font-display text-base font-bold uppercase tracking-[0.14em] text-slate-100">{{ t('industry.workshop.queue.title') }}</h2>
        <span
          v-if="queueCount !== null"
          data-testid="workshop-action-count"
          class="text-xs font-mono px-2 py-0.5 rounded border border-cyan-500/30 bg-cyan-500/10 text-cyan-400"
        >
          {{ t('industry.workshop.queue.count', { count: queueCount }) }}
        </span>
      </header>

      <ErrorBanner
        v-if="stockpileStore.error !== null"
        data-testid="workshop-stockpile-error"
        :message="stockpileStore.error"
        class="m-4"
      />

      <ErrorBanner
        v-if="actionsStore.error !== null"
        data-testid="workshop-error"
        :message="actionsStore.error"
        class="m-4"
      />

      <p
        v-else-if="isQueueEmpty"
        data-testid="workshop-empty-queue"
        class="px-4 py-8 text-center text-sm text-slate-500"
      >
        {{ t('industry.workshop.queue.empty') }}
      </p>

      <ul v-else class="divide-y divide-slate-800">
        <li
          v-for="action in actionsStore.actions"
          :key="action.projectId"
          data-testid="workshop-action-row"
          class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2 min-w-0">
              <span
                v-if="action.blocked"
                data-testid="workshop-action-blocked"
                class="shrink-0 text-xs px-2 py-0.5 rounded border border-amber-500/40 bg-amber-500/10 text-amber-400"
              >
                {{ t('industry.workshop.queue.blocked') }}
              </span>
              <p class="truncate font-medium text-slate-100" :title="projectLabel(action.projectName)">
                {{ projectLabel(action.projectName) }}{{ ' ' }}
              </p>
            </div>
            <p class="break-words text-sm text-slate-400">{{ actionPhrase(action) }}</p>
          </div>
          <button
            type="button"
            data-testid="workshop-action-button"
            class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium text-white transition-all hover:-translate-y-px"
            :class="action.blocked ? 'bg-amber-600 hover:bg-amber-500' : 'bg-cyan-600 hover:bg-cyan-500'"
            @click="openCockpit(action.projectId, action)"
          >
            {{ t(`industry.workshop.actions.${action.code}.button`) }}
          </button>
        </li>
        <li
          v-for="proposal in stockpileProposals"
          :key="proposal.id"
          data-testid="workshop-stockpile-row"
          class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <p
              class="truncate font-medium text-slate-100"
              :title="t('industry.workshop.queue.stockpileLow', { typeName: proposal.typeName, stock: n(proposal.stock), target: n(proposal.remainingTargetQuantity) })"
            >
              {{ t('industry.workshop.queue.stockpileLow', { typeName: proposal.typeName, stock: n(proposal.stock), target: n(proposal.remainingTargetQuantity) }) }}
            </p>
            <p class="break-words text-sm text-slate-400">
              {{ t('industry.workshop.queue.stockpileRuns', { runs: n(proposal.suggestedProjectRuns) }) }}
            </p>
          </div>
          <button
            type="button"
            data-testid="workshop-stockpile-button"
            class="shrink-0 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition-all hover:-translate-y-px hover:bg-amber-500"
            @click="createStockpileProject(proposal)"
          >
            {{ t('industry.workshop.queue.stockpileCreateProject') }}
          </button>
        </li>
      </ul>
    </section>

    <section class="bg-slate-900 rounded-xl border border-slate-800">
      <header class="px-4 py-3 border-b border-slate-800">
        <h2 class="font-display text-base font-bold uppercase tracking-[0.14em] text-slate-100">{{ t('industry.workshop.jobs.title') }}</h2>
      </header>

      <ErrorBanner
        v-if="slotsStore.error !== null"
        data-testid="workshop-jobs-error"
        :message="slotsStore.error"
        class="m-4"
      />

      <p
        v-else-if="hasLoadedJobs && upcomingJobs.length === 0"
        data-testid="workshop-empty-jobs"
        class="px-4 py-8 text-center text-sm text-slate-500"
      >
        {{ t('industry.workshop.jobs.empty') }}
      </p>

      <ul v-else class="divide-y divide-slate-800">
        <li
          v-for="job in upcomingJobs"
          :key="job.jobId"
          data-testid="workshop-upcoming-job"
          class="flex items-center justify-between gap-3 px-4 py-2.5"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-slate-100" :title="job.productTypeName ?? t('industry.workshop.jobs.unknownProduct')">
              {{ job.productTypeName ?? t('industry.workshop.jobs.unknownProduct') }}
            </p>
            <p class="truncate text-xs text-slate-500" :title="job.characterName">{{ job.characterName }}</p>
          </div>
          <span class="shrink-0 font-mono text-sm text-cyan-400" style="font-variant-numeric: tabular-nums;">
            {{ t('industry.workshop.jobs.endsIn', { duration: formatTimeLeft(job.timeLeftSeconds) }) }}
          </span>
        </li>
      </ul>
    </section>

    <section class="bg-slate-900 rounded-xl border border-slate-800">
      <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
        <h2 class="font-display text-base font-bold uppercase tracking-[0.14em] text-slate-100">{{ t('industry.workshop.projects.title') }}</h2>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="filter in TEAM_FILTERS"
            :key="filter"
            type="button"
            :data-testid="`workshop-filter-${filter}`"
            :aria-pressed="teamFilter === filter"
            class="rounded-md border px-3 py-1 text-xs font-medium transition-colors"
            :class="teamFilter === filter
              ? 'border-cyan-500/40 bg-cyan-500/10 text-cyan-400'
              : 'border-slate-700 text-slate-400 hover:text-slate-200'"
            @click="teamFilter = filter"
          >
            {{ t(`industry.workshop.projects.filters.${filter}`) }}
            <span class="ml-1 font-mono">{{ filterCount(filter) }}</span>
          </button>
        </div>
      </header>

      <ErrorBanner
        v-if="groupProjectStore.error"
        data-testid="workshop-projects-error"
        :message="groupProjectStore.error"
        class="m-4"
      />

      <LoadingSpinner v-else-if="groupProjectStore.loading && sortedProjects.length === 0" class="py-8" />

      <p
        v-else-if="sortedProjects.length === 0"
        data-testid="workshop-empty-projects"
        class="px-4 py-8 text-center text-sm text-slate-500"
      >
        {{ t('industry.workshop.projects.empty') }}
      </p>

      <p
        v-else-if="visibleProjects.length === 0"
        data-testid="workshop-empty-filter"
        class="px-4 py-8 text-center text-sm text-slate-500"
      >
        {{ t('industry.workshop.projects.emptyFilter') }}
      </p>

      <div v-else class="grid lg:grid-cols-[minmax(0,1fr)_20rem]">
        <ul role="listbox" :aria-label="t('industry.workshop.projects.title')" class="divide-y divide-slate-800 min-w-0">
          <li
            v-for="project in visibleProjects"
            :key="project.id"
            role="option"
            tabindex="0"
            data-testid="workshop-project-row"
            :aria-selected="selectedProject !== null && selectedProject.id === project.id"
            class="grid cursor-pointer gap-2 px-4 py-3 transition-colors hover:bg-slate-800/50 sm:grid-cols-[minmax(0,2fr)_minmax(0,1.2fr)_minmax(0,1fr)] sm:items-center sm:gap-4"
            :class="selectedProject !== null && selectedProject.id === project.id ? 'bg-cyan-500/5 border-l-2 border-cyan-400' : 'border-l-2 border-transparent'"
            @mouseenter="pickedProjectId = project.id"
            @focus="pickedProjectId = project.id"
            @click="openProjectRow(project.id, $event)"
            @auxclick="openProjectRowOnMiddleClick(project.id, $event)"
            @keydown.enter.prevent="openProjectRow(project.id, $event)"
            @keydown.space.prevent="pickedProjectId = project.id"
          >
            <div class="flex min-w-0 items-center gap-3">
              <div v-if="project.items.length > 0" class="h-8 w-8 shrink-0 overflow-hidden rounded border border-slate-700 bg-slate-800">
                <img
                  :src="getTypeIconUrl(project.items[0].typeId, 64)"
                  alt=""
                  class="h-full w-full object-cover"
                  @error="onImageError"
                />
              </div>
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-slate-100" :title="productLabel(project)">
                  {{ productLabel(project) }}
                  <span v-if="extraProductCount(project) > 0" class="ml-1 font-mono text-xs text-slate-400">+{{ extraProductCount(project) }}</span>
                </p>
                <p v-if="project.items.length > 0" class="truncate text-xs text-slate-500" :title="projectLabel(project.name)">
                  {{ projectLabel(project.name) }}
                </p>
              </div>
            </div>

            <div class="flex min-w-0 flex-wrap items-center gap-2">
              <span
                v-if="isSolo(project)"
                data-testid="workshop-team-solo"
                class="rounded border border-slate-600 bg-slate-800 px-2 py-0.5 text-xs text-slate-300"
              >
                {{ t('industry.workshop.team.solo') }}
              </span>
              <span
                v-else-if="isGroup(project)"
                data-testid="workshop-team-group"
                class="rounded border border-violet-500/20 bg-violet-500/15 px-2 py-0.5 text-xs text-violet-400"
              >
                {{ t('industry.workshop.team.group', { count: project.membersCount }) }}
              </span>
              <span v-if="project.myRole !== null" class="truncate text-xs text-slate-500">
                {{ t(`groupIndustry.members.roles.${project.myRole}`) }}
              </span>
            </div>

            <div class="flex min-w-0 items-center gap-2">
              <div class="flex gap-0.5" aria-hidden="true">
                <span
                  v-for="segment in LIFECYCLE_SEGMENTS"
                  :key="segment"
                  class="h-1.5 w-4 rounded-sm"
                  :class="segment <= LIFECYCLE_STEPS[project.status]
                    ? (project.status === 'completed' ? 'bg-emerald-500/70' : 'bg-cyan-500/70')
                    : 'bg-slate-700'"
                ></span>
              </div>
              <span class="truncate text-xs text-slate-400">{{ statusLabel(project.status) }}</span>
            </div>
          </li>
        </ul>

        <aside
          v-if="selectedProject !== null"
          data-testid="workshop-project-panel"
          class="min-w-0 space-y-3 border-t border-slate-800 p-4 lg:border-l lg:border-t-0"
        >
          <div>
            <p class="truncate font-display text-base font-bold text-slate-100" :title="projectLabel(selectedProject.name)">
              {{ projectLabel(selectedProject.name) }}
            </p>
            <span class="mt-1 inline-block rounded border px-2 py-0.5 text-xs" :class="STATUS_CLASSES[selectedProject.status]">
              {{ statusLabel(selectedProject.status) }}
            </span>
          </div>

          <p v-if="actionsStore.error !== null" class="text-sm text-slate-500">
            {{ t('industry.workshop.projects.actionUnavailable') }}
          </p>
          <div v-else-if="selectedAction !== null" class="space-y-2">
            <p class="break-words text-sm text-slate-300">{{ actionPhrase(selectedAction) }}</p>
            <button
              type="button"
              data-testid="workshop-action-button"
              class="w-full rounded-lg px-4 py-2 text-sm font-medium text-white transition-all hover:-translate-y-px"
              :class="selectedAction.blocked ? 'bg-amber-600 hover:bg-amber-500' : 'bg-cyan-600 hover:bg-cyan-500'"
              @click="openCockpit(selectedAction.projectId, selectedAction)"
            >
              {{ t(`industry.workshop.actions.${selectedAction.code}.button`) }}
            </button>
          </div>
          <p v-else class="text-sm text-slate-500">{{ t('industry.workshop.noPendingAction') }}</p>

          <button
            type="button"
            data-testid="workshop-open-cockpit"
            class="w-full rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium text-slate-200 transition-colors hover:border-cyan-500/40 hover:text-cyan-400"
            @click="openCockpit(selectedProject.id)"
          >
            {{ t('industry.workshop.projects.openCockpit') }}
          </button>
        </aside>
      </div>
    </section>
  </div>
</template>
