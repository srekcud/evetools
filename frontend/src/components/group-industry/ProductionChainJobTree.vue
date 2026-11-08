<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import IskAmount from '@/components/common/IskAmount.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { ChainJob, ChainNode, ChainStage } from '@/stores/group-industry/types'
import { showsBlueprintEfficiency } from './blueprintEfficiency'
import { chainStatusChipClass, chainStatusDotClass } from './chainStatus'
import UnknownContributionValue from './UnknownContributionValue.vue'

type TreeRow = {
  node: ChainNode
  depth: number
  hasChildren: boolean
  endDate: string | null
}

type CharacterEntry = {
  key: string
  name: string | null
  quantity: number | null
}

const STAGE_RANK: Record<ChainStage, number> = {
  product: 0,
  component: 1,
  blueprint: 2,
  material: 3,
  sale: 4,
}

const INDENT_PX = 20
const PLANNED_OUTSIDE_FAVORITE_SYSTEM = 'best_outside_favorite_system'

const props = defineProps<{
  nodes: ChainNode[]
  selectedId: string | null
}>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()
const { formatDateTime, formatNumber } = useFormatters()

const COLUMN_KEYS = ['element', 'activity', 'runs', 'meTe', 'character', 'status', 'realCost', 'end', 'inOut', 'structure'] as const

const foldedIds = ref<ReadonlySet<string>>(new Set())

const treeNodes = computed(() => props.nodes.filter(node => node.stage !== 'sale'))
const nodeById = computed(() => new Map(treeNodes.value.map(node => [node.id, node])))

function byStage(nodes: ChainNode[]): ChainNode[] {
  return [...nodes].sort((left, right) => STAGE_RANK[left.stage] - STAGE_RANK[right.stage])
}

const childrenByParentId = computed(() => {
  const children = new Map<string, ChainNode[]>()
  for (const node of treeNodes.value) {
    if (node.parentId === null || !nodeById.value.has(node.parentId)) continue
    children.set(node.parentId, [...(children.get(node.parentId) ?? []), node])
  }
  return children
})

const rootNodes = computed(() => byStage(
  treeNodes.value.filter(node => node.parentId === null || !nodeById.value.has(node.parentId)),
))

const rows = computed<TreeRow[]>(() => {
  const shown: TreeRow[] = []
  const visit = (node: ChainNode, depth: number): void => {
    const children = byStage(childrenByParentId.value.get(node.id) ?? [])
    shown.push({ node, depth, hasChildren: children.length > 0, endDate: latestEndDate(node.jobs) })
    if (foldedIds.value.has(node.id)) return
    for (const child of children) visit(child, depth + 1)
  }
  for (const root of rootNodes.value) visit(root, 0)
  return shown
})

function toggle(nodeId: string): void {
  const next = new Set(foldedIds.value)
  if (!next.delete(nodeId)) next.add(nodeId)
  foldedIds.value = next
}

function unfoldAncestorsOf(nodeId: string): void {
  const next = new Set(foldedIds.value)
  let parentId = nodeById.value.get(nodeId)?.parentId ?? null
  while (parentId !== null) {
    next.delete(parentId)
    parentId = nodeById.value.get(parentId)?.parentId ?? null
  }
  foldedIds.value = next
}

const rowElements = new Map<string, HTMLElement>()

function trackRow(nodeId: string, element: unknown): void {
  if (element instanceof HTMLElement) rowElements.set(nodeId, element)
  else rowElements.delete(nodeId)
}

function scrollToRow(nodeId: string): void {
  rowElements.get(nodeId)?.scrollIntoView({ block: 'center' })
}

watch(
  () => props.selectedId,
  (selectedId) => {
    if (selectedId === null) return
    unfoldAncestorsOf(selectedId)
    void nextTick(() => scrollToRow(selectedId))
  },
  { immediate: true },
)

function characterEntries(node: ChainNode): CharacterEntry[] {
  if (node.suppliers.length > 0) {
    return node.suppliers.map(supplier => ({
      key: `${supplier.participantId}-${supplier.type}`,
      name: supplier.characterName,
      quantity: node.suppliers.length > 1 ? supplier.quantity : null,
    }))
  }
  const installers = new Set(node.jobs.flatMap(job => (job.characterName === null ? [] : [job.characterName])))
  return [...installers].map(name => ({ key: name, name, quantity: null }))
}

function latestEndDate(jobs: ChainJob[]): string | null {
  const ends = jobs.flatMap(job => (job.endDate === null ? [] : [job.endDate]))
  if (ends.length === 0) return null
  return ends.reduce((latest, end) => (new Date(end) > new Date(latest) ? end : latest))
}

function plansStructure(node: ChainNode): boolean {
  return node.activity === 'manufacturing' || node.activity === 'reaction'
}
</script>

