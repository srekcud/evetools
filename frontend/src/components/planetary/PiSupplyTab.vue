<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import PiSupplyPendingBlocks from '@/components/planetary/PiSupplyPendingBlocks.vue'
import PiTierBadge from '@/components/planetary/PiTierBadge.vue'
import { useCopyFeedback } from '@/composables/useCopyFeedback'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryTiers } from '@/composables/usePlanetaryTiers'
import { usePlanetaryStore } from '@/stores/planetary'
import { usePlanetarySupplyStore } from '@/stores/planetarySupply'
import { multibuyText } from '@/utils/multibuy'
import { DEFAULT_SUPPLY_DAYS, MAX_SUPPLY_DAYS, buildSupplyGrid, isValidSupplyDays } from '@/utils/planetarySupply'

const ICON_SIZE = 32
const ONE_DECIMAL = 1

const emit = defineEmits<{ 'open-stock': [] }>()
const { t, locale } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const { copied, failed, copy } = useCopyFeedback()
const planetaryStore = usePlanetaryStore()
const supplyStore = usePlanetarySupplyStore()
const { tierOf, ensureCatalog } = usePlanetaryTiers()

const daysInput = ref<string | number>(DEFAULT_SUPPLY_DAYS)

const rawDays = computed(() => String(daysInput.value).trim())
const days = computed(() => Number(rawDays.value))
const isDaysValid = computed(() => rawDays.value !== '' && isValidSupplyDays(days.value))

const launchpads = computed(() => supplyStore.data?.launchpads ?? [])
const hasFedLaunchpad = computed(() => launchpads.value.some(launchpad => launchpad.feeds.length > 0))
const grid = computed(() => buildSupplyGrid(launchpads.value, isDaysValid.value ? days.value : Number.NaN))

const totalByType = computed(() => new Map(grid.value.totals.map(total => [total.typeId, total.quantity])))
const typeNameById = computed(() => new Map(grid.value.columns.map(column => [column.typeId, column.typeName])))

const copyLines = computed(() => grid.value.totals
  .filter(total => total.quantity > 0)
  .flatMap(total => {
    const typeName = typeNameById.value.get(total.typeId)
    return typeName === null || typeName === undefined ? [] : [{ typeName, quantity: total.quantity }]
  }))

const unnamedTypeIds = computed(() => grid.value.totals
  .filter(total => total.quantity > 0 && typeNameById.value.get(total.typeId) === null)
  .map(total => total.typeId))

const limitedRows = computed(() => grid.value.rows.filter(row => row.refill.status === 'known' && row.refill.limited))

const showGrid = computed(() => supplyStore.error === null && isDaysValid.value && hasFedLaunchpad.value)

function typeLabel(typeId: number, typeName: string | null): string {
  return typeName ?? `#${typeId}`
}

function planetLabel(planetName: string | null): string {
  return planetName ?? t('common.unknownName')
}

function formatDecimal(value: number): string {
  return value.toLocaleString(locale.value, { minimumFractionDigits: ONE_DECIMAL, maximumFractionDigits: ONE_DECIMAL })
}

function quantityOf(lines: readonly { typeId: number; quantity: number }[], typeId: number): number | undefined {
  return lines.find(line => line.typeId === typeId)?.quantity
}

function autonomyClass(limited: boolean): string {
  return limited ? 'text-orange-400' : 'text-slate-300'
}

function copyTotals(): Promise<void> {
  return copy(multibuyText(copyLines.value))
}

onMounted(() => {
  supplyStore.fetchInputNeeds()
  ensureCatalog()
})

watch(() => planetaryStore.lastSyncAt, () => supplyStore.fetchInputNeeds())
</script>

