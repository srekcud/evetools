<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BomItem, ChainStatus } from '@/stores/group-industry/types'
import { useGroupChainStore } from '@/stores/group-industry/chain'
import { useGroupT2BlueprintsToProvideStore } from '@/stores/group-industry/t2BlueprintsToProvide'
import { useSlotsStore } from '@/stores/industry/slots'
import { useSyncStore } from '@/stores/sync'
import { useCoalescedGroupProjectEvents } from '@/composables/useCoalescedGroupProjectEvents'
import { useRefetchOnReconnect } from '@/composables/useRefetchOnReconnect'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import ChainStatusFilter from './ChainStatusFilter.vue'
import { CHAIN_STATUSES, chainStatusDotClass } from './chainStatus'
import { recallChainStatusFilter, rememberChainStatusFilter } from './chainStatusFilter'
import ProductionChainFlow from './ProductionChainFlow.vue'
import ProductionChainJobTree from './ProductionChainJobTree.vue'
import JobLinkModal from './JobLinkModal.vue'
import ProjectReactionSteps from './ProjectReactionSteps.vue'
import { useProjectJobActions } from './useProjectJobActions'

type ChainView = 'flow' | 'tree'

const VIEWS: readonly ChainView[] = ['flow', 'tree']

const props = defineProps<{
  projectId: string
  canMark?: boolean
  markableNodeIds?: readonly string[]
  canMatchJobs?: boolean
  excludesInvention?: boolean
}>()

const emit = defineEmits<{
  marked: []
  contribute: [bomItem: BomItem]
  'open-sourcing': []
}>()

const { t } = useI18n()
const { formatNumber, formatTimeSince } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const syncStore = useSyncStore()
const chainStore = useGroupChainStore()
const toProvideStore = useGroupT2BlueprintsToProvideStore()
const slotsStore = useSlotsStore()

const jobActions = useProjectJobActions()
const {
  isMatchingJobs,
  matchJobsError,
  failedCharacters,
  failedLines,
  linkScreenLine,
  availableJobs,
  isLoadingAvailableJobs,
  linkingEsiJobId,
  jobLinkError,
  characterFailureReason,
} = jobActions

const jobsSyncLabel = computed(() => {
  const jobsSyncedAt = slotsStore.data?.jobsSyncedAt ?? null
  return jobsSyncedAt === null
    ? t('groupIndustry.chain.jobsSync.unknown')
    : t('groupIndustry.chain.jobsSync.syncedAgo', { since: formatTimeSince(jobsSyncedAt) })
})

async function matchJobs(): Promise<void> {
  const matched = await jobActions.matchJobs(props.projectId)
  if (!matched) return
  try {
    await Promise.all([chainStore.fetchChain(props.projectId), slotsStore.fetchSlots()])
    emit('marked')
  } catch (e) {
    matchJobsError.value = e instanceof Error ? e.message : t('groupIndustry.jobs.matchError')
  }
}

const view = ref<ChainView>('flow')
const selectedNodeId = ref<string | null>(null)
const statusFilter = ref<ChainStatus[]>(recallChainStatusFilter())
const stepNodes = computed(() => chainStore.nodes.filter(node => node.stage !== 'material'))

watch(statusFilter, statuses => rememberChainStatusFilter(statuses))

function openInTree(nodeId: string): void {
  selectedNodeId.value = nodeId
  view.value = 'tree'
}

function showView(next: ChainView): void {
  view.value = next
}

watch(() => props.projectId, projectId => {
  chainStore.fetchChain(projectId)
  slotsStore.fetchSlots()
  toProvideStore.fetchToProvide(projectId)
}, { immediate: true })

watch(() => props.excludesInvention, () => toProvideStore.fetchToProvide(props.projectId))

useCoalescedGroupProjectEvents(
  () => props.projectId,
  () => {
    chainStore.fetchChain(props.projectId)
    toProvideStore.fetchToProvide(props.projectId)
  },
)

