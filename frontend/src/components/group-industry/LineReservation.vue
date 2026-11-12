<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem } from '@/stores/group-industry/types'

const props = defineProps<{
  item: BomItem
}>()

const emit = defineEmits<{
  contribute: [item: BomItem]
}>()

const MINIMUM_RESERVED_QUANTITY = 1

const { t } = useI18n()
const projectStore = useGroupProjectStore()
const { formatNumber } = useFormatters()

const isBusy = ref(false)
const hasFailed = ref(false)

const projectId = computed(() => projectStore.currentProject?.id ?? null)
const isSolo = computed(() => projectStore.currentProject?.solo === true)
const isOpen = computed(() => {
  const project = projectStore.currentProject
  return project !== null && project.status !== 'completed' && !props.item.isFulfilled
})
const myReservation = computed(() => props.item.reservations.find(reservation => reservation.isMine) ?? null)
const maximumQuantity = computed(() => props.item.freeQuantity + (myReservation.value?.quantity ?? 0))
const canReserve = computed(() => isOpen.value && maximumQuantity.value >= MINIMUM_RESERVED_QUANTITY)

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

function reserve(): Promise<void> {
  return run(id => projectStore.reserveLine(id, props.item.id, clampedQuantity()))
}

function release(): Promise<void> {
  return run(id => projectStore.releaseLine(id, props.item.id))
}
</script>

<template>
  <div class="flex flex-col gap-1.5" data-testid="line-reservation">
    <ul v-if="!isSolo && item.reservations.length > 0" class="flex flex-wrap gap-1">
      <li
        v-for="reservation in item.reservations"
        :key="reservation.participantId"
        data-testid="reservation"
        class="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs"
        :class="reservation.isMine ? 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 bg-slate-800/60 text-slate-300'"
      >
        <span>{{ reservation.characterName }}</span>
        <span class="font-mono text-slate-400" style="font-variant-numeric: tabular-nums;">&times;{{ formatNumber(reservation.quantity, 0) }}</span>
        <span v-if="reservation.isMine" class="text-cyan-400/70">({{ t('groupIndustry.reservation.mine') }})</span>
      </li>
    </ul>
    <span v-if="!isSolo && isOpen && item.freeQuantity > 0 && item.reservations.length > 0" class="text-xs text-slate-500">
      {{ t('groupIndustry.reservation.free', { quantity: formatNumber(item.freeQuantity, 0) }) }}
    </span>

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
