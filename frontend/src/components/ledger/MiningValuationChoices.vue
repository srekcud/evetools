<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMiningValuationSettings } from '@/composables/useMiningValuationSettings'
import type { MiningValuationForm } from '@/stores/miningOperation'
import type { OreValueMarket, OreValueSide } from '@/composables/useOreValueTable'

const VALUATION_FORMS: MiningValuationForm[] = ['raw', 'compressed', 'reprocessed']

type MarketChoiceId = 'jitaBuy' | 'jitaSell' | 'structureBuy' | 'structureSell'

interface MarketChoice {
  id: MarketChoiceId
  market: OreValueMarket
  side: OreValueSide
}

const marketChoicesById: Record<MarketChoiceId, MarketChoice> = {
  jitaSell: { id: 'jitaSell', market: 'jita', side: 'sell' },
  jitaBuy: { id: 'jitaBuy', market: 'jita', side: 'buy' },
  structureSell: { id: 'structureSell', market: 'structure', side: 'sell' },
  structureBuy: { id: 'structureBuy', market: 'structure', side: 'buy' },
}
const marketChoices = Object.values(marketChoicesById)

const { t } = useI18n()
const { market, side, form, selectedStructureId, setMarketAndSide, setForm } = useMiningValuationSettings()

const selectedChoice = computed(() => marketChoices.find(choice => choice.market === market.value && choice.side === side.value) ?? marketChoicesById.jitaBuy)
const selectedChoiceId = computed(() => selectedChoice.value.id)

function isAvailable(choiceMarket: OreValueMarket): boolean {
  return choiceMarket === 'jita' || selectedStructureId.value !== null
}

function onFormChange(event: Event): void {
  setForm((event.target as HTMLSelectElement).value as MiningValuationForm)
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
    <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('ledger.mining.oreValue.marketLabel')">
      <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('ledger.mining.oreValue.marketLabel') }}</span>
      <button
        v-for="choice in marketChoices"
        :key="choice.id"
        type="button"
        :aria-pressed="selectedChoiceId === choice.id"
        :disabled="!isAvailable(choice.market)"
        :title="isAvailable(choice.market) ? undefined : t('ledger.mining.oreValue.structureRequired')"
        :class="[
          'px-3 py-1 rounded-sm border text-xs font-medium uppercase tracking-wider transition-colors disabled:opacity-40 disabled:cursor-not-allowed',
          selectedChoiceId === choice.id ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 text-slate-400 hover:text-slate-200',
        ]"
        @click="setMarketAndSide(choice.market, choice.side)"
      >
        {{ t(`ledger.mining.oreValue.${choice.id}`) }}
      </button>
    </div>
    <label class="flex items-center gap-2">
      <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('miningOperation.valuation.form.label') }}</span>
      <select
        data-valuation-form
        :value="form"
        class="rounded-sm border border-slate-700 bg-slate-800/50 px-2 py-1 text-xs font-medium uppercase tracking-wider text-slate-200 focus:outline-hidden focus:border-cyan-500/50"
        @change="onFormChange"
      >
        <option v-for="valuationForm in VALUATION_FORMS" :key="valuationForm" :value="valuationForm">
          {{ t(`miningOperation.valuation.form.${valuationForm}`) }}
        </option>
      </select>
    </label>
    <slot />
  </div>
</template>