<template>
  <div data-testid="chain-tree" class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
          <th
            v-for="column in COLUMN_KEYS"
            :key="column"
            class="py-2.5 px-3 whitespace-nowrap"
            :class="column === 'runs' || column === 'realCost' ? 'text-right' : 'text-left'"
          >{{ t(`groupIndustry.chain.columns.${column}`) }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800/30">
        <tr
          v-for="row in rows"
          :key="row.node.id"
          :ref="element => trackRow(row.node.id, element)"
          :data-testid="`chain-row-${row.node.id}`"
          :aria-selected="row.node.id === selectedId"
          class="hover:bg-slate-800/30 align-top"
          :class="row.node.id === selectedId ? 'bg-cyan-500/10' : ''"
        >
          <td class="py-2 px-3">
            <div class="flex items-center gap-1.5 min-w-0" :style="{ paddingLeft: `${row.depth * INDENT_PX}px` }">
              <button
                v-if="row.hasChildren"
                type="button"
                :data-testid="`chain-toggle-${row.node.id}`"
                :aria-expanded="!foldedIds.has(row.node.id)"
                :aria-label="t(foldedIds.has(row.node.id) ? 'groupIndustry.chain.toggle.unfold' : 'groupIndustry.chain.toggle.fold', { name: row.node.typeName })"
                class="w-5 h-5 shrink-0 text-slate-400 hover:text-cyan-300"
                @click="toggle(row.node.id)"
              >{{ foldedIds.has(row.node.id) ? '▸' : '▾' }}</button>
              <span v-else class="w-5 shrink-0"></span>
              <img
                v-if="row.node.typeId !== null"
                :src="getTypeIconUrl(row.node.typeId, 32)"
                alt=""
                class="w-6 h-6 rounded border border-slate-700 shrink-0"
                @error="onImageError"
              />
              <span v-else data-testid="chain-icon-placeholder" class="w-6 h-6 shrink-0" aria-hidden="true"></span>
              <span class="block truncate min-w-0 max-w-64 font-medium text-slate-200" :title="row.node.typeName ?? undefined">{{ row.node.typeName }}</span>
              <span
                v-if="row.node.stage === 'material' && row.node.requiredQuantity !== null && row.node.fulfilledQuantity !== null"
                class="shrink-0 text-xs font-mono text-slate-500"
              >{{ formatNumber(row.node.fulfilledQuantity, 0) }} / {{ formatNumber(row.node.requiredQuantity, 0) }}</span>
            </div>
          </td>

          <td class="py-2 px-3 text-slate-300">
            <template v-if="row.node.activity !== null">{{ t(`groupIndustry.chain.activity.${row.node.activity}`) }}</template>
          </td>

          <td class="py-2 px-3 text-right font-mono text-slate-300">
            <template v-if="row.node.runs !== null">{{ formatNumber(row.node.runs, 0) }}</template>
          </td>

          <td class="py-2 px-3 text-slate-400 whitespace-nowrap">
            <template v-if="showsBlueprintEfficiency(row.node.activity) && row.node.meLevel !== null && row.node.teLevel !== null">{{ t('groupIndustry.chain.meTeValue', { me: row.node.meLevel, te: row.node.teLevel }) }}</template>
          </td>

          <td class="py-2 px-3 text-slate-300">
            <span v-for="entry in characterEntries(row.node)" :key="entry.key" class="block truncate max-w-48" :title="entry.name ?? undefined">
              <template v-if="entry.name !== null">{{ entry.name }}</template>
              <span v-else class="text-violet-300">{{ t('groupIndustry.chain.characterUnknown') }}</span>
              <span v-if="entry.quantity !== null" class="ml-1 font-mono text-xs text-slate-500">×{{ formatNumber(entry.quantity, 0) }}</span>
            </span>
          </td>

          <td class="py-2 px-3">
            <span
              class="inline-flex items-center gap-1.5 px-1.5 py-0.5 rounded-sm border text-xs whitespace-nowrap"
              :class="chainStatusChipClass(row.node.status)"
            >
              <i class="inline-block w-1.5 h-1.5 rounded-full" :class="chainStatusDotClass(row.node.status)"></i>{{ t(`groupIndustry.chain.status.${row.node.status}`) }}
            </span>
          </td>

          <td class="py-2 px-3 text-right font-mono text-slate-300">
            <IskAmount v-if="row.node.costState === 'known'" :value="row.node.realCost" short />
            <template v-else-if="row.node.costState === 'unknown'">
              <UnknownContributionValue data-testid="chain-cost-unknown" />
              <span class="block mt-1 text-xs font-sans text-violet-300 whitespace-normal max-w-56 ml-auto">{{ t('groupIndustry.chain.unknownCostCause') }}</span>
            </template>
            <span v-else class="text-slate-500">—</span>
          </td>

          <td class="py-2 px-3 text-slate-400 whitespace-nowrap">
            <template v-if="row.endDate !== null">{{ formatDateTime(row.endDate) }}</template>
          </td>

          <td class="py-2 px-3">
            <template v-if="row.node.activity !== null">
              <UnknownContributionValue data-testid="chain-io-unknown" />
            </template>
          </td>

          <td class="py-2 px-3 text-slate-300">
            <div class="w-48">
              <span v-for="structure in row.node.structures" :key="structure" class="block truncate" :title="structure">{{ structure }}</span>
              <template v-if="row.node.plannedStructure !== null">
                <span data-testid="chain-planned-structure" class="block text-xs text-slate-400">
                  <span class="text-cyan-400" aria-hidden="true">★</span>
                  {{ t('groupIndustry.chain.structure.planned') }}
                  <span v-if="row.node.plannedStructure.name !== null" class="block truncate text-slate-300" :title="row.node.plannedStructure.name">{{ row.node.plannedStructure.name }}</span>
                  <span v-else class="block text-violet-300">{{ t('groupIndustry.chain.structure.deleted') }}</span>
                </span>
                <span
                  v-if="row.node.plannedStructure.status === PLANNED_OUTSIDE_FAVORITE_SYSTEM"
                  data-testid="chain-structure-warning"
                  class="block mt-0.5 text-xs text-amber-400 whitespace-normal"
                >{{ t('groupIndustry.chain.structure.outsideFavoriteSystem') }}</span>
              </template>
              <template v-else-if="row.node.structures.length === 0 && plansStructure(row.node)">
                <UnknownContributionValue />
                <span class="block mt-1 text-xs text-violet-300 whitespace-normal">{{ t('groupIndustry.chain.structure.unknown') }}</span>
              </template>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
