<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ContainerStockStation, ContainerVerification, StationQuantity, TypeStationQuantities } from '@/stores/group-industry/types'

const props = defineProps<{
  verification: ContainerVerification
  quantities: TypeStationQuantities | null
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()

const holdings = computed<ContainerStockStation[]>(() => props.verification.stockStations ?? [])

const jobStations = computed<StationQuantity[]>(() =>
  (props.quantities?.stations ?? []).filter(station => station.needed - station.consumed > 0),
)

function stationLabel(stationName: string | null): string {
  return stationName ?? t('groupIndustry.container.unknownStation')
}

function heldAt(locationId: number): number {
  return holdings.value
    .filter(holding => holding.locationId === locationId)
    .reduce((total, holding) => total + holding.quantity, 0)
}

const hasUnresolvedHolding = computed(() => holdings.value.some(holding => holding.locationId === null))

const lackingStations = computed(() => {
  if (holdings.value.length === 0 || props.quantities === null || hasUnresolvedHolding.value) return []
  const lacking: { station: StationQuantity; missing: number }[] = []
  for (const station of jobStations.value) {
    if (station.locationId === null) continue
    const missing = station.needed - station.consumed - heldAt(station.locationId)
    const heldElsewhere = holdings.value.some(holding => holding.locationId !== station.locationId)
    if (missing > 0 && heldElsewhere) lacking.push({ station, missing })
  }
  return lacking
})

const showStock = computed(() => {
  if (holdings.value.length === 0) return false
  const [only] = holdings.value
  const allAtJobStation = holdings.value.length === 1
    && only.locationId !== null
    && jobStations.value.length > 0
    && jobStations.value.every(station => station.locationId === only.locationId)
  return !allAtJobStation
})

const stockText = computed(() => t('groupIndustry.container.stockByStation', {
  stations: holdings.value.map(holding => `${stationLabel(holding.stationName)} ${formatNumber(holding.quantity, 0)}`).join(' · '),
}))

const lackingText = computed(() => t('groupIndustry.container.notAtJobStation', {
  stations: lackingStations.value
    .map(({ station, missing }) => t('groupIndustry.container.stationStillNeeds', { station: stationLabel(station.stationName), quantity: formatNumber(missing, 0) }))
    .join(' · '),
}))
</script>

<template>
  <div v-if="showStock || lackingStations.length > 0">
    <p v-if="showStock" data-testid="container-line-stations" class="mt-0.5 text-xs text-slate-400">{{ stockText }}</p>
    <p v-if="lackingStations.length > 0" data-testid="container-line-stations-warning" class="mt-0.5 text-xs text-amber-300">{{ lackingText }}</p>
  </div>
</template>
