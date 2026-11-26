<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlannerDisplay } from '@/composables/usePlannerDisplay'
import { usePlanetaryStore } from '@/stores/planetary'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { compareSales } from '@/utils/planetaryFollow'

const PLANNER_ROUTE = { name: 'planetary', query: { tab: 'planner' } }
const RATIO_DECIMALS = 2

const { t } = useI18n()
const store = usePlanetaryPlannerStore()
const planetaryStore = usePlanetaryStore()
const { formatPlannerIsk, formatNumber } = usePlannerDisplay()

const line = computed(() => {
  const template = store.followedTemplate
  const targets = store.followedTargetsOfPlan
  const revenue = planetaryStore.revenue
  if (template === null || targets === null || revenue === null) return null

  const sales = compareSales(targets.salesPerDay, { realisedIsk: revenue.realisedIsk, days: revenue.days })
  return {
    name: template.name,
    planned: sales.planned,
    real: sales.real,
    gap: sales.gap,
    ratio: sales.ratio,
    profit: targets.profitPerDay,
  }
})

function stateOf(value: number | null): 'known' | 'unknown' {
  return value === null ? 'unknown' : 'known'
}

function gapClass(gap: number | null): string {
  if (gap === null) return 'text-slate-500'
  if (gap === 0) return 'text-slate-200'
  return gap < 0 ? 'text-red-400' : 'text-emerald-400'
}
</script>

<template>
  <section v-if="line !== null" class="rounded-xl border border-emerald-500/30 bg-slate-900 p-4" data-testid="revenue-plan-line">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
      <div class="min-w-0">
        <span class="block text-xs uppercase tracking-wider text-emerald-400">{{ t('pi.planner.follow.revenue.title') }}</span>
        <span class="mt-0.5 block truncate text-base font-semibold text-white" data-testid="revenue-plan-name" :title="line.name">{{ line.name }}</span>
      </div>
      <RouterLink :to="PLANNER_ROUTE" class="text-sm text-cyan-400 hover:text-cyan-300" data-testid="revenue-plan-link">{{ t('pi.planner.follow.revenue.openPlanner') }}</RouterLink>
    </div>

    <dl class="grid grid-cols-2 gap-3 md:grid-cols-5">
      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-3">
        <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.follow.revenue.planned') }}</dt>
        <dd class="mt-1 font-mono-tech text-lg" :class="line.planned === null ? 'text-slate-500' : 'text-white'" data-testid="revenue-plan-planned" :data-state="stateOf(line.planned)">{{ line.planned === null ? t('pi.planner.unknown') : formatPlannerIsk(line.planned) }}</dd>
      </div>
      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-3">
        <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.follow.revenue.real') }}</dt>
        <dd class="mt-1 font-mono-tech text-lg text-white" data-testid="revenue-plan-real" :data-state="stateOf(line.real)">{{ formatPlannerIsk(line.real) }}</dd>
      </div>
      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-3">
        <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.follow.revenue.gap') }}</dt>
        <dd class="mt-1 font-mono-tech text-lg" :class="gapClass(line.gap)" data-testid="revenue-plan-gap" :data-state="stateOf(line.gap)">{{ line.gap === null ? t('pi.planner.unknown') : formatPlannerIsk(line.gap) }}</dd>
      </div>
      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-3">
        <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.follow.revenue.ratio') }}</dt>
        <dd class="mt-1 font-mono-tech text-lg" :class="line.ratio === null ? 'text-slate-500' : 'text-white'" data-testid="revenue-plan-ratio" :data-state="stateOf(line.ratio)">{{ line.ratio === null ? t('pi.planner.unknown') : formatNumber(line.ratio, RATIO_DECIMALS) }}</dd>
      </div>
      <div class="rounded-lg border border-slate-800 bg-slate-950/40 p-3">
        <dt class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.follow.revenue.profit') }}</dt>
        <dd class="mt-1 font-mono-tech text-lg" :class="line.profit === null ? 'text-slate-500' : 'text-slate-200'" data-testid="revenue-plan-profit" :data-state="stateOf(line.profit)">{{ line.profit === null ? t('pi.planner.unknown') : formatPlannerIsk(line.profit) }}</dd>
      </div>
    </dl>
  </section>
</template>
