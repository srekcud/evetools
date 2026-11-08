<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupChainStore } from '@/stores/group-industry/chain'
import { useSyncStore } from '@/stores/sync'
import { CHAIN_STATUSES, chainStatusDotClass } from './chainStatus'
import ProductionChainFlow from './ProductionChainFlow.vue'
import ProductionChainJobTree from './ProductionChainJobTree.vue'
import ProjectReactionSteps from './ProjectReactionSteps.vue'

type ChainView = 'flow' | 'tree'

const VIEWS: readonly ChainView[] = ['flow', 'tree']

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const syncStore = useSyncStore()
const chainStore = useGroupChainStore()

const view = ref<ChainView>('flow')
const selectedNodeId = ref<string | null>(null)

function openInTree(nodeId: string): void {
  selectedNodeId.value = nodeId
  view.value = 'tree'
}

function showView(next: ChainView): void {
  view.value = next
}

watch(() => props.projectId, projectId => chainStore.fetchChain(projectId), { immediate: true })

watch(
  () => syncStore.getGroupProjectEvent(props.projectId),
  (event) => {
    if (!event) return
    chainStore.fetchChain(props.projectId)
  },
)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    chainStore.fetchChain(props.projectId)
  },
)
</script>

<template>
  <section class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.chain.title') }}
      </h2>
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
    </header>

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
      <ProductionChainFlow
        v-if="view === 'flow'"
        :nodes="chainStore.nodes"
        @select="openInTree"
      />
      <ProductionChainJobTree
        v-else
        :nodes="chainStore.nodes"
        :selected-id="selectedNodeId"
      />
      <p v-if="view !== 'flow'" data-testid="chain-io-unknown-cause" class="px-4 pt-3 text-xs text-violet-300">
        {{ t('groupIndustry.chain.ioUnknownCause') }}
      </p>

      <footer class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 border-t border-slate-800 text-xs text-slate-400">
        <span class="uppercase tracking-wider text-slate-500">{{ t('groupIndustry.chain.legendTitle') }}</span>
        <span v-for="status in CHAIN_STATUSES" :key="status" class="inline-flex items-center gap-1.5">
          <i class="inline-block w-2 h-2 rounded-full" :class="chainStatusDotClass(status)"></i>
          {{ t(`groupIndustry.chain.status.${status}`) }}
        </span>
      </footer>

      <ProjectReactionSteps :project-id="projectId" />
    </template>
  </section>
</template>
