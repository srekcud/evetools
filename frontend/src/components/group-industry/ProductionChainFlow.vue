<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import IskAmount from '@/components/common/IskAmount.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { ChainNode, ChainStage } from '@/stores/group-industry/types'
import { chainStatusChipClass, chainStatusEdgeClass } from './chainStatus'
import UnknownContributionValue from './UnknownContributionValue.vue'

type FlowColumnKey = 'inventionMaterials' | 'components' | 'product' | 'sale'

const FLOW_COLUMNS: readonly { key: FlowColumnKey; stages: readonly ChainStage[] }[] = [
  { key: 'inventionMaterials', stages: ['blueprint', 'material'] },
  { key: 'components', stages: ['component'] },
  { key: 'product', stages: ['product'] },
  { key: 'sale', stages: ['sale'] },
]

const props = defineProps<{
  nodes: ChainNode[]
}>()

const emit = defineEmits<{
  select: [nodeId: string]
}>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()
const { formatNumber } = useFormatters()

const columns = computed(() => FLOW_COLUMNS.map(column => ({
  key: column.key,
  nodes: props.nodes.filter(node => column.stages.includes(node.stage)),
})))

function progressPercent(node: ChainNode): number | null {
  if (node.requiredQuantity === null || node.fulfilledQuantity === null || node.requiredQuantity === 0) return null
  return Math.min(100, (node.fulfilledQuantity / node.requiredQuantity) * 100)
}
</script>

<template>
  <div data-testid="chain-flow" class="p-4 overflow-x-auto">
    <div class="flex items-stretch gap-2 min-w-max">
      <template v-for="(column, index) in columns" :key="column.key">
        <span v-if="index > 0" class="self-center text-slate-600 text-xl" aria-hidden="true">›</span>
        <div :data-testid="`chain-column-${column.key}`" class="w-64 flex flex-col gap-2">
          <span class="text-xs uppercase tracking-widest text-slate-500 px-1">
            {{ t(`groupIndustry.chain.flow.${column.key}`) }}
          </span>
          <button
            v-for="node in column.nodes"
            :key="node.id"
            type="button"
            :data-testid="`chain-node-${node.id}`"
            :title="t('groupIndustry.chain.flow.openInTree')"
            class="text-left w-full rounded border border-slate-700 border-l-4 bg-slate-800/50 px-3 py-2 hover:bg-slate-800 transition-colors"
            :class="chainStatusEdgeClass(node.status)"
            @click="emit('select', node.id)"
          >
            <template v-if="node.stage === 'sale'">
              <span class="block text-sm font-semibold text-slate-200">{{ t('groupIndustry.chain.flow.sale') }}</span>
              <span class="mt-1 flex justify-between gap-2 text-xs text-slate-400">
                <span>{{ t('groupIndustry.chain.flow.soldUnits') }}</span>
                <span class="font-mono text-slate-200">{{ formatNumber(node.soldQuantity, 0) }}</span>
              </span>
              <span class="flex justify-between gap-2 text-xs text-slate-400">
                <span>{{ t('groupIndustry.chain.flow.grossRevenue') }}</span>
                <span class="font-mono text-slate-200"><IskAmount :value="node.grossRevenue" short /></span>
              </span>
            </template>
            <template v-else>
              <span class="flex items-center gap-2 min-w-0">
                <img
                  v-if="node.typeId !== null"
                  :src="getTypeIconUrl(node.typeId, 32)"
                  alt=""
                  class="w-6 h-6 rounded border border-slate-700 shrink-0"
                  @error="onImageError"
                />
                <span class="block truncate min-w-0 text-sm font-semibold text-slate-200" :title="node.typeName ?? undefined">{{ node.typeName }}</span>
              </span>
              <span class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
                <span
                  class="px-1.5 py-0.5 rounded-sm border text-xs"
                  :class="chainStatusChipClass(node.status)"
                >{{ t(`groupIndustry.chain.status.${node.status}`) }}</span>
                <span v-if="node.activity !== null">{{ t(`groupIndustry.chain.activity.${node.activity}`) }}</span>
                <span v-if="node.runs !== null" class="font-mono">×{{ formatNumber(node.runs, 0) }}</span>
              </span>
              <span v-if="node.stage === 'material' && node.requiredQuantity !== null && node.fulfilledQuantity !== null" class="block mt-1.5">
                <span class="block text-xs font-mono text-slate-300">
                  {{ t('groupIndustry.chain.flow.supplied', { fulfilled: formatNumber(node.fulfilledQuantity, 0), required: formatNumber(node.requiredQuantity, 0) }) }}
                </span>
                <span v-if="progressPercent(node) !== null" class="block mt-1 h-1 rounded bg-slate-700 overflow-hidden">
                  <i class="block h-full bg-cyan-500" :style="{ width: `${progressPercent(node)}%` }"></i>
                </span>
              </span>
              <span v-if="node.costState !== 'none'" class="block mt-1.5 text-xs font-mono text-slate-300">
                <IskAmount v-if="node.costState === 'known'" :value="node.realCost" short />
                <UnknownContributionValue v-else />
              </span>
            </template>
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
