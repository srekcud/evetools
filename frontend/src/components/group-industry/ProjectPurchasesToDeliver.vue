<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useCoalescedGroupProjectEvents } from '@/composables/useCoalescedGroupProjectEvents'
import { useRefetchOnReconnect } from '@/composables/useRefetchOnReconnect'
import { useGroupCourierStore } from '@/stores/group-industry/courier'
import { useGroupPurchasesToDeliverStore } from '@/stores/group-industry/purchasesToDeliver'
import type { CourierTransportState, PurchaseDeliveryState, PurchaseToDeliverRow } from '@/stores/group-industry/types'
import { useSyncStore } from '@/stores/sync'
import UnknownContributionValue from './UnknownContributionValue.vue'

interface DeliveryGroup {
  key: string
  title: string
  rows: PurchaseToDeliverRow[]
}

const DELIVERY_CHIP_CLASSES: Record<PurchaseDeliveryState, string> = {
  missing: 'border-red-500/40 bg-red-500/10 text-red-300',
  partial: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  delivered: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  unknown: 'border-dashed border-violet-400 text-violet-300',
}

const COURIER_CHIP_CLASSES: Record<CourierTransportState, string> = {
  not_shipped: 'border-slate-600 bg-slate-800/60 text-slate-400',
  waiting: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  in_transit: 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300',
  delivered: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  failed: 'border-red-500/40 bg-red-500/10 text-red-300',
  unknown: 'border-dashed border-violet-400 text-violet-300',
}

const props = defineProps<{
  projectId: string
  canEdit?: boolean
}>()

const emit = defineEmits<{
  editContainers: []
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const syncStore = useSyncStore()
const deliveriesStore = useGroupPurchasesToDeliverStore()
const courierStore = useGroupCourierStore()

const deliveries = computed(() => deliveriesStore.deliveries)

const isVisible = computed(() => deliveriesStore.error !== null
  || (deliveries.value === null && deliveriesStore.loading)
  || (deliveries.value !== null && deliveries.value.purchaseCount > 0))

const stockNotice = computed(() => {
  const stockState = deliveries.value?.stockState
  return stockState === 'unknown' || stockState === 'none' ? t(`groupIndustry.purchasesToDeliver.stock.${stockState}`) : null
})

const allDelivered = computed(() => deliveries.value !== null && deliveries.value.purchaseCount > 0 && deliveries.value.rows.length === 0)

function groupKeyOf(row: PurchaseToDeliverRow): string {
  return row.originKind === 'other' ? `other-${row.solarSystemId}` : row.originKind
}

function groupTitleOf(row: PurchaseToDeliverRow): string {
  if (row.originKind === 'jita') return t('groupIndustry.purchasesToDeliver.group.jita')
  if (row.originKind === 'unknown') return t('groupIndustry.purchasesToDeliver.group.unknown')
  return t('groupIndustry.purchasesToDeliver.group.other', {
    system: row.solarSystemName ?? t('groupIndustry.purchasesToDeliver.group.unnamedSystem', { id: row.solarSystemId }),
  })
}

const groups = computed<DeliveryGroup[]>(() => {
  const byKey = new Map<string, DeliveryGroup>()
  for (const row of deliveries.value?.rows ?? []) {
    const key = groupKeyOf(row)
    const group = byKey.get(key) ?? { key, title: groupTitleOf(row), rows: [] }
    group.rows.push(row)
    byKey.set(key, group)
  }
  return [...byKey.values()]
})

function rowLabel(row: PurchaseToDeliverRow): string {
  return row.typeName ?? `#${row.typeId}`
}

function courierStateOf(row: PurchaseToDeliverRow): CourierTransportState | null {
  if (row.originKind !== 'jita') return null
  return courierStore.courier?.lines.find(line => line.bomLineId === row.bomLineId)?.transportState ?? null
}

function refetch(): void {
  void deliveriesStore.fetchDeliveries(props.projectId)
}

watch(() => props.projectId, refetch, { immediate: true })

useCoalescedGroupProjectEvents(
  () => props.projectId,
  () => {
    refetch()
  },
)

useRefetchOnReconnect(refetch)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId === props.projectId) refetch()
  },
)
</script>

