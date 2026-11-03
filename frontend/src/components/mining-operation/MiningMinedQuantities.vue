<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MinedQuantityState, MiningLedgerCharacterQuantity, MiningLedgerParticipant, MiningOperationLedger } from '@/stores/miningOperation'
import { formatCount } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'

const props = defineProps<{
  ledger: MiningOperationLedger
}>()

const { t, locale } = useI18n()

const unknownParticipants = computed(() => props.ledger.participants.filter(participant => participant.state === 'unknown'))

function participantName(participant: MiningLedgerParticipant): string {
  return participant.characterName ?? t('miningOperation.unknownPilot')
}

function unitsText(count: number): string {
  return count === 0 ? formatCount(0, locale.value) : t('miningOperation.quantities.units', { count: formatCount(count, locale.value) })
}

function totalText(state: MinedQuantityState, totalQuantity: number | null): string {
  if (state === 'unknown') return t('miningOperation.quantities.unknown')
  if (state === 'measuring' || totalQuantity === null) return t('miningOperation.quantities.measuring')
  return unitsText(totalQuantity)
}

function totalClass(state: MinedQuantityState): string {
  if (state === 'unknown') return 'text-amber-300'
  return state === 'measuring' ? 'text-slate-400' : 'text-slate-100'
}

function correctionTrace(quantity: MiningLedgerCharacterQuantity): string {
  const correction = quantity.correction
  if (correction === null) return ''
  return t('miningOperation.correction.trace', {
    author: correction.correctedByName ?? t('miningOperation.unknownPilot'),
    time: formatEveClock(correction.correctedAt),
    value: quantity.measuredQuantity === null ? t('miningOperation.quantities.unknown') : formatCount(quantity.measuredQuantity, locale.value),
  })
}
</script>

<template>
  <section class="eve-card p-5">
    <h2 class="mb-1 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.quantities.title') }}</h2>
    <p class="mb-3 text-xs text-slate-400">{{ ledger.solarSystemName === null ? t('miningOperation.quantities.allSystems') : t('miningOperation.quantities.systemFilter', { system: ledger.solarSystemName }) }}</p>

    <p
      v-for="participant in unknownParticipants"
      :key="participant.memberId"
      class="mb-3 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200"
      role="alert"
    >{{ t('miningOperation.quantities.unknownBanner', { name: participantName(participant) }) }}</p>

    <ul class="divide-y divide-slate-800">
      <li v-for="participant in ledger.participants" :key="participant.memberId" class="py-2">
        <details>
          <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2">
            <span class="min-w-0 max-w-full truncate font-display text-base font-semibold text-slate-100" :title="participantName(participant)">{{ participantName(participant) }}</span>
            <span class="font-mono text-sm" :class="totalClass(participant.state)"><span aria-hidden="true" class="mr-2 text-slate-600">·</span>{{ totalText(participant.state, participant.totalQuantity) }}<span aria-hidden="true" class="ml-2 text-slate-500">▾</span></span>
          </summary>
          <ul class="mt-2 space-y-2 pl-3">
            <li v-for="character in participant.characters" :key="character.id">
              <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span class="min-w-0 max-w-full truncate text-slate-300" :title="character.name">{{ character.name }}</span>
                <span class="font-mono text-xs" :class="totalClass(character.state)"><span aria-hidden="true" class="mr-2 text-slate-600">·</span>{{ totalText(character.state, character.totalQuantity) }}</span>
              </div>
              <ul class="mt-1 space-y-0.5 pl-3">
                <li v-for="quantity in character.quantities" :key="quantity.typeId" class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-400">
                  <span class="min-w-0 truncate">{{ quantity.typeName }}</span>
                  <span class="flex items-center gap-2 font-mono text-slate-200">
                    <span v-if="quantity.correction !== null" class="rounded-sm border border-cyan-500/40 bg-cyan-500/10 px-1.5 py-0.5 text-cyan-300" :title="correctionTrace(quantity)">{{ t('miningOperation.correction.corrected') }}</span>
                    <span>{{ unitsText(quantity.quantity) }}</span>
                  </span>
                </li>
              </ul>
            </li>
          </ul>
        </details>
      </li>
    </ul>
  </section>
</template>
