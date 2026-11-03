<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningFleetStorageDeclaration, MiningOperationValuation, MiningValuationParticipant } from '@/stores/miningOperation'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { formatPercent, formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock, formatEveDateTime } from '@/views/MiningOperation/miningOperationTime'

const FULL_PERCENT = 100

const props = defineProps<{
  valuation: MiningOperationValuation
  title: string
  showShares: boolean
  ownMemberId: string | null
  marketLabel: string
  declarations: MiningFleetStorageDeclaration[]
}>()

const emit = defineEmits<{
  reread: []
}>()

const { t, locale } = useI18n()

const expandedKeys = ref<string[]>([])

const participants = computed(() => props.valuation.participants)
const sharesTotal = computed(() => sumOrNull(participants.value.map(participant => participant.share)))
const quantityTotal = computed(() =>
  participants.value.some(participant => participant.quantityState !== 'known') ? null : sumOrNull(participants.value.map(participant => participant.totalQuantity)),
)
const minedValueTotal = computed(() => props.valuation.totalValue)
const depositorsCount = computed(() => participants.value.filter(participant => participant.fleetStorageDelivery === 'all' || participant.fleetStorageDelivery === 'partial').length)
const recipientName = computed(() => props.valuation.fleetStorageRecipientName ?? t('miningOperation.unknownPilot'))
const syncedText = computed(() => (props.valuation.pricesSyncedAt === null ? t('miningOperation.quantities.unknown') : formatEveClock(props.valuation.pricesSyncedAt)))
const pricesFootnote = computed(() => {
  if (props.valuation.frozen && props.valuation.sharesFrozenAt !== null) {
    return t('miningOperation.shares.pricesFrozen', { date: formatEveDateTime(props.valuation.sharesFrozenAt), market: props.marketLabel, synced: syncedText.value })
  }
  const readAt = props.valuation.pricesReadAt
  return t('miningOperation.shares.pricesRead', { time: readAt === null ? t('miningOperation.quantities.unknown') : formatEveClock(readAt), market: props.marketLabel, synced: syncedText.value })
})
const checkText = computed(() => {
  const valuation = props.valuation
  if (valuation.totalValue === null) return t('miningOperation.shares.checkUnknown')
  if (!props.showShares) return t('miningOperation.shares.checkEachForThemselves', { total: formatWhole(valuation.totalValue, locale.value) })
  if (sharesTotal.value === null || valuation.boosterCut === null || valuation.corporationCut === null) return t('miningOperation.shares.checkUnknown')
  const sum = t('miningOperation.shares.check', {
    shares: formatWhole(sharesTotal.value, locale.value),
    booster: formatWhole(valuation.boosterCut, locale.value),
    corporation: formatWhole(valuation.corporationCut, locale.value),
    total: formatWhole(valuation.totalValue, locale.value),
  })
  return valuation.roundingRemainder === null
    ? sum
    : `${sum} · ${t('miningOperation.shares.checkRemainder', { remainder: valuation.roundingRemainder })}`
})

function sumOrNull(values: (number | null)[]): number | null {
  return values.some(value => value === null) ? null : values.reduce<number>((sum, value) => sum + (value ?? 0), 0)
}

function keyOf(participant: MiningValuationParticipant, index: number): string {
  return participant.memberId ?? `frozen-${index}`
}

function isExpanded(key: string): boolean {
  return expandedKeys.value.includes(key)
}

function toggle(key: string): void {
  expandedKeys.value = isExpanded(key) ? expandedKeys.value.filter(candidate => candidate !== key) : [...expandedKeys.value, key]
}

function nameOf(participant: MiningValuationParticipant): string {
  return participant.characterName ?? t('miningOperation.unknownPilot')
}

function amountOf(value: number | null): string {
  return value === null ? t('miningOperation.quantities.unknown') : formatWhole(value, locale.value)
}

function amountClass(value: number | null): string {
  return value === null ? 'text-amber-300' : 'text-slate-100'
}

function quantityOf(state: MiningValuationParticipant['quantityState'], totalQuantity: number | null): string {
  if (state === 'measuring') return t('miningOperation.quantities.measuring')
  if (state !== 'known' || totalQuantity === null) return t('miningOperation.quantities.unknown')
  return formatWhole(totalQuantity, locale.value)
}

function quantityClass(state: MiningValuationParticipant['quantityState']): string {
  if (state === 'measuring') return 'text-slate-400'
  return state === 'known' ? 'text-slate-100' : 'text-amber-300'
}

