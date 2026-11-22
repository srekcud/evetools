<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'
import FlowDetailBlock from './FlowDetailBlock.vue'
import FlowDetailBlueprintBlock from './FlowDetailBlueprintBlock.vue'
import FlowDetailJobBlock from './FlowDetailJobBlock.vue'
import FlowDetailSalesBlock from './FlowDetailSalesBlock.vue'
import FlowDetailSupplyBlock from './FlowDetailSupplyBlock.vue'
import type { FlowCard } from './projectFlow'
import { DETAIL_BLOCK_FAMILIES, detailBlockKindsOf } from './projectFlowCardDetail'
import { SOURCE_CLASSES } from './projectFlowSourceClasses'
import { useFlowDetailLoader } from './useFlowDetailLoader'

const props = defineProps<{
  projectId: string
  card: FlowCard
  line: BomItem | undefined
  project: GroupProject
  canLinkJobs: boolean
  canRecordSales: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const name = computed(() => props.card.typeName ?? t('groupIndustry.flow.unnamedType'))
const kinds = computed(() => detailBlockKindsOf({
  card: props.card,
  line: props.line,
  isFinalProduct: props.card.typeId !== null && props.project.items.some(item => item.typeId === props.card.typeId),
  personalUse: props.project.personalUse === true,
}))
const { statusOf, refresh } = useFlowDetailLoader({
  projectId: () => props.projectId,
  kinds: () => kinds.value,
  canRecordSales: () => props.canRecordSales,
})

defineExpose({ refresh })
</script>

<template>
  <aside data-testid="flow-card-detail" class="border border-cyan-400/30 bg-slate-950">
    <header class="flex items-center gap-2 px-3 py-2.5">
      <img
        v-if="card.typeId !== null"
        :src="getTypeIconUrl(card.typeId, 32)"
        alt=""
        class="h-[30px] w-[30px] flex-none border border-slate-700 bg-black"
        @error="onImageError"
      >
      <h2 class="min-w-0 flex-1 truncate font-display text-base font-semibold tracking-wide text-slate-100" :title="name">{{ name }}</h2>
      <span v-if="card.quantity !== null" class="whitespace-nowrap text-xs tabular-nums text-slate-400">{{ formatNumber(card.quantity, 0) }}</span>
      <button
        type="button"
        :aria-label="t('groupIndustry.flow.detail.closeDetail')"
        class="flex-none px-1 text-slate-400 hover:text-slate-100"
        @click="emit('close')"
      >✕</button>
    </header>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 px-3 pb-2 text-[11.5px] text-slate-400">
      <span
        v-if="card.source !== null"
        data-testid="flow-detail-source"
        class="whitespace-nowrap rounded-sm border px-1.5 text-[10px]"
        :class="SOURCE_CLASSES[card.source]"
      >{{ t(`groupIndustry.flow.sources.${card.source}`) }}</span>
      <span data-testid="flow-detail-state">{{ t(`groupIndustry.flow.states.${card.state}`) }}</span>
    </div>
    <p class="px-3 pb-2 text-xs text-slate-500">{{ t('groupIndustry.flow.detail.intro') }}</p>
    <p v-if="kinds.length === 0 || line === undefined" class="border-t border-slate-800 px-3 py-3 text-xs text-slate-400">{{ t('groupIndustry.flow.detail.nothingMore') }}</p>
    <template v-else>
      <FlowDetailBlock
        v-for="kind in kinds"
        :key="kind"
        :kind="kind"
        :loading="statusOf(kind).loading"
        :error="statusOf(kind).error"
      >
        <FlowDetailSupplyBlock v-if="DETAIL_BLOCK_FAMILIES[kind] === 'supply'" :kind="kind" :card="card" :line="line" />
        <FlowDetailJobBlock v-else-if="DETAIL_BLOCK_FAMILIES[kind] === 'job'" :kind="kind" :project-id="projectId" :line="line" :can-link-jobs="canLinkJobs" />
        <FlowDetailBlueprintBlock v-else-if="DETAIL_BLOCK_FAMILIES[kind] === 'blueprint'" :kind="kind" :line="line" />
        <FlowDetailSalesBlock v-else :kind="kind" :type-id="line.typeId" :can-record-sales="canRecordSales" />
      </FlowDetailBlock>
    </template>
  </aside>
</template>