<template>
  <div class="space-y-4" data-testid="pi-supply-tab">
    <ErrorBanner v-if="supplyStore.error" :message="supplyStore.error" @dismiss="supplyStore.clearError()" />

    <section class="rounded-xl border border-slate-800 bg-slate-900 p-5">
      <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.supply.title') }}</h3>
      <p class="mt-1 text-sm text-slate-500">{{ t('pi.supply.help') }}</p>

      <div class="mt-4 flex flex-wrap items-end gap-4">
        <label class="block">
          <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.supply.daysLabel') }}</span>
          <input
            v-model="daysInput"
            type="number"
            min="0"
            :max="MAX_SUPPLY_DAYS"
            step="any"
            data-testid="pi-supply-days"
            :class="[
              'w-28 rounded-md border bg-slate-800 px-3 py-1.5 font-mono-tech text-sm text-white focus:outline-hidden',
              isDaysValid ? 'border-slate-700 focus:border-cyan-500' : 'border-red-500/60 focus:border-red-400',
            ]"
          />
        </label>
        <button
          type="button"
          data-testid="pi-supply-copy"
          :disabled="copyLines.length === 0"
          class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
          @click="copyTotals"
        >
          {{ copied ? t('pi.supply.copied') : t('pi.supply.copy') }}
        </button>
      </div>

      <p v-if="!isDaysValid" class="mt-2 text-xs text-red-400" data-testid="pi-supply-days-invalid">
        {{ t('pi.supply.daysInvalid', { max: MAX_SUPPLY_DAYS }) }}
      </p>
      <p v-if="failed" class="mt-2 text-xs text-red-400">{{ t('pi.supply.copyFailed') }}</p>
      <p v-if="unnamedTypeIds.length > 0" class="mt-2 text-xs text-amber-400" data-testid="pi-supply-unnamed">
        {{ t('pi.supply.unnamed', { types: unnamedTypeIds.map(typeId => `#${typeId}`).join(', ') }) }}
      </p>
    </section>

    <div v-if="supplyStore.isLoading && supplyStore.data === null" class="flex items-center justify-center py-16">
      <div class="flex flex-col items-center gap-4">
        <LoadingSpinner size="xl" class="text-cyan-500" />
        <span class="text-sm text-slate-400">{{ t('pi.supply.loading') }}</span>
      </div>
    </div>

    <template v-else-if="supplyStore.data !== null">
      <p
        v-if="!supplyStore.data.complete"
        class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-300"
        data-testid="pi-supply-incomplete"
      >
        {{ t('pi.supply.incomplete') }}
      </p>

      <div
        v-if="!hasFedLaunchpad"
        class="rounded-xl border border-slate-800 bg-slate-900 px-6 py-12 text-center text-sm text-slate-400"
        data-testid="pi-supply-empty"
      >
        {{ t('pi.supply.empty') }}
      </div>

      <template v-else-if="showGrid">
        <p
          v-if="grid.excludedCount > 0"
          class="rounded-lg border border-slate-700 bg-slate-900 px-4 py-2 text-sm text-slate-300"
          data-testid="pi-supply-excluded"
        >
          {{ t('pi.supply.excluded', { count: grid.excludedCount }, grid.excludedCount) }}
        </p>

        <p
          v-for="row in limitedRows"
          :key="`warning-${row.key}`"
          class="rounded-lg border border-orange-500/30 bg-orange-500/10 px-4 py-2 text-sm text-orange-300"
          data-testid="pi-supply-capacity-warning"
        >
          {{ t('pi.supply.capacityWarning', {
            planet: planetLabel(row.planetName),
            capacityDays: row.refill.capacityDays === null ? '' : formatDecimal(row.refill.capacityDays),
            days: formatNumber(days),
          }) }}
        </p>

        <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
          <table class="w-full text-sm" data-testid="pi-supply-grid">
            <thead>
              <tr class="border-b border-slate-800 text-left text-xs uppercase tracking-wider text-slate-500">
                <th class="px-4 py-3 font-medium">{{ t('pi.supply.launchpad') }}</th>
                <th
                  v-for="column in grid.columns"
                  :key="column.typeId"
                  class="max-w-40 px-3 py-3 text-right font-medium"
                >
                  <div class="flex items-center justify-end gap-2">
                    <img
                      :src="getTypeIconUrl(column.typeId, ICON_SIZE)"
                      :alt="typeLabel(column.typeId, column.typeName)"
                      class="h-6 w-6 shrink-0 rounded"
                      @error="onImageError"
                    />
                    <span class="truncate" :title="typeLabel(column.typeId, column.typeName)">{{ typeLabel(column.typeId, column.typeName) }}</span>
                    <PiTierBadge :tier="tierOf(column.typeId)" />
                  </div>
                </th>
                <th class="px-3 py-3 text-right font-medium">{{ t('pi.supply.bring') }}</th>
                <th class="px-3 py-3 text-right font-medium">{{ t('pi.supply.autonomy') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in grid.rows"
                :key="row.key"
                class="border-b border-slate-800/60"
                data-testid="pi-supply-row"
              >
                <td class="max-w-56 px-4 py-2">
                  <div class="truncate font-medium text-white" :title="planetLabel(row.planetName)">{{ planetLabel(row.planetName) }}</div>
                  <div class="truncate text-xs text-slate-500" :title="row.launchpadTypeName ?? ''">{{ row.launchpadTypeName }}</div>
                </td>
                <td
                  v-for="column in grid.columns"
                  :key="column.typeId"
                  class="px-3 py-2 text-right font-mono-tech text-slate-200"
                  data-testid="pi-supply-cell"
                  :data-type-id="column.typeId"
                >
                  <template v-if="row.consumedTypeIds.includes(column.typeId)">
                    <span v-if="row.refill.status === 'unknown'" class="text-slate-500" data-testid="pi-supply-unknown">{{ t('pi.supply.unknown') }}</span>
                    <template v-else>{{ formatNumber(quantityOf(row.refill.lines, column.typeId), 0) }}</template>
                  </template>
                </td>
                <td class="px-3 py-2 text-right font-mono-tech text-slate-200" data-testid="pi-supply-bring">
                  <span v-if="row.refill.bringM3 === null" class="text-slate-500" data-testid="pi-supply-unknown">{{ t('pi.supply.unknown') }}</span>
                  <template v-else>{{ formatNumber(row.refill.bringM3, ONE_DECIMAL) }} m³</template>
                </td>
                <td
                  class="px-3 py-2 text-right font-mono-tech"
                  :class="autonomyClass(row.refill.limited)"
                  data-testid="pi-supply-autonomy"
                >
                  <span v-if="row.refill.status === 'unknown'" class="text-slate-500" data-testid="pi-supply-unknown">{{ t('pi.supply.unknown') }}</span>
                  <template v-else-if="row.refill.autonomyDays === null">{{ t('pi.supply.noConsumption') }}</template>
                  <template v-else>{{ t('pi.supply.daysShort', { value: formatDecimal(row.refill.autonomyDays) }) }}</template>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="bg-slate-950/60 text-slate-100">
                <td class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-cyan-400">{{ t('pi.supply.total') }}</td>
                <td
                  v-for="column in grid.columns"
                  :key="column.typeId"
                  class="px-3 py-3 text-right font-mono-tech font-semibold"
                  data-testid="pi-supply-total-cell"
                  :data-type-id="column.typeId"
                >
                  <template v-if="totalByType.has(column.typeId)">{{ formatNumber(totalByType.get(column.typeId), 0) }}</template>
                </td>
                <td class="px-3 py-3 text-right font-mono-tech font-semibold" data-testid="pi-supply-total-bring">
                  {{ formatNumber(grid.totalM3, ONE_DECIMAL) }} m³
                </td>
                <td class="px-3 py-3"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>
    </template>

    <PiSupplyPendingBlocks :totals="grid.totals" :type-names="typeNameById" @open-stock="emit('open-stock')" />
  </div>
</template>
