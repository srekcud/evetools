<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { ACTIVITY_STYLES } from './companionActivityStyles'
import { countdownPartsOf } from './companionTasks'
import type { FlowCard, FlowCardSource, FlowCardState } from './projectFlow'
import { useFlowReasonLabel } from './useFlowReasonLabel'

const STRIPES_SKY = 'bg-[repeating-linear-gradient(45deg,transparent_0_6px,rgba(56,189,248,0.05)_6px_12px)]'
const STRIPES_GREY = 'bg-[repeating-linear-gradient(45deg,transparent_0_6px,rgba(100,116,139,0.13)_6px_12px)]'

const READY_STRIPE = 'border-l-emerald-400'
const READY_TINT = 'from-emerald-400/15'
const READY_BAR = 'bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.8)]'
const TIME_UNIT_DIGITS = 2

const SOLID_BASE = 'border-slate-800 bg-slate-900'
const EXPECTED_BASE = 'border-dashed border-slate-600 bg-transparent opacity-85 hover:bg-white/5 hover:opacity-100'
const HIGHLIGHT_CLASS = 'ring-1 ring-cyan-400/70'

const STATE_SURFACE_CLASSES: Partial<Record<FlowCardState, string>> = {
  inTransit: STRIPES_SKY,
  consumed: 'opacity-50',
  unknown: `italic text-slate-400 ${STRIPES_GREY}`,
}

const STATE_TEXT_CLASSES: Partial<Record<FlowCardState, string>> = {
  toBuy: 'text-amber-400',
  toLaunch: 'text-amber-400',
}

const SOURCE_CLASSES: Record<FlowCardSource, string> = {
  stock: 'border-slate-600 text-slate-400',
  blueprint: 'border-violet-400/50 text-violet-300',
  invention: 'border-blue-400/50 text-blue-300',
}

const props = withDefaults(defineProps<{
  card: FlowCard
  highlighted?: boolean
}>(), { highlighted: false })

const emit = defineEmits<{
  highlight: [entered: boolean]
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const reasonLabel = useFlowReasonLabel()

const name = computed(() => props.card.typeName ?? t('groupIndustry.flow.unnamedType'))
const reason = computed(() => (props.card.unknownReason === null ? null : reasonLabel(props.card.unknownReason)))
const activityStyle = computed(() => ACTIVITY_STYLES[props.card.activity])
const root = useTemplateRef<HTMLElement>('root')

const HIGHLIGHT_EVENTS = [['mouseenter', true], ['mouseleave', false], ['focusin', true], ['focusout', false]] as const
const listeners = HIGHLIGHT_EVENTS.map(([eventName, entered]) => ({ eventName, listener: () => emit('highlight', entered) }))

onMounted(() => {
  for (const { eventName, listener } of listeners) root.value?.addEventListener(eventName, listener)
})

onBeforeUnmount(() => {
  for (const { eventName, listener } of listeners) root.value?.removeEventListener(eventName, listener)
})

const isExpected = computed(() => props.card.state === 'expected')
const cardKey = computed(() => props.card.expected?.cardKey ?? props.card.key)
const stripeClass = computed(() => (props.card.ready && !isExpected.value ? READY_STRIPE : activityStyle.value.stripe))
const tintClass = computed(() => (props.card.state === 'inTransit' || props.card.state === 'unknown' || isExpected.value ? '' : `bg-gradient-to-r ${props.card.ready ? READY_TINT : activityStyle.value.tint} to-transparent`))
const baseClass = computed(() => (isExpected.value ? EXPECTED_BASE : SOLID_BASE))
const expectedWhy = computed(() => {
  const { expected } = props.card
  if (expected === null) return null
  const from = expected.fromKind === 'unresolved' ? t('groupIndustry.flow.unresolvedRow') : (expected.fromName ?? t('groupIndustry.flow.unnamedStation'))
  return t(`groupIndustry.flow.expected.${expected.origin}`, { from })
})
const barClass = computed(() => (props.card.ready ? READY_BAR : activityStyle.value.bar))

function padTimeUnit(value: number): string {
  return String(value).padStart(TIME_UNIT_DIGITS, '0')
}

const remaining = computed(() => {
  if (props.card.remainingMs === null) return null
  const { days, hours, minutes } = countdownPartsOf(props.card.remainingMs)
  const time = days > 0
    ? t('groupIndustry.companion.countdown.days', { days, hours: padTimeUnit(hours) })
    : t('groupIndustry.companion.countdown.clock', { hours: padTimeUnit(hours), minutes: padTimeUnit(minutes) })
  return t('groupIndustry.flow.remaining', { time })
})
</script>

<template>
  <div
    ref="root"
    data-testid="flow-card"
    :data-state="card.state"
    :data-activity="card.activity"
    role="group"
    tabindex="0"
    :aria-label="name"
    :data-card-key="cardKey"
    :data-highlighted="String(highlighted)"
    :data-expected-origin="card.expected?.origin"
    class="min-w-0 border border-l-[3px] px-2 py-1.5"
    :class="[baseClass, stripeClass, tintClass, STATE_SURFACE_CLASSES[card.state], highlighted ? HIGHLIGHT_CLASS : '']"
  >
    <div class="flex min-w-0 items-center gap-2">
      <span v-if="card.typeId !== null" class="relative h-[26px] w-[26px] flex-none bg-black">
        <img
          :src="getTypeIconUrl(card.typeId, 32)"
          alt=""
          class="h-[26px] w-[26px]"
          @error="onImageError"
        >
        <span
          v-if="card.bpc"
          data-testid="flow-card-bpc"
          class="absolute inset-0 border border-violet-400 shadow-[inset_0_0_0_1px_#000]"
        />
      </span>
      <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-200" :title="name">{{ name }}</span>
      <span v-if="card.quantity !== null" class="whitespace-nowrap text-xs tabular-nums text-slate-400">{{ formatNumber(card.quantity, 0) }}</span>
    </div>
    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11.5px] text-slate-400">
      <span
        v-if="card.source !== null"
        data-testid="flow-card-source"
        class="whitespace-nowrap rounded-sm border px-1.5 text-[10px]"
        :class="SOURCE_CLASSES[card.source]"
      >{{ t(`groupIndustry.flow.sources.${card.source}`) }}</span>
      <span
        v-if="isExpected"
        data-testid="flow-card-expected"
        class="whitespace-nowrap rounded-sm border border-dashed border-current px-1.5 text-[10px]"
        :class="activityStyle.text"
      >{{ t('groupIndustry.flow.states.expected') }}</span>
      <span v-if="expectedWhy !== null" data-testid="flow-card-expected-why" :class="card.expected?.origin === 'out' ? 'text-amber-400' : ''">{{ expectedWhy }}</span>
      <span v-if="!isExpected" :class="STATE_TEXT_CLASSES[card.state]">{{ t(`groupIndustry.flow.states.${card.state}`) }}</span>
      <span v-if="remaining !== null" data-testid="flow-card-remaining" class="tabular-nums text-sky-300">{{ remaining }}</span>
      <span v-if="reason !== null" class="italic text-slate-500">{{ reason }}</span>
    </div>
    <div
      v-if="card.progress !== null && !isExpected"
      data-testid="flow-card-progress"
      role="progressbar"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-valuenow="card.progress"
      :aria-label="t('groupIndustry.flow.progress', { name })"
      class="relative mt-1.5 h-[3px] bg-white/10"
    >
      <span class="absolute inset-y-0 left-0" :class="barClass" :style="{ width: `${card.progress}%` }" />
    </div>
  </div>
</template>
