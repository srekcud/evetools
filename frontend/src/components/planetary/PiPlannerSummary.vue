<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import { usePlannerDisplay } from '@/composables/usePlannerDisplay'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'

type FigureState = 'known' | 'incomplete' | 'unknown'

const FACTORY_TIER_ORDER = ['P4', 'P3', 'P2', 'P1'] as const

const { t } = useI18n()
const store = usePlanetaryPlannerStore()
const { typeOf, formatPlannerIsk, formatNumber } = usePlannerDisplay()

const summary = computed(() => store.plan?.summary ?? null)

const selectedUnitCost = computed(() => {
  const index = store.selectedIndex
  return index === null || summary.value === null ? null : (summary.value.unitCost[index] ?? null)
})

const selectedName = computed(() => {
  const production = store.selectedIndex === null ? undefined : store.productions[store.selectedIndex]
  return production ? typeOf(production.typeId).name : null
})

const profitState = computed<FigureState>(() => {
  if (summary.value === null) return 'unknown'
  const knownCount = summary.value.profitPerDay.filter(profit => profit !== null).length
  if (knownCount === 0) return 'unknown'
  return summary.value.profitIncomplete ? 'incomplete' : 'known'
})

const purchaseLines = computed(() =>
  (summary.value?.weeklyPurchases ?? []).map(purchase => ({ ...purchase, name: typeOf(purchase.typeId).name })),
)

const extractionLines = computed(() =>
  (summary.value?.extractionPerHour ?? []).map(line => ({ ...line, name: typeOf(line.typeId).name })),
)

const factoryLines = computed(() => {
  const factoriesByTier = summary.value?.factoriesByTier
  return factoriesByTier ? FACTORY_TIER_ORDER.map(tier => ({ tier, count: factoriesByTier[tier] })) : []
})

function profitClass(): string {
  if (profitState.value === 'unknown' || summary.value === null) return 'text-slate-500'
  return summary.value.totalProfitPerDay >= 0 ? 'text-emerald-400' : 'text-red-400'
}
</script>

<template>
  <section v-if="summary !== null && store.productions.length > 0" class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="planner-summary">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.planner.summary.title') }}</h3>

    <div class="grid gap-4 md:grid-cols-2">
      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4" :title="selectedName === null ? undefined : t('pi.planner.summary.unitCostOf', { name: selectedName })">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.summary.unitCost') }}</span>
        <span class="mt-1 block truncate text-xs text-slate-500">{{ selectedName }}</span>
        <span
          class="mt-1 block font-mono-tech text-2xl"
          :class="selectedUnitCost === null ? 'text-slate-500' : 'text-white'"
          data-testid="summary-unit-cost"
          :data-state="selectedUnitCost === null ? 'unknown' : 'known'"
        >{{ selectedUnitCost === null ? t('pi.planner.unknown') : formatPlannerIsk(selectedUnitCost) }}</span>
      </div>

      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.summary.profit') }}</span>
        <span
          class="mt-1 block font-mono-tech text-2xl"
          :class="profitClass()"
          data-testid="summary-profit"
          :data-state="profitState"
        >{{ profitState === 'unknown' ? t('pi.planner.unknown') : formatPlannerIsk(summary.totalProfitPerDay) }}</span>
        <span v-if="profitState !== 'known'" class="mt-1 block text-xs text-amber-400">{{ t('pi.planner.summary.profitIncomplete') }}</span>
      </div>

      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4 md:col-span-2" data-testid="summary-purchases" :data-incomplete="summary.purchasesIncomplete">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.summary.purchases') }}</span>
        <span class="mt-1 block font-mono-tech text-2xl text-white">{{ formatPlannerIsk(summary.weeklyPurchasesIsk) }}</span>
        <span class="block text-xs text-slate-500">{{ t('pi.planner.summary.purchasesEstimate') }}</span>
        <span v-if="summary.purchasesIncomplete" class="mt-1 block text-xs text-amber-400">{{ t('pi.planner.summary.purchasesIncomplete') }}</span>
        <p v-if="purchaseLines.length === 0" class="mt-3 text-sm text-slate-400">{{ t('pi.planner.summary.noPurchases') }}</p>
        <ul v-else class="mt-3 space-y-1">
          <li v-for="line in purchaseLines" :key="line.typeId" class="flex items-center gap-3 text-sm" data-testid="summary-purchase-line">
            <span class="min-w-0 flex-1 truncate text-slate-300" :title="line.name">{{ line.name }}</span>
            <span class="font-mono-tech text-slate-400">{{ formatNumber(line.quantity, 0) }}</span>
            <span class="w-24 text-right font-mono-tech" :class="line.isk === null ? 'text-amber-400' : 'text-slate-200'">{{ line.isk === null ? t('pi.planner.unknown') : formatPlannerIsk(line.isk) }}</span>
          </li>
        </ul>
      </div>

      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4" data-testid="summary-factories">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.summary.factories') }}</span>
        <ul class="mt-3 space-y-1.5">
          <li v-for="line in factoryLines" :key="line.tier" class="flex items-center justify-between text-sm" :data-tier="line.tier">
            <span :class="['rounded-sm border px-1.5 py-0.5 text-xs', TIER_CONFIG[line.tier].badgeBg, TIER_CONFIG[line.tier].badgeText, TIER_CONFIG[line.tier].badgeBorder]">{{ line.tier }}</span>
            <span class="font-mono-tech text-slate-200">{{ formatNumber(line.count, 0) }}</span>
          </li>
        </ul>
      </div>

      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-4" data-testid="summary-extraction">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.summary.extraction') }}</span>
        <p v-if="extractionLines.length === 0" class="mt-3 text-sm text-slate-400">{{ t('pi.planner.summary.noExtraction') }}</p>
        <ul v-else class="mt-3 space-y-1.5">
          <li v-for="line in extractionLines" :key="line.typeId" class="flex items-center gap-3 text-sm">
            <span class="min-w-0 flex-1 truncate text-slate-300" :title="line.name">{{ line.name }}</span>
            <span class="font-mono-tech text-slate-200">{{ formatNumber(line.perHour, 0) }}</span>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
