<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import PiTierBadge from '@/components/planetary/PiTierBadge.vue'
import { useCopyFeedback } from '@/composables/useCopyFeedback'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryTiers } from '@/composables/usePlanetaryTiers'
import { usePlanetarySupplyHoldersStore } from '@/stores/planetarySupplyHolders'
import { multibuyText } from '@/utils/multibuy'
import { planStockTransfers } from '@/utils/planetarySupply'
import type { StockHolder, StockTransferRecipient, SupplyGrid, SupplyLaunchpad } from '@/utils/planetarySupply'

const ICON_SIZE = 32
const ONE_DECIMAL = 1

const props = defineProps<{
  launchpads: readonly SupplyLaunchpad[]
  days: number
  rows: SupplyGrid['rows']
}>()

const emit = defineEmits<{ 'open-stock': [] }>()
const { t } = useI18n()
const { formatNumber, formatDateTime } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const { tierOf } = usePlanetaryTiers()
const { copied, failed, copy } = useCopyFeedback()
const holdersStore = usePlanetarySupplyHoldersStore()

const copiedCharacterId = ref<number | null>(null)

const holders = computed(() => holdersStore.data)
const plan = computed(() => holders.value === null ? null : planStockTransfers(props.launchpads, props.days, holders.value))
const homeUnset = computed(() => holders.value !== null && holders.value.homeLocationId === null)
const holdersByType = computed(() => new Map((plan.value?.types ?? []).map(type => [type.typeId, type])))
const rowByKey = computed(() => new Map(props.rows.map(row => [row.key, row])))
const unknownCharacters = computed(() => (holders.value?.characters ?? []).filter(character => character.status === 'unknown'))
const staleCharacters = computed(() => (holders.value?.characters ?? []).filter(character => character.status === 'known' && character.stale))

function typeLabel(typeId: number, typeName: string | null): string {
  return typeName ?? `#${typeId}`
}

function planetLabel(planetName: string | null): string {
  return planetName ?? t('common.unknownName')
}

function holdersOfType(typeId: number): StockHolder[] {
  return holdersByType.value.get(typeId)?.holders ?? []
}

function shortfallOfType(typeId: number): number | null {
  return holdersByType.value.get(typeId)?.shortfall ?? null
}

function copyLinesOf(recipient: StockTransferRecipient): { typeName: string; quantity: number }[] {
  return recipient.lines.flatMap(line => line.transfer !== null && line.transfer > 0 && line.typeName !== null
    ? [{ typeName: line.typeName, quantity: line.transfer }]
    : [])
}

function unnamedTypeIdsOf(recipient: StockTransferRecipient): number[] {
  return recipient.lines
    .filter(line => line.transfer !== null && line.transfer > 0 && line.typeName === null)
    .map(line => line.typeId)
}

async function copyRecipient(recipient: StockTransferRecipient): Promise<void> {
  copiedCharacterId.value = recipient.characterId
  await copy(multibuyText(copyLinesOf(recipient)))
}

interface DetailRow {
  key: string
  label: string
  known: boolean
  quantities: { typeId: number; label: string; quantity: number }[]
}

function detailRowsOf(recipient: StockTransferRecipient): DetailRow[] {
  return recipient.rowKeys.flatMap(rowKey => {
    const row = rowByKey.value.get(rowKey)
    if (row === undefined) return []
    const label = [planetLabel(row.planetName), row.launchpadTypeName].filter(part => part !== null).join(' · ')
    return [{
      key: rowKey,
      label,
      known: row.refill.status === 'known',
      quantities: row.refill.lines.map(line => ({
        typeId: line.typeId,
        label: typeLabel(line.typeId, recipient.lines.find(candidate => candidate.typeId === line.typeId)?.typeName ?? null),
        quantity: line.quantity,
      })),
    }]
  })
}

onMounted(() => {
  holdersStore.fetchSupplyHolders()
})
</script>

