<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useProjectSetupStore } from '@/stores/group-industry/projectSetup'
import type { BomItem, SetupSourcingChoice, SourcingOption, SourcingRow } from '@/stores/group-industry/types'
import { projectRefusalMessage } from '@/utils/projectRefusal'
import { sourcingComparisonOf, sourcingRowOfDecisions } from './projectFlowCardDetail'
import { useFlowDetailFormat } from './useFlowDetailFormat'

const props = defineProps<{
  projectId: string
  line: BomItem
  canSwitch: boolean
  blacklistTypeIds: number[]
  afterSwitch: () => void
}>()

const CHOICES: SetupSourcingChoice[] = ['build', 'jita', 'structure']

const { t } = useI18n()
const { detailText, iskOrUnknown, numberOrUnknown } = useFlowDetailFormat()
const setupStore = useProjectSetupStore()
const projectStore = useGroupProjectStore()

const isAwaitingConfirmation = ref(false)
const isSwitching = ref(false)
const switchError = ref<string | null>(null)

const row = computed(() => sourcingRowOfDecisions(setupStore.decisions, props.projectId, props.line.typeId))
const typeLabel = computed(() => row.value?.typeName ?? props.line.typeName)
const isExcluded = computed(() => props.blacklistTypeIds.includes(props.line.typeId))
const switchLabel = computed(() => (isExcluded.value ? detailText('buyOrBuild.switch.toBuild') : detailText('buyOrBuild.switch.toBuy')))
const switchConfirmation = computed(() => (isExcluded.value
  ? detailText('buyOrBuild.switch.confirmToBuild', { name: typeLabel.value })
  : detailText('buyOrBuild.switch.confirmToBuy', { name: typeLabel.value })))
const comparison = computed(() => (row.value === undefined ? { state: 'none' as const } : sourcingComparisonOf(row.value)))
const presentOptions = computed(() => CHOICES.flatMap((choice) => {
  const option = row.value?.options[choice]
  return option === undefined ? [] : [{ choice, option }]
}))

function stockText(option: SourcingOption): string {
  return option.availableQuantity === null
    ? detailText('buyOrBuild.stockUnknown')
    : detailText('buyOrBuild.onSaleOnly', { quantity: numberOrUnknown(option.availableQuantity) })
}

function gapText(gap: number | null): string {
  if (gap === null) return detailText('unknown')
  return gap > 0 ? `+ ${iskOrUnknown(gap)}` : iskOrUnknown(gap)
}

function askToSwitch(): void {
  isAwaitingConfirmation.value = true
}

function cancelSwitch(): void {
  isAwaitingConfirmation.value = false
}

function failureMessageOf(error: unknown): string {
  return error instanceof Error && error.message !== '' ? projectRefusalMessage(error.message, t) : detailText('buyOrBuild.switch.error')
}

async function confirmSwitch(sourcingRow: SourcingRow): Promise<void> {
  const blacklistTypeIds = isExcluded.value
    ? props.blacklistTypeIds.filter(typeId => typeId !== sourcingRow.typeId)
    : [...props.blacklistTypeIds, sourcingRow.typeId]
  isSwitching.value = true
  switchError.value = null
  try {
    await projectStore.updateProject(props.projectId, { blacklistTypeIds })
    props.afterSwitch()
    await setupStore.fetchSourcingDecisions(props.projectId)
  } catch (error) {
    switchError.value = failureMessageOf(error)
  } finally {
    isSwitching.value = false
    isAwaitingConfirmation.value = false
  }
}
</script>

