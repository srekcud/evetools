<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { parseDecimalInput, type PriceSource } from '@/utils/planetaryPlanner'

const PRICE_SOURCES: readonly PriceSource[] = ['jita-sell', 'jita-buy']
const PRICE_SOURCE_LABEL_KEY: Record<PriceSource, string> = {
  'jita-sell': 'pi.planner.settings.jitaSell',
  'jita-buy': 'pi.planner.settings.jitaBuy',
}

const { t } = useI18n()
const store = usePlanetaryPlannerStore()

const isTaxInvalid = ref(false)
const isDaysInvalid = ref(false)

function onTaxInput(event: Event): void {
  isTaxInvalid.value = !store.setTaxPercent(parseDecimalInput((event.target as HTMLInputElement).value))
}

function onDaysInput(event: Event): void {
  isDaysInvalid.value = !store.setDaysBetweenRounds(parseDecimalInput((event.target as HTMLInputElement).value))
}

function sourceButtonClass(source: PriceSource): string[] {
  return [
    'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
    store.settings.priceSource === source ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50',
  ]
}

function inputClass(isInvalid: boolean): string[] {
  return [
    'w-full rounded-md border bg-slate-800 px-3 py-1.5 font-mono-tech text-sm text-white focus:outline-hidden',
    isInvalid ? 'border-red-500/60 focus:border-red-400' : 'border-slate-700 focus:border-cyan-500',
  ]
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="planner-settings">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.planner.settings.title') }}</h3>

    <div class="space-y-4">
      <div>
        <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.settings.priceSource') }}</span>
        <div class="flex w-fit max-w-full flex-wrap gap-1 rounded-lg bg-slate-950 p-1" role="group" :aria-label="t('pi.planner.settings.priceSource')">
          <button
            v-for="source in PRICE_SOURCES"
            :key="source"
            type="button"
            :data-source="source"
            :class="sourceButtonClass(source)"
            :aria-pressed="store.settings.priceSource === source"
            @click="store.setPriceSource(source)"
          >{{ t(PRICE_SOURCE_LABEL_KEY[source]) }}</button>
        </div>
      </div>

      <label class="block">
        <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.settings.taxPercent') }}</span>
        <input
          type="number"
          inputmode="decimal"
          min="0"
          max="100"
          step="0.1"
          data-testid="planner-tax-input"
          :value="store.settings.taxPercent"
          :class="inputClass(isTaxInvalid)"
          :aria-invalid="isTaxInvalid"
          @input="onTaxInput"
        />
        <span :class="['mt-1 block text-xs', isTaxInvalid ? 'text-red-400' : 'text-slate-500']">
          {{ isTaxInvalid ? t('pi.planner.settings.taxInvalid') : t('pi.planner.settings.taxHint') }}
        </span>
      </label>

      <label class="block">
        <span class="mb-1.5 block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.settings.daysBetweenRounds') }}</span>
        <input
          type="number"
          inputmode="decimal"
          min="0.5"
          step="0.5"
          data-testid="planner-days-input"
          :value="store.settings.daysBetweenRounds"
          :class="inputClass(isDaysInvalid)"
          :aria-invalid="isDaysInvalid"
          @input="onDaysInput"
        />
        <span :class="['mt-1 block text-xs', isDaysInvalid ? 'text-red-400' : 'text-slate-500']">
          {{ isDaysInvalid ? t('pi.planner.settings.daysInvalid') : t('pi.planner.settings.daysHint') }}
        </span>
      </label>
    </div>
  </section>
</template>
