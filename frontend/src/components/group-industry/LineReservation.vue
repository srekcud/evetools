<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem, GroupContribution } from '@/stores/group-industry/types'
import { pendingQuantityOf } from './pendingContributions'

const props = defineProps<{
  item: BomItem
}>()

const emit = defineEmits<{
  contribute: [item: BomItem]
}>()

const MINIMUM_RESERVED_QUANTITY = 1

const { t } = useI18n()
const projectStore = useGroupProjectStore()
const contributionStore = useGroupContributionStore()
const { formatNumber } = useFormatters()

const isBusy = ref(false)
const hasFailed = ref(false)

const projectId = computed(() => projectStore.currentProject?.id ?? null)
const isSolo = computed(() => projectStore.currentProject?.solo === true)
const isOpen = computed(() => {
  const project = projectStore.currentProject
  return project !== null && project.status !== 'completed' && !props.item.isFulfilled
})
const reservationChips = computed(() => props.item.reservations
  .map(reservation => ({ reservation, quantityToDeliver: reservation.quantity - pendingQuantityOf(props.item, reservation.participantId) }))
  .filter(chip => chip.quantityToDeliver > 0))
const myReservation = computed(() => props.item.reservations.find(reservation => reservation.isMine) ?? null)
const maximumQuantity = computed(() => props.item.freeQuantity + (myReservation.value?.quantity ?? 0))
const canReserve = computed(() => isOpen.value && maximumQuantity.value >= MINIMUM_RESERVED_QUANTITY)

const suppliedContributions = computed(() => isSolo.value
  ? contributionStore.contributions.filter(contribution =>
    contribution.bomItemId === props.item.id && contribution.type === 'material' && contribution.editableByViewer === true)
  : [])
const correctingId = ref<string | null>(null)
const correctedQuantity = ref(MINIMUM_RESERVED_QUANTITY)
const isConfirmingRemoval = ref(false)
const correctionError = ref<string | null>(null)

const quantity = ref(maximumQuantity.value)
watch(maximumQuantity, (maximum) => {
  quantity.value = maximum
})

function clampedQuantity(): number {
  const typed = Number.isFinite(quantity.value) ? Math.trunc(quantity.value) : maximumQuantity.value
  return Math.min(maximumQuantity.value, Math.max(MINIMUM_RESERVED_QUANTITY, typed))
}

async function run(action: (projectId: string) => Promise<void>): Promise<void> {
  if (projectId.value === null || isBusy.value) return
  isBusy.value = true
  hasFailed.value = false
  try {
    await action(projectId.value)
  } catch {
    hasFailed.value = true
  } finally {
    isBusy.value = false
  }
}

function startCorrection(contribution: GroupContribution): void {
  correctingId.value = contribution.id
  correctedQuantity.value = contribution.quantity
  isConfirmingRemoval.value = false
  correctionError.value = null
}

function stopCorrection(): void {
  correctingId.value = null
  isConfirmingRemoval.value = false
  correctionError.value = null
}

function maximumCorrectedQuantity(contribution: GroupContribution): number {
  return contribution.quantity + props.item.remainingQuantity
}

async function correct(action: (projectId: string) => Promise<boolean>): Promise<void> {
  if (projectId.value === null || isBusy.value) return
  isBusy.value = true
  correctionError.value = null
  const succeeded = await action(projectId.value)
  if (succeeded) {
    await projectStore.fetchBom(projectId.value)
    stopCorrection()
  } else {
    correctionError.value = contributionStore.error ?? t('groupIndustry.reservation.correctionFailed')
  }
  isBusy.value = false
}

function saveCorrection(contribution: GroupContribution): Promise<void> {
  const typed = Number.isFinite(correctedQuantity.value) ? Math.trunc(correctedQuantity.value) : contribution.quantity
  const corrected = Math.min(maximumCorrectedQuantity(contribution), Math.max(MINIMUM_RESERVED_QUANTITY, typed))
  if (corrected === contribution.quantity) {
    stopCorrection()
    return Promise.resolve()
  }
  return correct(id => contributionStore.editContribution(id, contribution.id, { quantity: corrected }))
}

function removeContribution(contribution: GroupContribution): Promise<void> {
  return correct(id => contributionStore.withdrawContribution(id, contribution.id))
}

function reserve(): Promise<void> {
  return run(id => projectStore.reserveLine(id, props.item.id, clampedQuantity()))
}

function release(): Promise<void> {
  return run(id => projectStore.releaseLine(id, props.item.id))
}
</script>

