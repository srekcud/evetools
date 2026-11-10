<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { useFormatters } from '@/composables/useFormatters'
import type { StockpileCoverage, StockpileCoverageMineral } from '@/composables/useStockpileCoverage'

type CoverageRowState = 'partial' | 'whole' | 'no-deficit' | 'unknown'

const PERCENT_DECIMALS = 2
const QUANTITY_DECIMALS = 0
const YIELD_PANEL_LOCATION = { name: 'mining-ore-value' }

defineProps<{ coverage: StockpileCoverage }>()

const { t } = useI18n()
const { formatNumber } = useFormatters()

function rowState(mineral: StockpileCoverageMineral): CoverageRowState {
  if (!mineral.hasDeficit) return 'no-deficit'
  if (mineral.coveragePercent === null) return 'unknown'
  return mineral.coversWholeDeficit === true ? 'whole' : 'partial'
}

function rowClass(state: CoverageRowState): string {
  if (state === 'whole') return 'border-l-2 border-emerald-500/60 pl-3'
  if (state === 'partial') return 'border-l-2 border-cyan-500/60 pl-3'
  if (state === 'unknown') return 'border-l-2 border-amber-500/60 pl-3'
  return 'border-l-2 border-slate-700 pl-3'
}

function mineralName(mineral: StockpileCoverageMineral): string {
  return mineral.typeName ?? String(mineral.typeId)
}
</script>

<template>
  <section class="eve-card p-5" data-stockpile-coverage>
    <h2 class="mb-1 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.stockpileCoverage.title') }}</h2>

    <p v-if="coverage.state === 'measuring'" class="text-sm text-slate-400" data-coverage-measuring>{{ t('miningOperation.stockpileCoverage.measuring') }}</p>
    <p v-else-if="coverage.state === 'unknown'" class="text-sm text-amber-300" data-coverage-unknown>{{ t('miningOperation.stockpileCoverage.unknown') }}</p>
    <p v-else-if="!coverage.yieldKnown" class="text-sm text-amber-300" data-coverage-yield-unknown>
      <RouterLink :to="YIELD_PANEL_LOCATION" class="underline hover:text-amber-200">{{ t('miningOperation.stockpileCoverage.yieldUnknown') }}</RouterLink>
    </p>
    <template v-else>
      <p v-if="coverage.minerals.length === 0" class="text-sm text-slate-400">{{ t('miningOperation.stockpileCoverage.nothingToReprocess') }}</p>
      <template v-else>
        <p class="mb-3 text-xs text-slate-400">{{ t('miningOperation.stockpileCoverage.intro') }}</p>
        <ul class="space-y-2">
          <li
            v-for="mineral in coverage.minerals"
            :key="mineral.typeId"
            class="flex flex-wrap items-center justify-between gap-2 text-sm"
            :class="rowClass(rowState(mineral))"
            :data-coverage-mineral="mineral.typeId"
            :data-coverage-state="rowState(mineral)"
          >
            <span class="min-w-0 max-w-full truncate text-slate-200" :title="mineralName(mineral)" data-coverage-mineral-name>{{ mineralName(mineral) }}</span>
            <span class="flex flex-wrap items-center gap-3 font-mono text-xs">
              <span class="text-slate-300" data-produced-quantity>
                <template v-if="mineral.producedQuantity === null"><UnknownValue /></template>
                <template v-else>{{ formatNumber(mineral.producedQuantity, QUANTITY_DECIMALS) }}</template>
              </span>
              <span v-if="rowState(mineral) === 'no-deficit'" class="text-slate-400">{{ t('miningOperation.stockpileCoverage.noDeficit') }}</span>
              <template v-else>
                <span v-if="rowState(mineral) === 'whole'" class="rounded-sm border border-emerald-500/40 bg-emerald-500/10 px-1.5 py-0.5 text-emerald-300">{{ t('miningOperation.stockpileCoverage.wholeDeficit') }}</span>
                <span class="text-slate-100" data-coverage-percent>
                  <template v-if="mineral.coveragePercent === null"><UnknownValue /></template>
                  <template v-else>{{ formatNumber(mineral.coveragePercent, PERCENT_DECIMALS) }} %</template>
                </span>
              </template>
            </span>
          </li>
        </ul>
      </template>
    </template>
  </section>
</template>