<template>
  <section v-if="isVisible" data-testid="deliveries-card" class="mb-6 bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.purchasesToDeliver.title') }}
      </h2>
    </header>

    <div
      v-if="deliveriesStore.error !== null"
      data-testid="deliveries-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.purchasesToDeliver.error') }}</p>
      <p class="mt-1 break-words">{{ deliveriesStore.error }}</p>
    </div>

    <p
      v-if="deliveries === null && deliveriesStore.loading"
      data-testid="deliveries-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.purchasesToDeliver.loading') }}</p>

    <template v-else-if="deliveries !== null">
      <div
        v-if="stockNotice !== null"
        data-testid="deliveries-stock-notice"
        :data-stock-state="deliveries.stockState"
        class="m-4 p-2 flex flex-wrap items-center justify-between gap-2 rounded border border-dashed border-violet-400 text-xs text-violet-300 break-words"
      >
        <p class="min-w-0">{{ stockNotice }}</p>
        <button
          v-if="canEdit && deliveries.stockState === 'none'"
          type="button"
          data-testid="deliveries-name-container"
          class="flex-shrink-0 px-3 py-1.5 rounded-lg border border-violet-500/25 bg-violet-600/20 hover:bg-violet-600/30 text-violet-300 text-xs font-medium transition-colors"
          @click="emit('editContainers')"
        >{{ t('groupIndustry.purchasesToDeliver.nameContainer') }}</button>
      </div>

      <p
        v-if="allDelivered"
        data-testid="deliveries-all-delivered"
        class="p-8 text-center text-slate-500"
      >{{ t('groupIndustry.purchasesToDeliver.allDelivered') }}</p>

      <div
        v-for="group in groups"
        :key="group.key"
        :data-testid="`deliveries-group-${group.key}`"
        class="border-t border-slate-800 first:border-t-0"
      >
        <h3 class="px-4 pt-3 text-xs uppercase tracking-wider text-slate-500 break-words">{{ group.title }}</h3>
        <ul class="p-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <li
            v-for="row in group.rows"
            :key="`${row.bomLineId}-${group.key}`"
            :data-testid="`deliveries-row-${row.typeId}-${group.key}`"
            :data-state="row.deliveryState"
            class="flex items-center gap-2 min-w-0 p-2 rounded border bg-slate-800/30"
            :class="row.deliveryState === 'unknown' ? 'border-dashed border-violet-400/60' : 'border-slate-800'"
          >
            <img
              :src="getTypeIconUrl(row.typeId, 32)"
              alt=""
              class="w-8 h-8 rounded border border-slate-700 bg-slate-800 flex-shrink-0"
              @error="onImageError"
            />
            <div class="min-w-0 flex-1">
              <p class="truncate font-medium text-slate-100" :title="rowLabel(row)">{{ rowLabel(row) }}</p>
              <p class="text-xs text-slate-500">
                {{ t('groupIndustry.purchasesToDeliver.bought') }}
                <span class="font-mono tabular-nums text-slate-300">{{ formatNumber(row.purchasedQuantity) }}</span>
              </p>
            </div>
            <span data-testid="deliveries-missing" class="font-mono tabular-nums text-sm text-slate-100" :title="t('groupIndustry.purchasesToDeliver.missing')">
              <UnknownContributionValue v-if="row.missingQuantity === null" />
              <template v-else>{{ formatNumber(row.missingQuantity) }}</template>
            </span>
            <span
              data-testid="deliveries-delivery-state"
              class="inline-block flex-shrink-0 px-2 py-0.5 rounded border text-xs uppercase tracking-wider"
              :class="DELIVERY_CHIP_CLASSES[row.deliveryState]"
            >{{ t(`groupIndustry.purchasesToDeliver.state.${row.deliveryState}`) }}</span>
            <span
              v-if="courierStateOf(row) !== null"
              data-testid="deliveries-courier-state"
              :data-courier-state="courierStateOf(row)"
              class="inline-block flex-shrink-0 px-2 py-0.5 rounded border text-xs uppercase tracking-wider"
              :class="COURIER_CHIP_CLASSES[courierStateOf(row)!]"
              :title="t('groupIndustry.purchasesToDeliver.courier')"
            >{{ t(`groupIndustry.courier.state.${courierStateOf(row)}`) }}</span>
          </li>
        </ul>
      </div>
    </template>
  </section>
</template>