function deliveredQuantitiesOf(participant: MiningValuationParticipant): string {
  const declaration = props.declarations.find(candidate => candidate.memberId === participant.memberId)
  return (declaration?.quantities ?? []).map(line => `${line.typeName ?? line.typeId} ${formatWhole(line.quantity, locale.value)} u`).join(', ')
}

function percentOf(participant: MiningValuationParticipant): string {
  return participant.valuePercent === null ? t('miningOperation.quantities.unknown') : `${formatPercent(participant.valuePercent, locale.value)} %`
}

function rateOf(participant: MiningValuationParticipant): string {
  return participant.iskPerHour === null ? t('miningOperation.quantities.unknown') : formatWhole(participant.iskPerHour, locale.value)
}
</script>

<template>
  <section class="eve-card p-5">
    <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ title }}</h2>

    <div class="overflow-x-auto">
      <table class="w-full min-w-[46rem] table-fixed text-sm">
        <thead>
          <tr class="border-b border-slate-700 text-left font-mono text-xs uppercase tracking-wider text-slate-400">
            <th class="w-2/5 py-2 pr-2 font-normal">{{ t('miningOperation.shares.columns.participant') }}</th>
            <th class="py-2 pr-2 text-right font-normal">{{ t('miningOperation.shares.columns.quantity') }}</th>
            <th class="py-2 pr-2 text-right font-normal">{{ t('miningOperation.shares.columns.minedValue') }}</th>
            <th v-if="showShares" class="py-2 pr-2 text-right font-normal">{{ t('miningOperation.shares.columns.valuePercent') }}</th>
            <th v-if="showShares" class="py-2 pr-2 text-right font-normal">{{ t('miningOperation.shares.columns.share') }}</th>
            <th class="py-2 pr-2 text-right font-normal">{{ t('miningOperation.shares.columns.iskPerHour') }}</th>
            <th class="py-2 font-normal">{{ t('miningOperation.shares.columns.holder') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(participant, index) in participants" :key="keyOf(participant, index)">
            <tr class="border-b border-slate-800 align-top">
              <td class="py-2 pr-2">
                <div class="flex min-w-0 items-center gap-1.5">
                  <button
                    v-if="participant.characters.length > 0"
                    type="button"
                    class="shrink-0 text-slate-500 hover:text-cyan-300"
                    :aria-expanded="isExpanded(keyOf(participant, index))"
                    :aria-label="t('miningOperation.shares.showCharacters', { name: nameOf(participant) })"
                    @click="toggle(keyOf(participant, index))"
                  ><span aria-hidden="true">{{ isExpanded(keyOf(participant, index)) ? '▾' : '▸' }}</span></button>
                  <span class="min-w-0 truncate font-display text-base font-semibold text-slate-100" :title="nameOf(participant)">{{ nameOf(participant) }}</span>
                  <span v-if="participant.isBooster" class="shrink-0 rounded-sm border border-amber-500/40 bg-amber-500/10 px-1.5 py-0.5 font-mono text-xs text-amber-300">{{ t('miningOperation.shares.boosterBadge') }}</span>
                  <span v-if="participant.memberId !== null && participant.memberId === ownMemberId" class="shrink-0 rounded-sm border border-cyan-500/40 bg-cyan-500/10 px-1.5 py-0.5 font-mono text-xs text-cyan-300">{{ t('miningOperation.detail.you') }}</span>
                  <span v-if="participant.departedAt !== null" class="shrink-0 font-mono text-xs text-slate-400">{{ t('miningOperation.shares.left', { time: formatEveClock(participant.departedAt) }) }}</span>
                </div>
              </td>
              <td class="py-2 pr-2 text-right font-mono" :class="quantityClass(participant.quantityState)">{{ quantityOf(participant.quantityState, participant.totalQuantity) }}</td>
              <td class="py-2 pr-2 text-right font-mono" :class="amountClass(participant.minedValue)">{{ amountOf(participant.minedValue) }}</td>
              <td v-if="showShares" class="py-2 pr-2 text-right font-mono" :class="amountClass(participant.valuePercent)">{{ percentOf(participant) }}</td>
              <td v-if="showShares" class="py-2 pr-2 text-right font-mono" :class="amountClass(participant.share)">
                <span>{{ amountOf(participant.share) }}</span>
                <span v-if="participant.roundingAdjustment === 1" class="ml-1 text-xs text-cyan-300" :title="t('miningOperation.shares.roundingMark')">+1</span>
                <span v-if="participant.isBooster && valuation.boosterCut !== null" class="block text-xs text-amber-300">
                  {{ t('miningOperation.shares.plusBooster', { amount: formatWhole(valuation.boosterCut, locale) }) }}
                  <span v-if="valuation.boosterRoundingAdjustment === 1" class="text-cyan-300" :title="t('miningOperation.shares.roundingMark')">+1</span>
                </span>
              </td>
              <td class="py-2 pr-2 text-right font-mono" :class="amountClass(participant.iskPerHour)">{{ rateOf(participant) }}</td>
              <td class="min-w-0 py-2 text-xs">
                <span v-if="participant.isFleetStorageRecipient" class="rounded-sm border border-cyan-500/40 bg-cyan-500/10 px-1.5 py-0.5 font-mono text-cyan-300">{{ t('miningOperation.fleetStorage.holder.recipient') }}</span>
                <UnknownValue v-else-if="participant.fleetStorageDelivery === null" />
                <span v-else-if="participant.fleetStorageDelivery === 'none'" class="text-slate-400">{{ t('miningOperation.fleetStorage.holder.self') }}</span>
                <template v-else>
                  <span class="flex min-w-0 items-center gap-1.5">
                    <span class="min-w-0 truncate text-sm text-slate-100" :title="recipientName">{{ recipientName }}</span>
                    <span class="shrink-0 rounded-sm border border-cyan-500/40 bg-cyan-500/10 px-1.5 py-0.5 font-mono text-cyan-300">{{ t('miningOperation.fleetStorage.holder.recipient') }}</span>
                  </span>
                  <span v-if="participant.fleetStorageDelivery === 'all'" class="block text-slate-400">{{ t('miningOperation.fleetStorage.holder.all') }}</span>
                  <span v-else-if="deliveredQuantitiesOf(participant) !== ''" class="block text-slate-400">{{ t('miningOperation.fleetStorage.holder.partial', { quantities: deliveredQuantitiesOf(participant) }) }}</span>
                </template>
              </td>
            </tr>
            <template v-if="isExpanded(keyOf(participant, index))">
              <tr v-for="character in participant.characters" :key="character.id" class="border-b border-slate-800/60 text-xs text-slate-400">
                <td class="py-1 pl-6 pr-2"><span class="block truncate" :title="character.name">{{ character.name }}</span></td>
                <td class="py-1 pr-2 text-right font-mono">{{ quantityOf(character.quantityState, character.totalQuantity) }}</td>
                <td class="py-1 pr-2 text-right font-mono" :class="character.minedValue === null ? 'text-amber-300' : ''">{{ amountOf(character.minedValue) }}</td>
                <td v-if="showShares" colspan="2" />
                <td colspan="2" />
              </tr>
            </template>
          </template>
        </tbody>
        <tfoot>
          <tr class="font-mono text-slate-100">
            <th class="py-2 pr-2 text-left font-display font-semibold">{{ t('miningOperation.shares.total') }}</th>
            <td class="py-2 pr-2 text-right" :class="quantityTotal === null ? 'text-amber-300' : ''">{{ quantityTotal === null ? t('miningOperation.quantities.unknown') : formatWhole(quantityTotal, locale) }}</td>
            <td class="py-2 pr-2 text-right" :class="amountClass(minedValueTotal)">{{ amountOf(minedValueTotal) }}</td>
            <td v-if="showShares" class="py-2 pr-2 text-right" :class="amountClass(minedValueTotal)">{{ minedValueTotal === null ? t('miningOperation.quantities.unknown') : `${formatPercent(FULL_PERCENT, locale)} %` }}</td>
            <td v-if="showShares" class="py-2 pr-2 text-right" :class="amountClass(sharesTotal)">{{ amountOf(sharesTotal) }}</td>
            <td class="py-2 pr-2 text-right" :class="amountClass(valuation.iskPerHour)">{{ valuation.iskPerHour === null ? t('miningOperation.quantities.unknown') : formatWhole(valuation.iskPerHour, locale) }}</td>
            <td class="py-2 text-xs font-normal text-slate-400">{{ showShares && depositorsCount > 0 ? t('miningOperation.fleetStorage.totalDepositors', { count: depositorsCount }) : '' }}</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <p class="mt-3 font-mono text-xs" :class="valuation.totalValue === null ? 'text-amber-300' : 'text-emerald-300'">{{ checkText }}</p>

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
      <p class="font-mono text-xs text-slate-400">{{ pricesFootnote }}</p>
      <button
        v-if="!valuation.frozen"
        type="button"
        class="rounded-md border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-800"
        @click="emit('reread')"
      >{{ t('miningOperation.shares.reread') }}</button>
    </div>
  </section>
</template>
