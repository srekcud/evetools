<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryStore } from '@/stores/planetary'
import { usePlanetaryPendingStockStore } from '@/stores/planetaryPendingStock'
import { buildPickupPlan, sortBringBack } from '@/utils/planetaryPickup'
import type { PendingOutput } from '@/utils/planetaryPickup'

const ICON_SIZE = 32
const ONE_DECIMAL = 1
const SECONDS_PER_HOUR = 3600

const props = defineProps<{
  totals: readonly { typeId: number; quantity: number }[]
  typeNames: ReadonlyMap<number, string | null>
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const planetaryStore = usePlanetaryStore()
const pendingStore = usePlanetaryPendingStockStore()

const outputs = computed(() => pendingStore.data?.pendingOutputs ?? [])
const customsOfficeStocks = computed(() => pendingStore.data?.customsOfficeStocks ?? [])
const pickupRows = computed(() => buildPickupPlan(props.totals, props.typeNames, outputs.value, customsOfficeStocks.value))
const bringBackRows = computed(() => sortBringBack(outputs.value))
const hasUnknownSource = computed(() => pickupRows.value.some(row => row.unknownSources.length > 0))

function typeLabel(typeId: number, typeName: string | null): string {
  return typeName ?? `#${typeId}`
}

function planetLabel(planetName: string | null): string {
  return planetName ?? t('common.unknownName')
}

function ageHours(dataAgeSeconds: number): number {
  return Math.floor(dataAgeSeconds / SECONDS_PER_HOUR)
}

function staleClass(dataStale: boolean): string {
  return dataStale ? 'text-orange-400' : 'text-slate-200'
}

function destinationLabel(output: PendingOutput): string {
  return output.destination === 'colony'
    ? t('pi.supply.bringBack.to', { planets: output.consumers.map(consumer => consumer.planetName).join(', ') })
    : t('pi.supply.bringBack.unplaced')
}

onMounted(() => pendingStore.fetchPendingStock())

watch(() => planetaryStore.lastSyncAt, () => pendingStore.fetchPendingStock())
</script>

<template>
  <div class="space-y-4" data-testid="pi-supply-pending">
    <ErrorBanner v-if="pendingStore.error" :message="pendingStore.error" @dismiss="pendingStore.clearError()" />

    <div v-if="pendingStore.isLoading && pendingStore.data === null" class="flex items-center justify-center py-10">
      <div class="flex flex-col items-center gap-4">
        <LoadingSpinner size="xl" class="text-cyan-500" />
        <span class="text-sm text-slate-400">{{ t('pi.supply.pickup.loading') }}</span>
      </div>
    </div>

    <template v-else-if="pendingStore.data !== null">
      <p
        v-if="!pendingStore.data.complete"
        class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-300"
        data-testid="pi-pending-incomplete"
      >
        {{ t('pi.supply.pickup.incomplete') }}
      </p>

      <section v-if="pickupRows.length > 0" class="rounded-xl border border-slate-800 bg-slate-900" data-testid="pi-pickup">
        <div class="p-5 pb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.supply.pickup.title') }}</h3>
          <p class="mt-1 text-sm text-slate-500">{{ t('pi.supply.pickup.help') }}</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-800 text-left text-xs uppercase tracking-wider text-slate-500">
                <th class="px-4 py-3 font-medium">{{ t('pi.supply.pickup.product') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('pi.supply.pickup.needed') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('pi.supply.pickup.amount') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('pi.supply.pickup.remaining') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('pi.supply.pickup.sources') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in pickupRows"
                :key="row.typeId"
                class="border-b border-slate-800/60 align-top"
                data-testid="pi-pickup-row"
                :data-type-id="row.typeId"
              >
                <td class="max-w-56 px-4 py-2">
                  <div class="flex items-center gap-2">
                    <img
                      :src="getTypeIconUrl(row.typeId, ICON_SIZE)"
                      :alt="typeLabel(row.typeId, row.typeName)"
                      class="h-6 w-6 shrink-0 rounded"
                      @error="onImageError"
                    />
                    <span class="truncate font-medium text-white" :title="typeLabel(row.typeId, row.typeName)">{{ typeLabel(row.typeId, row.typeName) }}</span>
                  </div>
                </td>
                <td class="px-3 py-2 text-right font-mono-tech text-slate-200" data-testid="pi-pickup-needed">{{ formatNumber(row.needed, 0) }}</td>
                <td class="px-3 py-2 text-right font-mono-tech font-semibold text-cyan-400" data-testid="pi-pickup-amount">{{ formatNumber(row.pickup, 0) }}</td>
                <td class="px-3 py-2 text-right font-mono-tech text-slate-200" data-testid="pi-pickup-remaining">{{ formatNumber(row.remaining, 0) }}</td>
                <td class="px-4 py-2">
                  <ul class="space-y-1">
                    <li
                      v-for="source in row.sources"
                      :key="source.colonyId"
                      class="flex items-baseline gap-3"
                      data-testid="pi-pickup-source"
                    >
                      <span class="min-w-0 flex-1 truncate text-slate-300" :title="planetLabel(source.planetName)" data-testid="pi-pickup-source-planet">{{ planetLabel(source.planetName) }}</span>
                      <span class="font-mono-tech" :class="staleClass(source.dataStale)" data-testid="pi-pickup-source-quantity">{{ formatNumber(source.quantity, 0) }}</span>
                      <span class="font-mono-tech text-xs text-slate-500" data-testid="pi-pickup-source-volume">
                        <template v-if="source.volumeM3 === null">{{ t('pi.supply.bringBack.unknown') }}</template>
                        <template v-else>{{ formatNumber(source.volumeM3, ONE_DECIMAL) }} m³</template>
                      </span>
                    </li>
                    <li
                      v-for="source in row.unknownSources"
                      :key="source.colonyId"
                      class="flex items-baseline gap-3 text-slate-500"
                      data-testid="pi-pickup-unknown-source"
                    >
                      <span class="min-w-0 flex-1 truncate" :title="planetLabel(source.planetName)">{{ planetLabel(source.planetName) }}</span>
                      <span class="font-mono-tech text-xs">{{ t('pi.supply.pickup.unknownSource') }}</span>
                    </li>
                  </ul>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="hasUnknownSource" class="px-5 py-3 text-xs text-slate-500" data-testid="pi-pickup-unknown-note">
          {{ t('pi.supply.pickup.unknownNote') }}
        </p>
      </section>

      <section class="rounded-xl border border-slate-800 bg-slate-900" data-testid="pi-bringback">
        <div class="p-5 pb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.supply.bringBack.title') }}</h3>
        </div>
        <div
          v-if="bringBackRows.length === 0"
          class="px-6 py-10 text-center text-sm text-slate-400"
          data-testid="pi-bringback-empty"
        >
          {{ t('pi.supply.bringBack.empty') }}
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-800 text-left text-xs uppercase tracking-wider text-slate-500">
                <th class="px-4 py-3 font-medium">{{ t('pi.supply.bringBack.planet') }}</th>
                <th class="px-3 py-3 font-medium">{{ t('pi.supply.bringBack.product') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('pi.supply.bringBack.quantity') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('pi.supply.bringBack.volume') }}</th>
                <th class="px-4 py-3 font-medium">{{ t('pi.supply.bringBack.destination') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="output in bringBackRows"
                :key="`${output.colonyId}-${output.typeId}`"
                class="border-b border-slate-800/60"
                data-testid="pi-bringback-row"
              >
                <td class="max-w-56 px-4 py-2">
                  <div class="truncate font-medium text-white" :title="planetLabel(output.planetName)" data-testid="pi-bringback-planet">{{ planetLabel(output.planetName) }}</div>
                  <div v-if="output.solarSystemName" class="truncate text-xs text-slate-500" :title="output.solarSystemName">{{ output.solarSystemName }}</div>
                </td>
                <td class="max-w-56 px-3 py-2">
                  <div class="flex items-center gap-2">
                    <img
                      :src="getTypeIconUrl(output.typeId, ICON_SIZE)"
                      :alt="typeLabel(output.typeId, output.typeName)"
                      class="h-6 w-6 shrink-0 rounded"
                      @error="onImageError"
                    />
                    <span class="truncate text-slate-200" :title="typeLabel(output.typeId, output.typeName)">{{ typeLabel(output.typeId, output.typeName) }}</span>
                  </div>
                </td>
                <td
                  class="px-3 py-2 text-right font-mono-tech"
                  :class="staleClass(output.dataStale)"
                  data-testid="pi-bringback-quantity"
                >
                  <span v-if="output.quantity === null" class="text-slate-500" data-testid="pi-bringback-unknown">{{ t('pi.supply.bringBack.unknown') }}</span>
                  <template v-else>{{ formatNumber(output.quantity, 0) }}</template>
                  <span v-if="output.dataStale" class="ml-1 text-xs" data-testid="pi-bringback-age">{{ t('pi.supply.bringBack.age', { hours: ageHours(output.dataAgeSeconds) }) }}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono-tech text-slate-200" data-testid="pi-bringback-volume">
                  <span v-if="output.volumeM3 === null" class="text-slate-500">{{ t('pi.supply.bringBack.unknown') }}</span>
                  <template v-else>{{ formatNumber(output.volumeM3, ONE_DECIMAL) }} m³</template>
                </td>
                <td
                  class="max-w-72 px-4 py-2"
                  :class="output.destination === 'colony' ? 'text-slate-200' : 'text-slate-400'"
                  data-testid="pi-bringback-destination"
                  :data-destination="output.destination"
                >
                  <div class="line-clamp-2" :title="destinationLabel(output)">{{ destinationLabel(output) }}</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