<template>
  <div>
    <p v-if="row === undefined" data-testid="flow-detail-buy-or-build-none" class="text-slate-400">{{ detailText('buyOrBuild.none') }}</p>

    <template v-else>
      <div class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
        <span
          v-if="isExcluded"
          data-testid="flow-detail-buy-or-build-excluded"
          class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-1.5 text-xs text-amber-300"
        >{{ detailText('buyOrBuild.excluded') }}</span>
        <span
          v-if="comparison.state === 'onRecommended'"
          data-testid="flow-detail-buy-or-build-on-recommended"
          class="text-emerald-300"
        >{{ detailText('buyOrBuild.onRecommended') }}</span>
        <span
          v-else-if="comparison.state === 'off'"
          data-testid="flow-detail-buy-or-build-gap"
          class="text-amber-300"
        >{{ detailText('buyOrBuild.gap', { amount: gapText(comparison.gap) }) }}</span>
      </div>

      <ul class="space-y-1">
        <li
          v-for="{ choice, option } in presentOptions"
          :key="choice"
          :data-testid="`flow-detail-buy-or-build-option-${choice}`"
          :data-chosen="String(row.chosen === choice)"
          :data-recommended="String(row.recommended === choice)"
          class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 border-l-2 pl-2"
          :class="row.chosen === choice ? 'border-cyan-400 text-slate-100' : 'border-slate-700 text-slate-400'"
        >
          <span class="min-w-[8.5rem] font-medium">{{ detailText(`buyOrBuild.options.${choice}`) }}</span>
          <span class="tabular-nums">{{ iskOrUnknown(option.total) }}</span>
          <span class="tabular-nums text-slate-500">{{ detailText('buyOrBuild.perUnit', { amount: iskOrUnknown(option.perUnit) }) }}</span>
          <span
            v-if="row.chosen === choice"
            class="rounded-sm border border-cyan-500/40 bg-cyan-500/10 px-1.5 text-xs text-cyan-300"
          >{{ detailText('buyOrBuild.chosen') }}</span>
          <span
            v-if="row.recommended === choice"
            class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-1.5 text-xs text-amber-300"
          >{{ detailText('buyOrBuild.recommended') }}</span>
          <span v-if="!option.eligible" class="text-amber-400">{{ stockText(option) }}</span>
        </li>
      </ul>

      <div
        v-if="switchError !== null"
        data-testid="flow-detail-buy-or-build-switch-error"
        role="alert"
        class="mt-2 border border-red-500/30 bg-red-500/10 p-2 text-red-300"
      >
        <p class="font-semibold">{{ detailText('buyOrBuild.switch.error') }}</p>
        <p class="mt-0.5 break-words">{{ switchError }}</p>
      </div>

      <button
        v-if="canSwitch"
        type="button"
        data-testid="flow-detail-buy-or-build-switch"
        :disabled="isSwitching"
        class="mt-2 block rounded-sm border border-cyan-500/40 px-2 py-1 text-cyan-300 hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:opacity-50"
        @click="askToSwitch"
      >{{ switchLabel }}</button>

      <div
        v-if="canSwitch && isAwaitingConfirmation"
        data-testid="flow-detail-buy-or-build-switch-confirmation"
        role="group"
        class="mt-2 space-y-2 border border-amber-500/40 bg-amber-500/10 p-2 text-slate-200"
      >
        <p>{{ switchConfirmation }}</p>
        <div class="flex gap-2">
          <button
            type="button"
            data-testid="flow-detail-buy-or-build-switch-confirm"
            :disabled="isSwitching"
            class="rounded-sm border border-cyan-500/40 bg-cyan-500/10 px-2 py-1 text-cyan-300 hover:bg-cyan-500/20 disabled:cursor-not-allowed disabled:opacity-50"
            @click="confirmSwitch(row)"
          >{{ detailText('buyOrBuild.switch.confirm') }}</button>
          <button
            type="button"
            data-testid="flow-detail-buy-or-build-switch-cancel"
            :disabled="isSwitching"
            class="rounded-sm border border-slate-600 px-2 py-1 text-slate-300 hover:bg-slate-700/40 disabled:cursor-not-allowed disabled:opacity-50"
            @click="cancelSwitch"
          >{{ t('common.actions.cancel') }}</button>
        </div>
      </div>
    </template>
  </div>
</template>
