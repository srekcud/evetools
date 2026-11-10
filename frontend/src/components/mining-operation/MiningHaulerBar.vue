<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMiningHaulerText } from '@/composables/useMiningHaulerText'
import type { MiningHaulerPlan } from '@/stores/miningOperation'
import { formatDuration } from '@/views/MiningOperation/miningOperationTime'

type HaulerTone = 'red' | 'amber' | 'cyan'

const PERCENT_MAX = 100
const SECONDS_PER_MINUTE = 60
const RED_BELOW_MINUTES = 3
const AMBER_BELOW_MINUTES = 8
const TONE_CLASSES: Record<HaulerTone, { text: string; bar: string }> = {
  red: { text: 'text-red-400', bar: 'bg-red-400' },
  amber: { text: 'text-amber-400', bar: 'bg-amber-400' },
  cyan: { text: 'text-cyan-400', bar: 'bg-cyan-400' },
}

const props = defineProps<{ plan: MiningHaulerPlan; compact: boolean }>()

const { t } = useI18n()
const { haulerNameOf, shipNameOf } = useMiningHaulerText()

const fill = computed(() => {
  const { fillRatio, fullInMinutes, isFull } = props.plan
  if (props.plan.state !== 'known' || fillRatio === null || fullInMinutes === null || isFull === null) return null
  const percent = Math.min(PERCENT_MAX, Math.max(0, Math.round(fillRatio * PERCENT_MAX)))
  const tone: HaulerTone = isFull || fullInMinutes < RED_BELOW_MINUTES ? 'red' : fullInMinutes < AMBER_BELOW_MINUTES ? 'amber' : 'cyan'
  const text = isFull ? t('miningOperation.haulerPlan.full') : t('miningOperation.haulerPlan.fill', { percent, time: formatDuration(Math.ceil(fullInMinutes) * SECONDS_PER_MINUTE) })
  return { percent, tone, text }
})
const label = computed(() =>
  props.compact
    ? t('miningOperation.haulerPlan.barLabelShort', { ship: shipNameOf(props.plan) })
    : t('miningOperation.haulerPlan.barLabel', { hauler: haulerNameOf(props.plan), ship: shipNameOf(props.plan) }),
)
</script>

<template>
  <div v-if="fill !== null" class="space-y-1 font-mono text-xs" data-testid="hauler-bar">
    <div class="flex items-baseline justify-between gap-2">
      <span class="min-w-0 truncate text-slate-300" :title="label">{{ label }}</span>
      <span class="shrink-0 whitespace-nowrap" :class="TONE_CLASSES[fill.tone].text" data-testid="hauler-fill-text">{{ fill.text }}</span>
    </div>
    <div class="h-2 overflow-hidden rounded-sm bg-slate-800" role="progressbar" aria-valuemin="0" :aria-valuemax="PERCENT_MAX" :aria-valuenow="fill.percent">
      <div class="h-full rounded-sm" :class="TONE_CLASSES[fill.tone].bar" :style="{ width: `${fill.percent}%` }" :data-tone="fill.tone" data-testid="hauler-fill" />
    </div>
  </div>
  <div v-else class="space-y-1 font-mono text-xs" data-testid="hauler-bar">
    <p class="break-words text-slate-300" data-testid="hauler-unknown" :title="t('miningOperation.haulerPlan.unknown', { hauler: haulerNameOf(plan) })">
      {{ t('miningOperation.haulerPlan.unknown', { hauler: haulerNameOf(plan) }) }}
    </p>
    <p v-if="plan.unknownReason !== null" class="text-amber-300" data-testid="hauler-unknown-reason">{{ t(`miningOperation.haulerPlan.reason.${plan.unknownReason}`) }}</p>
  </div>
</template>
