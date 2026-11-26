<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import { useFactoryGapDisplay } from '@/composables/useFactoryGapDisplay'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'

const UNKNOWN_FIGURE = '—'

const { t } = useI18n()
const store = usePlanetaryPlannerStore()
const { formatNumber } = useFormatters()
const { gapText, gapClass } = useFactoryGapDisplay()
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="factory-gap">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.planner.follow.factories.title') }}</h3>

    <div class="grid grid-cols-[3rem_1fr_1fr_minmax(6rem,auto)] items-center gap-x-4 gap-y-2 text-sm">
      <span />
      <span class="text-right text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.follow.factories.planned') }}</span>
      <span class="text-right text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.follow.factories.real') }}</span>
      <span />

      <template v-for="gap in store.factoryComparison" :key="gap.tier">
        <div class="contents" data-testid="factory-gap-row" :data-tier="gap.tier" :data-state="gap.state">
          <span :class="['w-fit rounded-sm border px-1.5 py-0.5 text-xs', TIER_CONFIG[gap.tier].badgeBg, TIER_CONFIG[gap.tier].badgeText, TIER_CONFIG[gap.tier].badgeBorder]">{{ gap.tier }}</span>
          <span class="text-right font-mono-tech text-slate-200" data-testid="factory-gap-planned">{{ formatNumber(gap.planned, 0) }}</span>
          <span class="text-right font-mono-tech text-slate-200" data-testid="factory-gap-real">{{ gap.real === null ? UNKNOWN_FIGURE : formatNumber(gap.real, 0) }}</span>
          <span class="text-right font-mono-tech" :class="gapClass(gap.state)" data-testid="factory-gap-text">{{ gapText(gap) }}</span>
        </div>
      </template>
    </div>
  </section>
</template>
