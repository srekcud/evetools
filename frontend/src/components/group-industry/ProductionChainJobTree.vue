<script setup lang="ts">
import { computed, nextTick, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import IskAmount from '@/components/common/IskAmount.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { ChainJob, ChainNode, ChainStage } from '@/stores/group-industry/types'
import { showsBlueprintEfficiency } from './blueprintEfficiency'
import { chainStatusChipClass, chainStatusDotClass } from './chainStatus'
import UnknownContributionValue from './UnknownContributionValue.vue'

type LevelHeader = {
  kind: 'header'
  key: string
  levelKey: string
  level: number | null
  jobCount: number
}

type JobRow = {
  kind: 'row'
  key: string
  node: ChainNode
  endDate: string | null
}

type CharacterEntry = {
  key: string
  name: string | null
  quantity: number | null
}

const STAGE_RANK: Partial<Record<ChainStage, number>> = {
  blueprint: 0,
  component: 1,
  product: 2,
}

const UNKNOWN_LEVEL_KEY = 'unknown'
const PLANNED_OUTSIDE_FAVORITE_SYSTEM = 'best_outside_favorite_system'

const props = defineProps<{
  nodes: ChainNode[]
  selectedId: string | null
}>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()
const { formatDateTime, formatNumber } = useFormatters()

const COLUMN_KEYS = ['element', 'activity', 'runs', 'meTe', 'character', 'status', 'realCost', 'end', 'inOut', 'structure'] as const

const rowElements = new Map<string, Element>()

const jobNodes = computed(() => props.nodes.filter(node => STAGE_RANK[node.stage] !== undefined))

function levelKeyOf(level: number | null): string {
  return level === null ? UNKNOWN_LEVEL_KEY : String(level)
}

function levelRank(node: ChainNode): number {
  return node.level ?? Number.POSITIVE_INFINITY
}

function stageRank(node: ChainNode): number {
  return STAGE_RANK[node.stage] ?? Number.POSITIVE_INFINITY
}

const jobNodeById = computed(() => new Map(jobNodes.value.map(node => [node.id, node])))

function sortName(node: ChainNode): string {
  const target = node.stage === 'blueprint' && node.parentId !== null ? jobNodeById.value.get(node.parentId) : undefined
  return (target ?? node).typeName ?? ''
}

function compareJobs(left: ChainNode, right: ChainNode): number {
  if (levelRank(left) !== levelRank(right)) return levelRank(left) < levelRank(right) ? -1 : 1
  return sortName(left).localeCompare(sortName(right)) || stageRank(left) - stageRank(right)
}

const listItems = computed<(LevelHeader | JobRow)[]>(() => {
  const items: (LevelHeader | JobRow)[] = []
  let currentHeader: LevelHeader | null = null
  for (const node of [...jobNodes.value].sort(compareJobs)) {
    if (currentHeader === null || currentHeader.level !== node.level) {
      currentHeader = { kind: 'header', key: `level-${levelKeyOf(node.level)}`, levelKey: levelKeyOf(node.level), level: node.level, jobCount: 0 }
      items.push(currentHeader)
    }
    currentHeader.jobCount += 1
    items.push({ kind: 'row', key: `row-${node.id}`, node, endDate: latestEndDate(node.jobs) })
  }
  return items
})

function trackRow(nodeId: string, element: unknown): void {
  if (element instanceof Element) rowElements.set(nodeId, element)
  else rowElements.delete(nodeId)
}

function scrollToSelected(): void {
  if (props.selectedId === null) return
  rowElements.get(props.selectedId)?.scrollIntoView({ block: 'center' })
}

onMounted(scrollToSelected)

watch(
  () => props.selectedId,
  () => { void nextTick(scrollToSelected) },
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
        <template v-for="item in listItems" :key="item.key">
          <tr
            v-if="item.kind === 'header'"
            :data-testid="`chain-level-${item.levelKey}`"
            class="bg-slate-800/40"
          >
            <th :colspan="COLUMN_KEYS.length" scope="colgroup" class="py-1.5 px-3 text-left text-xs uppercase tracking-wider text-cyan-300">
              <template v-if="item.level !== null">{{ t('groupIndustry.chain.level.title', { level: item.level }) }}</template>
              <template v-else>
                {{ t('groupIndustry.chain.level.unknown') }}
                <span class="ml-2 normal-case tracking-normal text-violet-300">{{ t('groupIndustry.chain.level.unknownCause') }}</span>
              </template>
              <span class="ml-2 font-mono normal-case tracking-normal text-slate-500">{{ t('groupIndustry.chain.level.count', item.jobCount) }}</span>
            </th>
          </tr>
          <tr
            v-else
            :ref="element => trackRow(item.node.id, element)"
            :data-testid="`chain-row-${item.node.id}`"
            :aria-selected="item.node.id === selectedId"
            class="hover:bg-slate-800/30 align-top"
            :class="item.node.id === selectedId ? 'bg-cyan-500/10' : ''"
          >
          <td class="py-2 px-3">
            <div class="flex items-center gap-1.5 min-w-0">
              <img
                v-if="item.node.typeId !== null"
                :src="getTypeIconUrl(item.node.typeId, 32)"
                alt=""
                class="w-6 h-6 rounded border border-slate-700 shrink-0"
                @error="onImageError"
              />
              <span v-else data-testid="chain-icon-placeholder" class="w-6 h-6 shrink-0" aria-hidden="true"></span>
              <span class="block truncate min-w-0 max-w-64 font-medium text-slate-200" :title="item.node.typeName ?? undefined">{{ item.node.typeName }}</span>
            </div>
          </td>

          <td class="py-2 px-3 text-slate-300">
            <template v-if="item.node.activity !== null">{{ t(`groupIndustry.chain.activity.${item.node.activity}`) }}</template>
          </td>

          <td class="py-2 px-3 text-right font-mono text-slate-300">
            <template v-if="item.node.runs !== null">{{ formatNumber(item.node.runs, 0) }}</template>
          </td>

          <td class="py-2 px-3 text-slate-400 whitespace-nowrap">
            <template v-if="showsBlueprintEfficiency(item.node.activity) && item.node.meLevel !== null && item.node.teLevel !== null">{{ t('groupIndustry.chain.meTeValue', { me: item.node.meLevel, te: item.node.teLevel }) }}</template>
          </td>

          <td class="py-2 px-3 text-slate-300">
            <span v-for="entry in characterEntries(item.node)" :key="entry.key" class="block truncate max-w-48" :title="entry.name ?? undefined">
              <template v-if="entry.name !== null">{{ entry.name }}</template>
              <span v-else class="text-violet-300">{{ t('groupIndustry.chain.characterUnknown') }}</span>
              <span v-if="entry.quantity !== null" class="ml-1 font-mono text-xs text-slate-500">×{{ formatNumber(entry.quantity, 0) }}</span>
            </span>
          </td>

          <td class="py-2 px-3">
            <span
              class="inline-flex items-center gap-1.5 px-1.5 py-0.5 rounded-sm border text-xs whitespace-nowrap"
              :class="chainStatusChipClass(item.node.status)"
            >
              <i class="inline-block w-1.5 h-1.5 rounded-full" :class="chainStatusDotClass(item.node.status)"></i>{{ t(`groupIndustry.chain.status.${item.node.status}`) }}
            </span>
          </td>

          <td class="py-2 px-3 text-right font-mono text-slate-300">
            <IskAmount v-if="item.node.costState === 'known'" :value="item.node.realCost" short />
            <template v-else-if="item.node.costState === 'unknown'">
              <UnknownContributionValue data-testid="chain-cost-unknown" />
              <span class="block mt-1 text-xs font-sans text-violet-300 whitespace-normal max-w-56 ml-auto">{{ t('groupIndustry.chain.unknownCostCause') }}</span>
            </template>
            <span v-else class="text-slate-500">—</span>
          </td>

          <td class="py-2 px-3 text-slate-400 whitespace-nowrap">
            <template v-if="item.endDate !== null">{{ formatDateTime(item.endDate) }}</template>
          </td>

          <td class="py-2 px-3">
            <template v-if="item.node.activity !== null">
              <UnknownContributionValue data-testid="chain-io-unknown" />
              <span class="block mt-1 text-xs text-violet-300 whitespace-normal max-w-48">{{ t('groupIndustry.chain.ioUnknownCause') }}</span>
            </template>
          </td>

          <td class="py-2 px-3 text-slate-300">
            <span v-for="structure in item.node.structures" :key="structure" class="block truncate max-w-48" :title="structure">{{ structure }}</span>
            <template v-if="item.node.plannedStructure !== null">
              <span data-testid="chain-planned-structure" class="block text-xs text-slate-400">
                <span class="text-cyan-400" aria-hidden="true">★</span>
                {{ t('groupIndustry.chain.structure.planned') }}
                <span v-if="item.node.plannedStructure.name !== null" class="block truncate max-w-48 text-slate-300" :title="item.node.plannedStructure.name">{{ item.node.plannedStructure.name }}</span>
                <span v-else class="block text-violet-300">{{ t('groupIndustry.chain.structure.deleted') }}</span>
              </span>
              <span
                v-if="item.node.plannedStructure.status === PLANNED_OUTSIDE_FAVORITE_SYSTEM"
                data-testid="chain-structure-warning"
                class="block mt-0.5 text-xs text-amber-400"
              >{{ t('groupIndustry.chain.structure.outsideFavoriteSystem') }}</span>
            </template>
            <template v-else-if="item.node.structures.length === 0 && plansStructure(item.node)">
              <UnknownContributionValue />
              <span class="block mt-1 text-xs text-violet-300 whitespace-normal max-w-48">{{ t('groupIndustry.chain.structure.unknown') }}</span>
            </template>
          </td>
                  </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
