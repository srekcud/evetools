<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupCourierStore } from '@/stores/group-industry/courier'
import { useGroupMaterialSourcingStore } from '@/stores/group-industry/materialSourcing'
import type { BomItem, MaterialSourcingSide } from '@/stores/group-industry/types'
import type { FlowCard } from './projectFlow'
import { sourcingRowOf, type DetailBlockKind } from './projectFlowCardDetail'
import { useFlowDetailFormat } from './useFlowDetailFormat'

const props = defineProps<{
  kind: DetailBlockKind
  card: FlowCard
  line: BomItem
}>()

const SIDE_LABEL_KEYS: Record<MaterialSourcingSide, string> = {
  jita: 'sides.jita',
  structure: 'sides.structure',
  unknown: 'unknown',
}

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { detailText, numberOrUnknown, iskOrUnknown, textOrUnknown, cubicMetersOrUnknown } = useFlowDetailFormat()
const sourcingStore = useGroupMaterialSourcingStore()
const courierStore = useGroupCourierStore()

const sourcing = computed(() => sourcingStore.sourcing)
const sourcingRow = computed(() => (sourcing.value === null || props.card.typeId === null ? undefined : sourcingRowOf(sourcing.value.rows, props.card.typeId)))
const sourcedVolume = computed(() => {
  const row = sourcingRow.value
  return row === undefined || row.volumePerUnit === null ? null : row.volumePerUnit * row.quantity
})
const reservationsOfOthers = computed(() => props.line.reservations.filter((reservation) => !reservation.isMine))
const courier = computed(() => courierStore.courier)
const stockQuantity = computed(() => {
  const { containerStockState, containerQuantity } = props.line
  if (containerStockState === 'none') return detailText('noContainer')
  if (containerStockState === 'known') return numberOrUnknown(containerQuantity)
  return detailText('unknown')
})

function purchaseSource(transactionId: string | null): string {
  return t(transactionId === null ? 'groupIndustry.purchases.sourceManual' : 'groupIndustry.purchases.sourceTransaction')
}
</script>

<template>
  <div v-if="kind === 'whereToBuy'">
    <dl v-if="sourcingRow !== undefined" class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
      <dt class="text-slate-500">{{ detailText('fields.jita') }}</dt>
      <dd class="tabular-nums">{{ iskOrUnknown(sourcingRow.jitaWithImportTotal) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.structure', { name: textOrUnknown(sourcing?.structureName) }) }}</dt>
      <dd class="tabular-nums">{{ iskOrUnknown(sourcingRow.structureTotal) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.bestSide') }}</dt>
      <dd>{{ detailText(SIDE_LABEL_KEYS[sourcingRow.side]) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.volume') }}</dt>
      <dd class="tabular-nums">{{ cubicMetersOrUnknown(sourcedVolume) }}</dd>
    </dl>
    <p v-else>{{ detailText('unknown') }}</p>
  </div>

  <div v-else-if="kind === 'courier'">
    <p class="mb-1 text-slate-500">{{ detailText('phrases.courierWholeProject') }}</p>
    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
      <dt class="text-slate-500">{{ detailText('fields.volume') }}</dt>
      <dd class="tabular-nums">{{ cubicMetersOrUnknown(courier?.volumeCubicMeters) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.collateral') }}</dt>
      <dd class="tabular-nums">{{ iskOrUnknown(courier?.collateral) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.reward') }}</dt>
      <dd class="tabular-nums">{{ iskOrUnknown(courier?.reward) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.route') }}</dt>
      <dd>{{ textOrUnknown(courier?.originSolarSystemName) }} → {{ textOrUnknown(courier?.workshopSolarSystemName) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.contract') }}</dt>
      <dd v-if="courier?.contract" class="tabular-nums">{{ detailText('phrases.contractLinked', { id: courier.contract.contractId }) }}</dd>
      <dd v-else>{{ courier === null ? detailText('unknown') : detailText('phrases.contractNotLinked') }}</dd>
    </dl>
  </div>

  <div v-else-if="kind === 'contributions'">
    <p class="mb-1 tabular-nums">{{ detailText('phrases.fulfilled', { fulfilled: formatNumber(line.fulfilledQuantity, 0), required: formatNumber(line.requiredQuantity, 0) }) }}</p>
    <ul v-if="line.purchases.length > 0" class="space-y-0.5">
      <li v-for="purchase in line.purchases" :key="purchase.id" data-testid="flow-detail-purchase" class="flex flex-wrap gap-x-2">
        <span>{{ textOrUnknown(purchase.buyerName) }}</span>
        <span class="tabular-nums">{{ formatNumber(purchase.quantity, 0) }}</span>
        <span class="tabular-nums">{{ iskOrUnknown(purchase.totalPrice) }}</span>
        <span class="text-slate-500">{{ purchaseSource(purchase.transactionId) }}</span>
      </li>
    </ul>
    <p v-else class="text-slate-500">{{ detailText('phrases.noPurchase') }}</p>
    <ul v-if="line.pendingContributions.length > 0" class="mt-1 space-y-0.5">
      <li v-for="pending in line.pendingContributions" :key="pending.participantId" class="flex gap-x-2 text-amber-300">
        <span>{{ textOrUnknown(pending.characterName) }}</span>
        <span class="tabular-nums">{{ formatNumber(pending.quantity, 0) }}</span>
        <span>{{ detailText('phrases.awaitingCheck') }}</span>
      </li>
    </ul>
  </div>

  <div v-else-if="kind === 'useMyStock'">
    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
      <dt class="text-slate-500">{{ detailText('fields.inContainers') }}</dt>
      <dd class="tabular-nums">{{ stockQuantity }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.reserved') }}</dt>
      <dd class="tabular-nums">{{ numberOrUnknown(line.reservedQuantity) }}</dd>
      <dt class="text-slate-500">{{ detailText('fields.free') }}</dt>
      <dd class="tabular-nums">{{ numberOrUnknown(line.freeQuantity) }}</dd>
    </dl>
    <div v-if="reservationsOfOthers.length > 0" data-testid="flow-detail-reserved-by-others" class="mt-1">
      <p class="text-slate-500">{{ detailText('phrases.reservedByOthers') }}</p>
      <ul class="space-y-0.5">
        <li v-for="reservation in reservationsOfOthers" :key="reservation.participantId" class="flex gap-x-2">
          <span>{{ textOrUnknown(reservation.characterName) }}</span>{{ ' ' }}
          <span class="tabular-nums">{{ formatNumber(reservation.quantity, 0) }}</span>
        </li>
      </ul>
    </div>
  </div>

  <div v-else-if="kind === 'depositToCheck'">
    <p class="mb-1 text-slate-400">{{ detailText('phrases.depositToCheck') }}</p>
    <ul class="space-y-0.5">
      <li v-for="pending in line.pendingContributions" :key="pending.participantId" data-testid="flow-detail-pending" class="flex gap-x-2">
        <span>{{ textOrUnknown(pending.characterName) }}</span>
        <span class="tabular-nums">{{ formatNumber(pending.quantity, 0) }}</span>
      </li>
    </ul>
  </div>
</template>