<template>
  <div class="flex flex-col gap-1.5" data-testid="line-reservation">
    <ul v-if="!isSolo && item.pendingContributions.length > 0" class="flex flex-col items-start gap-1">
      <li
        v-for="contribution in item.pendingContributions"
        :key="contribution.participantId"
        data-testid="pending-contribution"
        class="flex flex-wrap max-w-full rounded border border-amber-500/45 bg-amber-500/10 px-1.5 py-0.5 text-xs text-amber-300"
      >
        <span data-testid="pending-contribution-name" :title="contribution.characterName ?? undefined" class="min-w-0 max-w-full truncate">{{ contribution.characterName }}</span>
        <span class="font-mono" style="font-variant-numeric: tabular-nums;">{{ ' · ' + t('groupIndustry.reservation.awaitingApproval', { quantity: formatNumber(contribution.quantity, 0) }) }}</span>
        <span v-if="contribution.isMine" class="text-amber-300/70">{{ ' (' + t('groupIndustry.reservation.mine') + ')' }}</span>
      </li>
    </ul>
    <ul v-if="!isSolo && reservationChips.length > 0" class="flex flex-wrap gap-1">
      <li
        v-for="{ reservation, quantityToDeliver } in reservationChips"
        :key="reservation.participantId"
        data-testid="reservation"
        class="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs"
        :class="reservation.isMine ? 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 bg-slate-800/60 text-slate-300'"
      >
        <span>{{ reservation.characterName }}</span>
        <span class="font-mono text-slate-400" style="font-variant-numeric: tabular-nums;">&times;{{ formatNumber(quantityToDeliver, 0) }}</span>
        <span v-if="reservation.isMine" class="text-cyan-400/70">({{ t('groupIndustry.reservation.mine') }})</span>
      </li>
    </ul>
    <span v-if="!isSolo && isOpen && item.freeQuantity > 0 && item.reservations.length > 0" class="text-xs text-slate-500">
      {{ t('groupIndustry.reservation.free', { quantity: formatNumber(item.freeQuantity, 0) }) }}
    </span>

    <ul v-if="suppliedContributions.length > 0" class="flex flex-col gap-1">
      <li
        v-for="contribution in suppliedContributions"
        :key="contribution.id"
        data-testid="supplied-contribution"
        class="flex flex-wrap items-center gap-1.5 text-xs"
      >
        <span class="rounded border border-emerald-500/40 bg-emerald-500/10 px-1.5 py-0.5 font-mono text-emerald-300" style="font-variant-numeric: tabular-nums;">{{ t('groupIndustry.reservation.supplied', { quantity: formatNumber(contribution.quantity, 0) }) }}</span>
        <button
          v-if="correctingId !== contribution.id"
          type="button"
          class="rounded border border-slate-600 px-2 py-0.5 text-slate-300 hover:bg-slate-800"
          @click="startCorrection(contribution)"
        >{{ t('groupIndustry.reservation.correct') }}</button>
        <template v-else>
          <input
            v-model.number="correctedQuantity"
            type="number"
            :min="MINIMUM_RESERVED_QUANTITY"
            :max="maximumCorrectedQuantity(contribution)"
            :aria-label="t('groupIndustry.reservation.correctedQuantity')"
            class="w-20 rounded border border-slate-700 bg-slate-900 px-1.5 py-0.5 font-mono text-slate-200"
          />
          <button
            v-if="!isConfirmingRemoval"
            type="button"
            class="rounded border border-cyan-500/40 px-2 py-0.5 text-cyan-300 hover:bg-cyan-500/10 disabled:opacity-50"
            :disabled="isBusy"
            @click="saveCorrection(contribution)"
          >{{ t('groupIndustry.reservation.save') }}</button>
          <button
            v-if="!isConfirmingRemoval"
            type="button"
            class="rounded border border-red-500/40 px-2 py-0.5 text-red-300 hover:bg-red-500/10 disabled:opacity-50"
            :disabled="isBusy"
            @click="isConfirmingRemoval = true"
          >{{ t('groupIndustry.reservation.remove') }}</button>
          <button
            v-else
            type="button"
            class="rounded border border-red-500/60 bg-red-500/10 px-2 py-0.5 text-red-300 hover:bg-red-500/20 disabled:opacity-50"
            :disabled="isBusy"
            @click="removeContribution(contribution)"
          >{{ t('groupIndustry.reservation.confirmRemoval') }}</button>
          <button
            type="button"
            class="rounded border border-slate-600 px-2 py-0.5 text-slate-300 hover:bg-slate-800"
            @click="stopCorrection"
          >{{ t('groupIndustry.reservation.cancel') }}</button>
        </template>
      </li>
    </ul>
    <p v-if="correctionError !== null" class="text-xs text-red-400" role="alert">{{ correctionError }}</p>

    <div v-if="isOpen" class="flex flex-wrap items-center gap-1.5">
      <template v-if="!isSolo && canReserve">
        <input
          v-model.number="quantity"
          type="number"
          :min="MINIMUM_RESERVED_QUANTITY"
          :max="maximumQuantity"
          :aria-label="t('groupIndustry.reservation.quantity')"
          class="w-20 rounded border border-slate-700 bg-slate-900 px-1.5 py-0.5 text-xs font-mono text-slate-200"
        />
        <button
          type="button"
          class="rounded border border-cyan-500/40 px-2 py-0.5 text-xs text-cyan-300 hover:bg-cyan-500/10 disabled:opacity-50"
          :disabled="isBusy"
          @click="reserve"
        >{{ t('groupIndustry.reservation.reserve') }}</button>
      </template>
      <button
        v-if="!isSolo && myReservation !== null"
        type="button"
        class="rounded border border-slate-600 px-2 py-0.5 text-xs text-slate-300 hover:bg-slate-800 disabled:opacity-50"
        :disabled="isBusy"
        @click="release"
      >{{ t('groupIndustry.reservation.release') }}</button>
      <button
        type="button"
        class="rounded border border-emerald-500/40 px-2 py-0.5 text-xs text-emerald-300 hover:bg-emerald-500/10"
        @click="emit('contribute', item)"
      >{{ t(isSolo ? 'groupIndustry.reservation.contributeSolo' : 'groupIndustry.reservation.contribute') }}</button>
    </div>
    <p v-if="hasFailed" class="text-xs text-red-400" role="alert">{{ t('groupIndustry.reservation.failed') }}</p>
  </div>
</template>