<template>
  <section class="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="pi-transfer-block">
    <div>
      <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.supply.transfer.title') }}</h3>
      <p class="mt-1 text-sm text-slate-500">{{ t('pi.supply.transfer.help') }}</p>
    </div>

    <ErrorBanner v-if="holdersStore.error" :message="holdersStore.error" @dismiss="holdersStore.clearError()" />

    <div v-if="holdersStore.isLoading && holders === null" class="flex items-center justify-center py-8">
      <div class="flex flex-col items-center gap-3">
        <LoadingSpinner size="xl" class="text-cyan-500" />
        <span class="text-sm text-slate-400">{{ t('pi.supply.transfer.loading') }}</span>
      </div>
    </div>

    <template v-else-if="plan !== null">
      <p
        v-if="homeUnset"
        class="flex flex-wrap items-center gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-300"
        data-testid="pi-transfer-home-unset"
      >
        <span>{{ t('pi.supply.transfer.homeUnset') }}</span>
        <button
          type="button"
          class="text-xs text-cyan-300 hover:text-cyan-200 hover:underline"
          data-testid="pi-transfer-open-stock"
          @click="emit('open-stock')"
        >{{ t('pi.supply.pickup.openStock') }}</button>
      </p>

      <p
        v-for="character in unknownCharacters"
        :key="`unknown-${character.characterId}`"
        class="rounded-lg border border-slate-700 bg-slate-950/60 px-4 py-2 text-sm text-slate-300"
        data-testid="pi-transfer-assets-unknown"
        :data-character-id="character.characterId"
        :data-reason="character.unknownReason"
      >
        {{ t('pi.supply.transfer.assetsUnknown', { name: character.characterName, reason: t(`pi.supply.transfer.reasons.${character.unknownReason}`) }) }}
      </p>

      <p
        v-for="character in staleCharacters"
        :key="`stale-${character.characterId}`"
        class="rounded-lg border border-orange-500/30 bg-orange-500/10 px-4 py-2 text-sm text-orange-300"
        data-testid="pi-transfer-assets-stale"
        :data-character-id="character.characterId"
      >
        {{ t('pi.supply.transfer.assetsStale', { name: character.characterName, date: character.assetsReadAt === null ? t('pi.supply.unknown') : formatDateTime(character.assetsReadAt) }) }}
      </p>

      <article
        v-for="recipient in plan.recipients"
        :key="recipient.characterId"
        class="rounded-lg border border-slate-800 bg-slate-950/40"
        data-testid="pi-transfer-card"
        :data-character-id="recipient.characterId"
      >
        <header class="flex flex-wrap items-center gap-3 border-b border-slate-800 px-4 py-3">
          <h4 class="min-w-0 flex-1 truncate text-sm font-semibold text-cyan-400" :title="recipient.characterName" data-testid="pi-transfer-character">{{ recipient.characterName }}</h4>
          <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.supply.total') }}</span>
          <span class="font-mono-tech text-sm font-semibold text-slate-100" data-testid="pi-transfer-total">
            <template v-if="recipient.totalM3 === null">{{ t('pi.supply.unknown') }}</template>
            <template v-else>{{ formatNumber(recipient.totalM3, ONE_DECIMAL) }} m³</template>
          </span>
          <button
            type="button"
            data-testid="pi-transfer-copy"
            :disabled="copyLinesOf(recipient).length === 0"
            class="rounded-lg bg-cyan-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
            @click="copyRecipient(recipient)"
          >
            {{ copied && copiedCharacterId === recipient.characterId ? t('pi.supply.copied') : t('pi.supply.transfer.copy') }}
          </button>
        </header>

        <p v-if="failed && copiedCharacterId === recipient.characterId" class="px-4 pt-2 text-xs text-red-400">{{ t('pi.supply.copyFailed') }}</p>
        <p v-if="unnamedTypeIdsOf(recipient).length > 0" class="px-4 pt-2 text-xs text-amber-400">
          {{ t('pi.supply.unnamed', { types: unnamedTypeIdsOf(recipient).map(typeId => `#${typeId}`).join(', ') }) }}
        </p>
        <p v-if="recipient.excludedCount > 0" class="px-4 pt-2 text-xs text-slate-400" data-testid="pi-transfer-excluded">
          {{ t('pi.supply.transfer.excluded', { count: recipient.excludedCount }, recipient.excludedCount) }}
        </p>

        <ul class="divide-y divide-slate-800/60">
          <li
            v-for="line in recipient.lines"
            :key="line.typeId"
            class="flex flex-wrap items-start gap-x-6 gap-y-2 px-4 py-3"
            data-testid="pi-transfer-line"
            :data-type-id="line.typeId"
          >
            <div class="flex w-56 min-w-0 items-center gap-2">
              <img
                :src="getTypeIconUrl(line.typeId, ICON_SIZE)"
                :alt="typeLabel(line.typeId, line.typeName)"
                class="h-6 w-6 shrink-0 rounded"
                @error="onImageError"
              />
              <span class="truncate font-medium text-white" :title="typeLabel(line.typeId, line.typeName)">{{ typeLabel(line.typeId, line.typeName) }}</span>
              <PiTierBadge :tier="tierOf(line.typeId)" />
            </div>
            <dl class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
              <div>
                <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.supply.transfer.need') }}</dt>
                <dd class="font-mono-tech text-slate-300" data-testid="pi-transfer-need">{{ formatNumber(line.need, 0) }}</dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.supply.transfer.quantity') }}</dt>
                <dd class="font-mono-tech font-semibold text-cyan-400" data-testid="pi-transfer-quantity">
                  <span v-if="line.transfer === null" class="font-normal text-slate-500">{{ t('pi.supply.unknown') }}</span>
                  <template v-else>{{ formatNumber(line.transfer, 0) }}</template>
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.supply.bringBack.volume') }}</dt>
                <dd class="font-mono-tech text-slate-200" data-testid="pi-transfer-volume">
                  <span v-if="line.volumeM3 === null" class="text-slate-500">{{ t('pi.supply.unknown') }}</span>
                  <template v-else>{{ formatNumber(line.volumeM3, ONE_DECIMAL) }} m³</template>
                </dd>
              </div>
              <div v-if="!homeUnset">
                <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.supply.pickup.shortfall') }}</dt>
                <dd class="font-mono-tech" data-testid="pi-transfer-shortfall">
                  <span v-if="shortfallOfType(line.typeId) === null" class="text-slate-500">{{ t('pi.supply.unknown') }}</span>
                  <span v-else :class="shortfallOfType(line.typeId) === 0 ? 'text-slate-200' : 'font-semibold text-orange-400'">{{ formatNumber(shortfallOfType(line.typeId), 0) }}</span>
                </dd>
              </div>
            </dl>
            <ul class="min-w-48 flex-1 space-y-1 text-sm">
              <li class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.supply.transfer.holders') }}</li>
              <li v-if="holdersOfType(line.typeId).length === 0" class="text-slate-500">{{ t('pi.supply.transfer.noHolder') }}</li>
              <li
                v-for="holder in holdersOfType(line.typeId)"
                :key="holder.characterId"
                class="flex items-baseline gap-3"
                data-testid="pi-transfer-holder"
                :data-character-id="holder.characterId"
              >
                <span class="min-w-0 flex-1 truncate text-slate-300" :title="holder.characterName">{{ holder.characterName }}</span>
                <span v-if="holder.available === null" class="text-xs text-slate-500">{{ t('pi.supply.unknown') }}</span>
                <span v-else class="font-mono-tech text-slate-200">{{ formatNumber(holder.available, 0) }}</span>
              </li>
            </ul>
          </li>
        </ul>

        <details class="border-t border-slate-800 px-4 py-2 text-sm" data-testid="pi-transfer-details">
          <summary class="cursor-pointer text-xs uppercase tracking-wider text-slate-400 hover:text-slate-200">{{ t('pi.supply.transfer.details') }}</summary>
          <ul class="mt-2 space-y-1">
            <li
              v-for="detailRow in detailRowsOf(recipient)"
              :key="detailRow.key"
              class="flex flex-wrap items-baseline gap-x-4"
              data-testid="pi-transfer-detail-row"
            >
              <span class="min-w-0 flex-1 truncate text-slate-300" :title="detailRow.label">{{ detailRow.label }}</span>
              <span v-if="!detailRow.known" class="font-mono-tech text-xs text-slate-500">{{ t('pi.supply.unknown') }}</span>
              <template v-else>
                <span
                  v-for="quantity in detailRow.quantities"
                  :key="quantity.typeId"
                  class="font-mono-tech text-xs text-slate-400"
                >{{ quantity.label }} {{ formatNumber(quantity.quantity, 0) }}</span>
              </template>
            </li>
          </ul>
        </details>
      </article>
    </template>
  </section>
</template>
