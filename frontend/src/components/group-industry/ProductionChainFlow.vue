<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChainNode, ChainStage } from '@/stores/group-industry/types'
import ProductionChainFlowNode from './ProductionChainFlowNode.vue'

type FlowColumnKey = 'inventionMaterials' | 'components' | 'product' | 'sale'

const FLOW_COLUMNS: readonly { key: FlowColumnKey; stages: readonly ChainStage[]; foldsDone: boolean }[] = [
  { key: 'inventionMaterials', stages: ['blueprint', 'material'], foldsDone: true },
  { key: 'components', stages: ['component'], foldsDone: true },
  { key: 'product', stages: ['product'], foldsDone: true },
  { key: 'sale', stages: ['sale'], foldsDone: false },
]

const props = defineProps<{
  nodes: ChainNode[]
}>()

const emit = defineEmits<{
  select: [nodeId: string]
}>()

const { t } = useI18n()

const columns = computed(() => FLOW_COLUMNS.map((column) => {
  const nodes = props.nodes.filter(node => column.stages.includes(node.stage))
  const isFolded = (node: ChainNode): boolean => column.foldsDone && node.status === 'done'
  return {
    key: column.key,
    openNodes: nodes.filter(node => !isFolded(node)),
    doneNodes: nodes.filter(isFolded),
  }
}))
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
          <ProductionChainFlowNode
            v-for="node in column.openNodes"
            :key="node.id"
            :node="node"
            @select="emit('select', $event)"
          />
          <details
            v-if="column.doneNodes.length > 0"
            :data-testid="`chain-done-${column.key}`"
            class="flex flex-col gap-2"
          >
            <summary class="cursor-pointer select-none text-xs uppercase tracking-widest text-slate-500 px-1 py-1">
              {{ t('groupIndustry.chain.flow.doneCount', { count: column.doneNodes.length }) }}
            </summary>
            <div class="flex flex-col gap-2 mt-2">
              <ProductionChainFlowNode
                v-for="node in column.doneNodes"
                :key="node.id"
                :node="node"
                @select="emit('select', $event)"
              />
            </div>
          </details>
        </div>
      </template>
    </div>
  </div>
</template>