useRefetchOnReconnect(() => Promise.all([chainStore.fetchChain(props.projectId), toProvideStore.fetchToProvide(props.projectId)]))

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    chainStore.fetchChain(props.projectId)
    slotsStore.fetchSlots()
    toProvideStore.fetchToProvide(props.projectId)
  },
)
</script>

<template>
  <section class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.chain.title') }}
      </h2>
      <div class="flex flex-wrap items-center gap-3">
        <span data-testid="chain-jobs-synced-at" class="text-xs text-slate-500">{{ jobsSyncLabel }}</span>
        <button
          v-if="canMatchJobs === true"
          type="button"
          data-testid="chain-match-jobs"
          class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-xs font-medium transition-colors disabled:opacity-50"
          :disabled="isMatchingJobs"
          @click="matchJobs"
        >{{ t('groupIndustry.jobs.matchJobs') }}</button>
        <div class="inline-flex rounded border border-slate-700 overflow-hidden" role="group">
          <button
            v-for="candidate in VIEWS"
            :key="candidate"
            type="button"
            :data-testid="`chain-view-${candidate}`"
            :aria-pressed="view === candidate"
            class="px-3 py-1.5 text-xs uppercase tracking-wider transition-colors"
            :class="view === candidate ? 'bg-cyan-500/15 text-cyan-300' : 'text-slate-400 hover:bg-slate-800'"
            @click="showView(candidate)"
          >{{ t(`groupIndustry.chain.views.${candidate}`) }}</button>
        </div>
      </div>
    </header>

    <p
      v-if="matchJobsError !== null"
      data-testid="chain-match-jobs-error"
      role="alert"
      class="mx-4 mt-3 text-sm text-red-400"
    >{{ matchJobsError }}</p>
    <div v-if="failedCharacters.length > 0" class="mx-4 mt-3 rounded-lg border border-amber-500/20 bg-amber-500/10 px-4 py-3">
      <p class="text-sm text-amber-400">{{ t('groupIndustry.jobs.matchFailedCharacters') }}</p>
      <ul class="mt-1 space-y-0.5 text-xs text-amber-400/80">
        <li v-for="failure in failedCharacters" :key="failure.characterName">
          <span class="font-medium">{{ failure.characterName }}</span>: {{ characterFailureReason(failure) }}
        </li>
      </ul>
    </div>
    <div v-if="failedLines.length > 0" class="mx-4 mt-3 rounded-lg border border-amber-500/20 bg-amber-500/10 px-4 py-3">
      <p class="text-sm text-amber-400">{{ t('groupIndustry.jobs.matchFailedLines') }}</p>
      <ul class="mt-1 space-y-0.5 text-xs text-amber-400/80">
        <li v-for="(failure, index) in failedLines" :key="`${failure.typeId}-${failure.reason}-${index}`" data-testid="match-failed-line">
          <span class="font-medium">{{ failure.typeName }}</span>: {{ t(`groupIndustry.jobs.matchLineReasons.${failure.reason}`) }}
        </li>
      </ul>
    </div>
    <p
      v-if="jobLinkError !== null && linkScreenLine === null"
      data-testid="job-link-error"
      role="alert"
      class="mx-4 mt-3 text-sm text-red-400"
    >{{ jobLinkError }}</p>
    <JobLinkModal
      v-if="canMatchJobs === true"
      :show="linkScreenLine !== null"
      :line-name="linkScreenLine?.typeName ?? ''"
      :jobs="availableJobs"
      :loading="isLoadingAvailableJobs"
      :error="jobLinkError"
      :linking-esi-job-id="linkingEsiJobId"
      @close="jobActions.closeLinkScreen"
      @link="jobActions.linkJob(projectId, $event)"
    />

    <p
      v-if="chainStore.loading && chainStore.nodes.length === 0"
      data-testid="chain-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.chain.loading') }}</p>

    <div
      v-else-if="chainStore.error !== null"
      data-testid="chain-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.chain.loadError') }}</p>
      <p class="mt-1 break-words">{{ chainStore.error }}</p>
    </div>

    <template v-else-if="chainStore.nodes.length > 0">
      <ChainStatusFilter v-model="statusFilter" :nodes="stepNodes" />
      <ProductionChainFlow
        v-if="view === 'flow'"
        :nodes="chainStore.nodes"
        :status-filter="statusFilter"
        @select="openInTree"
        @open-sourcing="$emit('open-sourcing')"
      />
      <ProductionChainJobTree
        v-else
        :nodes="chainStore.nodes"
        :status-filter="statusFilter"
        :selected-id="selectedNodeId"
        :project-id="projectId"
        :can-mark="canMark === true"
        :markable-node-ids="markableNodeIds"
        :can-link-jobs="canMatchJobs === true"
        @marked="$emit('marked')"
        @contribute="$emit('contribute', $event)"
        @link-job="jobActions.openLinkScreen(projectId, $event)"
        @unlink-job="jobActions.unlinkJob(projectId, $event)"
      />

      <footer class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 border-t border-slate-800 text-xs text-slate-400">
        <span class="uppercase tracking-wider text-slate-500">{{ t('groupIndustry.chain.legendTitle') }}</span>
        <span v-for="status in CHAIN_STATUSES" :key="status" class="inline-flex items-center gap-1.5">
          <i class="inline-block w-2 h-2 rounded-full" :class="chainStatusDotClass(status)"></i>
          {{ t(`groupIndustry.chain.status.${status}`) }}
        </span>
      </footer>

      <section
        v-if="toProvideStore.error !== null || toProvideStore.toProvide?.excludesInvention === true"
        data-testid="chain-t2-to-provide"
        class="border-t border-slate-800"
      >
        <header class="px-4 py-3">
          <h3 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
            {{ t('groupIndustry.chain.t2ToProvide.title') }}
          </h3>
          <p class="text-xs text-slate-500 mt-1">{{ t('groupIndustry.chain.t2ToProvide.description') }}</p>
        </header>

        <div
          v-if="toProvideStore.error !== null"
          data-testid="chain-t2-to-provide-error"
          role="alert"
          class="m-4 mt-0 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
        >
          <p class="font-semibold">{{ t('groupIndustry.chain.t2ToProvide.loadError') }}</p>
          <p class="mt-1 break-words">{{ toProvideStore.error }}</p>
        </div>

        <p
          v-else-if="toProvideStore.toProvide?.lines.length === 0"
          data-testid="chain-t2-to-provide-empty"
          class="px-4 pb-4 text-sm text-slate-500"
        >{{ t('groupIndustry.chain.t2ToProvide.empty') }}</p>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                <th class="px-4 py-2 font-medium">{{ t('groupIndustry.chain.t2ToProvide.columns.type') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.chain.t2ToProvide.columns.runs') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr
                v-for="line in toProvideStore.toProvide?.lines ?? []"
                :key="line.productTypeId"
                :data-testid="`chain-t2-to-provide-row-${line.productTypeId}`"
              >
                <td class="px-4 py-2 max-w-[24rem]">
                  <span class="flex items-center gap-2 min-w-0">
                    <img
                      :src="getTypeIconUrl(line.productTypeId, 32)"
                      alt=""
                      class="w-5 h-5 rounded-sm shrink-0"
                      @error="onImageError"
                    />
                    <span
                      :data-testid="`chain-t2-to-provide-name-${line.productTypeId}`"
                      class="font-medium text-slate-100 truncate"
                      :title="line.typeName"
                    >{{ line.typeName }}</span>
                  </span>
                </td>
                <td
                  :data-testid="`chain-t2-to-provide-runs-${line.productTypeId}`"
                  class="px-4 py-2 text-right tabular-nums text-cyan-300"
                >{{ formatNumber(line.runs, 0) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <ProjectReactionSteps :project-id="projectId" />
    </template>
  </section>
</template>
