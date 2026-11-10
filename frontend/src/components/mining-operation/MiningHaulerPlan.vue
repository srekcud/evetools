<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MiningHaulerBar from '@/components/mining-operation/MiningHaulerBar.vue'
import { useMiningHaulerText } from '@/composables/useMiningHaulerText'
import type { MiningHaulerPlan } from '@/stores/miningOperation'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'

const props = defineProps<{
  plan: MiningHaulerPlan
  canDeclareEmptied: boolean
  typeNames: Record<number, string>
}>()

defineEmits<{ 'declare-emptied': [] }>()

const { t, locale } = useI18n()
const { shipNameOf, wholeOrUnknown, originText } = useMiningHaulerText()

const isKnown = computed(() => props.plan.state === 'known')
const tripsText = computed(() => {
  const { trips, toMoveM3, siteEtaAt, rawTypeIds } = props.plan
  if (trips === null || toMoveM3 === null || siteEtaAt === null) return t('miningOperation.haulerPlan.tripsUnknown')
  const raw =
    rawTypeIds.length === 0
      ? t('miningOperation.haulerPlan.compressedOre')
      : t('miningOperation.haulerPlan.rawOre', { types: rawTypeIds.map(typeId => props.typeNames[typeId] ?? t('common.unknownTypeName', { typeId })).join(', ') })
  return t('miningOperation.haulerPlan.trips', { count: trips, time: formatEveClock(siteEtaAt), volume: formatWhole(toMoveM3, locale.value), raw }, trips)
})
const calculationText = computed(() => {
  const { inflowM3PerMinute, share, transportM3PerMinute } = props.plan
  if (inflowM3PerMinute === null || share === null || transportM3PerMinute === null) return t('miningOperation.haulerPlan.calculationUnknown')
  return t('miningOperation.haulerPlan.calculation', {
    inflow: formatWhole(inflowM3PerMinute, locale.value),
    share: formatWhole(share * 100, locale.value),
    transport: formatWhole(transportM3PerMinute, locale.value),
  })
})
const capacitiesText = computed(() => t('miningOperation.haulerPlan.capacities', { hold: wholeOrUnknown(props.plan.holdCapacityM3), hangar: wholeOrUnknown(props.plan.capacityM3) }))
const contributorsText = computed(() => props.plan.contributorNames.join(', '))
</script>

<template>
  <section class="eve-card space-y-2 p-5" data-testid="hauler-plan">
    <div class="flex items-center justify-between gap-2">
      <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.haulerPlan.title') }}</h2>
      <button
        v-if="canDeclareEmptied"
        type="button"
        class="shrink-0 rounded-md border border-cyan-500/40 px-3 py-1 text-xs text-cyan-200 hover:bg-cyan-500/10"
        data-testid="hauler-emptied-button"
        @click="$emit('declare-emptied')"
      >{{ t('miningOperation.haulerPlan.emptied') }}</button>
    </div>

    <MiningHaulerBar :plan="plan" :compact="false" />

    <template v-if="isKnown">
      <p class="font-mono text-xs" data-testid="hauler-trips" :class="plan.trips === null ? 'text-amber-300' : 'text-slate-300'">{{ tripsText }}</p>
      <p class="font-mono text-xs text-slate-400" data-testid="hauler-calculation">{{ calculationText }}</p>
      <p class="font-mono text-xs text-slate-400" data-testid="hauler-capacities">{{ capacitiesText }}</p>
      <p class="truncate font-mono text-xs text-slate-400" data-testid="hauler-origin" :title="originText(plan)">{{ originText(plan) }}</p>
      <p v-if="contributorsText !== ''" class="truncate font-mono text-xs text-slate-400" data-testid="hauler-contributors" :title="contributorsText">
        {{ t('miningOperation.haulerPlan.contributors', { ship: shipNameOf(plan), names: contributorsText }) }}
      </p>
    </template>
    <p v-else class="truncate font-mono text-xs text-slate-400" data-testid="hauler-origin" :title="originText(plan)">{{ originText(plan) }}</p>

    <p class="text-xs text-slate-500" data-testid="hauler-estimate">{{ t('miningOperation.haulerPlan.estimate') }}</p>
  </section>
</template>
