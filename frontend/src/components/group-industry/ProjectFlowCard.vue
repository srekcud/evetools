<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { FlowCard, FlowCardState } from './projectFlow'
import { useFlowReasonLabel } from './useFlowReasonLabel'

const STRIPES_SKY = 'bg-[repeating-linear-gradient(45deg,transparent_0_6px,rgba(56,189,248,0.05)_6px_12px)]'
const STRIPES_GREY = 'bg-[repeating-linear-gradient(45deg,transparent_0_6px,rgba(100,116,139,0.13)_6px_12px)]'

const STATE_CLASSES: Record<FlowCardState, string> = {
  toBring: 'border-l-cyan-400',
  inTransit: `border-l-sky-400 ${STRIPES_SKY}`,
  onSite: 'border-l-slate-500',
  consumed: 'border-l-slate-600 opacity-50',
  inProduction: 'border-l-sky-400',
  out: 'border-l-cyan-400',
  toBuy: 'border-l-amber-400',
  toLaunch: 'border-l-amber-400',
  sold: 'border-l-emerald-400',
  unknown: `border-l-slate-500 italic text-slate-400 ${STRIPES_GREY}`,
}

const props = defineProps<{
  card: FlowCard
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const reasonLabel = useFlowReasonLabel()

const name = computed(() => props.card.typeName ?? t('groupIndustry.flow.unnamedType'))
const reason = computed(() => (props.card.unknownReason === null ? null : reasonLabel(props.card.unknownReason)))
</script>

<template>
  <div
    data-testid="flow-card"
    :data-state="card.state"
    class="min-w-0 border border-l-[3px] border-slate-800 bg-slate-900 px-2 py-1.5"
    :class="STATE_CLASSES[card.state]"
  >
    <div class="flex min-w-0 items-center gap-2">
      <img
        v-if="card.typeId !== null"
        :src="getTypeIconUrl(card.typeId, 32)"
        alt=""
        class="h-[26px] w-[26px] flex-none border border-slate-700"
        @error="onImageError"
      >
      <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-200" :title="name">{{ name }}</span>
      <span v-if="card.quantity !== null" class="whitespace-nowrap text-xs tabular-nums text-slate-400">{{ formatNumber(card.quantity, 0) }}</span>
    </div>
    <div class="mt-1 flex flex-wrap items-center gap-x-2 text-[11.5px] text-slate-400">
      <span>{{ t(`groupIndustry.flow.states.${card.state}`) }}</span>
      <span v-if="reason !== null" class="italic text-slate-500">{{ reason }}</span>
    </div>
  </div>
</template>
