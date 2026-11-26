<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import { useEveImages } from '@/composables/useEveImages'
import { usePlannerDisplay } from '@/composables/usePlannerDisplay'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { parseDecimalInput } from '@/utils/planetaryPlanner'
import { PLANETS_PER_CHARACTER } from '@/utils/planetaryPlanets'
import {
  DEFAULT_RANKING_ROWS,
  rankingBaseCount,
  type RankingBase,
  type RankingRow,
  type RankingTierFilter,
} from '@/utils/planetaryRanking'

const ICON_SIZE = 32
const PERCENT = 100
const TREND_DECIMALS = 1
const MARGIN_DECIMALS = 1
const BASES: readonly RankingBase[] = ['free', 'planet', 'character']
const TIERS: readonly RankingTierFilter[] = ['all', 'P2', 'P3', 'P4']
const GRID_COLUMNS = 'grid min-w-[84rem] grid-cols-[2.5rem_minmax(14rem,1.6fr)_minmax(9rem,1fr)_minmax(6rem,0.7fr)_5rem_minmax(6rem,0.7fr)_5rem_4.5rem_6.5rem_6.5rem_6.5rem_6.5rem_5.5rem] items-center gap-3'

const { t } = useI18n()
const store = usePlanetaryPlannerStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { typeOf, formatPlannerIsk, formatNumber } = usePlannerDisplay()

const showAll = ref(false)
const shareRejected = ref(false)

const freePlanets = computed(() => rankingBaseCount('free', store.planetPlan))

const rows = computed(() => store.ranking.map(row => ({ row, name: typeOf(row.typeId).name })))
const hasHiddenRows = computed(() => rows.value.length > DEFAULT_RANKING_ROWS)
const visibleRows = computed(() => (showAll.value ? rows.value : rows.value.slice(0, DEFAULT_RANKING_ROWS)))

function baseLabel(base: RankingBase): string {
  if (base === 'planet') return t('pi.planner.ranking.base.planet')
  if (base === 'character') return t('pi.planner.ranking.base.character', { count: PLANETS_PER_CHARACTER })
  return t('pi.planner.ranking.base.free', { count: freePlanets.value === null ? t('pi.planner.unknown') : formatNumber(freePlanets.value, 0) })
}

function isBaseDisabled(base: RankingBase): boolean {
  return base === 'free' && freePlanets.value === null
}

function onShareChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const accepted = store.setVolumeShare(parseDecimalInput(input.value))
  shareRejected.value = !accepted
  if (!accepted) input.value = String(store.volumeSharePercent)
}

function trendText(priceChange: number): string {
  return `${priceChange > 0 ? '+' : ''}${formatNumber(priceChange, TREND_DECIMALS)} %`
}

function trendClass(priceChange: number | null): string {
  if (priceChange === null || priceChange === 0) return 'text-slate-500'
  return priceChange > 0 ? 'text-emerald-400' : 'text-red-400'
}

function profitClass(profit: number | null): string {
  if (profit === null) return 'text-slate-500'
  return profit < 0 ? 'text-red-400' : 'text-emerald-400'
}

function stateOf(value: number | null): 'known' | 'unknown' {
  return value === null ? 'unknown' : 'known'
}

function addToPlan(row: RankingRow): void {
  if (row.planTarget !== null) store.addProduction(row.typeId, row.planTarget)
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="planner-ranking">
    <div class="mb-4">
      <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.planner.ranking.title') }}</h3>
      <p class="mt-1 max-w-3xl text-xs text-slate-500">{{ t('pi.planner.ranking.subtitle') }}</p>
    </div>

    <div class="mb-4 flex flex-wrap items-start gap-x-8 gap-y-4">
      <div>
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.ranking.base.label') }}</span>
        <div class="mt-1 flex flex-wrap gap-2" data-testid="ranking-base">
          <button
            v-for="base in BASES"
            :key="base"
            type="button"
            :data-base="base"
            :disabled="isBaseDisabled(base)"
            :aria-pressed="store.rankingBase === base"
            :title="isBaseDisabled(base) ? t('pi.planner.ranking.base.freeUnknown') : undefined"
            :class="[
              'rounded-md border px-3 py-1.5 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50',
              store.rankingBase === base ? 'border-cyan-500 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 bg-slate-800 text-slate-300 hover:border-slate-500',
            ]"
            @click="store.setRankingBase(base)"
          >{{ baseLabel(base) }}</button>
        </div>
      </div>

      <label class="block">
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.ranking.share.label') }}</span>
        <span class="mt-1 flex items-center gap-2">
          <input
            type="text"
            inputmode="decimal"
            data-testid="ranking-share"
            :value="String(store.volumeSharePercent)"
            :aria-invalid="shareRejected"
            :title="t('pi.planner.ranking.share.help')"
            :class="['w-24 rounded-md border bg-slate-800 px-2 py-1.5 text-right font-mono-tech text-sm text-white focus:outline-hidden', shareRejected ? 'border-red-500/60' : 'border-slate-700 focus:border-cyan-500']"
            @change="onShareChange"
          />
          <span class="text-sm text-slate-400">{{ t('pi.planner.ranking.share.unit') }}</span>
        </span>
        <span v-if="shareRejected" class="mt-1 block text-xs text-red-400" data-testid="ranking-share-invalid">{{ t('pi.planner.ranking.share.invalid') }}</span>
      </label>

      <div>
        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ t('pi.planner.ranking.columns.product') }}</span>
        <div class="mt-1 flex flex-wrap gap-2" data-testid="ranking-tier">
          <button
            v-for="tier in TIERS"
            :key="tier"
            type="button"
            :data-tier="tier"
            :aria-pressed="store.rankingTier === tier"
            :class="[
              'rounded-md border px-3 py-1.5 text-sm transition-colors',
              store.rankingTier === tier ? 'border-cyan-500 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 bg-slate-800 text-slate-300 hover:border-slate-500',
            ]"
            @click="store.setRankingTier(tier)"
          >{{ t(`pi.planner.ranking.tiers.${tier}`) }}</button>
        </div>
      </div>
    </div>

    <div class="overflow-x-auto">
      <div :class="[GRID_COLUMNS, 'border-b border-slate-700 px-2 pb-2 text-xs uppercase tracking-wider text-slate-500']">
        <span>{{ t('pi.planner.ranking.columns.rank') }}</span>
        <span>{{ t('pi.planner.ranking.columns.product') }}</span>
        <span :title="t('pi.planner.ranking.chain.legend')">{{ t('pi.planner.ranking.columns.chain') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.output') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.planets') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.volume') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.trend') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.margin') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.profitDay') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.profitWeek') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.profitMonth') }}</span>
        <span class="text-right">{{ t('pi.planner.ranking.columns.purchases') }}</span>
        <span />
      </div>

      <div
        v-for="(entry, position) in visibleRows"
        :key="entry.row.typeId"
        :class="[GRID_COLUMNS, 'border-b border-slate-800/70 px-2 py-2 text-sm']"
        data-testid="ranking-row"
        :data-type-id="entry.row.typeId"
        :data-status="entry.row.fit"
      >
        <span class="font-mono-tech text-slate-500" data-testid="ranking-rank">{{ position + 1 }}</span>

        <div class="flex min-w-0 items-center gap-2" data-testid="ranking-product">
          <img :src="getTypeIconUrl(entry.row.typeId, ICON_SIZE)" alt="" class="h-5 w-5 shrink-0 rounded-sm" @error="onImageError" />
          <span class="min-w-0 truncate text-slate-100" :title="entry.name">{{ entry.name }}</span>
          <span :class="['shrink-0 rounded-sm border px-1.5 py-0.5 text-xs', TIER_CONFIG[entry.row.tier].badgeBg, TIER_CONFIG[entry.row.tier].badgeText, TIER_CONFIG[entry.row.tier].badgeBorder]">{{ entry.row.tier }}</span>
        </div>

        <div class="flex min-w-0 flex-wrap gap-x-3 gap-y-1" data-testid="ranking-chain">
          <span v-for="step in entry.row.chain" :key="step.tier" class="whitespace-nowrap font-mono-tech text-xs">
            <span :class="TIER_CONFIG[step.tier].badgeText">{{ step.tier }}</span>
            <span class="ml-1 text-slate-300">{{ step.produced }} · {{ step.bought }}</span>
          </span>
        </div>

        <span
          class="text-right font-mono-tech"
          :class="entry.row.outputPerDay === null && entry.row.fit === 'doesNotFit' ? 'text-amber-400' : 'text-slate-100'"
          data-testid="ranking-output"
          :data-state="stateOf(entry.row.outputPerDay)"
        >
          <template v-if="entry.row.outputPerDay !== null">
            {{ formatNumber(entry.row.outputPerDay, 0) }}
            <span v-if="entry.row.capped" class="text-amber-400" data-testid="ranking-capped" :title="t('pi.planner.ranking.status.capped')">▼</span>
          </template>
          <template v-else-if="entry.row.fit === 'doesNotFit' && entry.row.doesNotFitDays !== null">{{ t('pi.planner.ranking.status.doesNotFit', { days: formatNumber(entry.row.doesNotFitDays, 1) }) }}</template>
          <template v-else-if="entry.row.fit === 'unknownVolume'"><span class="text-amber-400">{{ t('pi.planner.ranking.status.sdeVolumeUnknown') }}</span></template>
          <template v-else>—</template>
        </span>

        <span
          class="text-right font-mono-tech"
          :class="entry.row.planetsUsed === null || entry.row.baseCount === null ? 'text-slate-500' : 'text-slate-100'"
          data-testid="ranking-planets"
          :data-state="entry.row.planetsUsed === null || entry.row.baseCount === null ? 'unknown' : 'known'"
        >{{ entry.row.planetsUsed === null || entry.row.baseCount === null ? '—' : `${formatNumber(entry.row.planetsUsed, 0)} / ${formatNumber(entry.row.baseCount, 0)}` }}</span>

        <span
          class="text-right font-mono-tech"
          :class="entry.row.averageDailyVolume === null ? 'text-slate-500' : 'text-slate-100'"
          data-testid="ranking-volume"
          :data-state="stateOf(entry.row.averageDailyVolume)"
          :data-last-date="entry.row.lastDate === null ? undefined : entry.row.lastDate"
          :title="entry.row.lastDate === null ? t('pi.planner.ranking.status.volumeUnknown') : t('pi.planner.ranking.status.lastDate', { date: entry.row.lastDate })"
        >{{ entry.row.averageDailyVolume === null ? t('pi.planner.unknown') : formatNumber(entry.row.averageDailyVolume, 0) }}</span>

        <span
          class="text-right font-mono-tech"
          :class="trendClass(entry.row.priceChange)"
          data-testid="ranking-trend"
          :data-state="stateOf(entry.row.priceChange)"
        >{{ entry.row.priceChange === null ? '—' : trendText(entry.row.priceChange) }}</span>

        <span
          class="text-right font-mono-tech"
          :class="entry.row.margin === null ? 'text-slate-500' : 'text-slate-100'"
          data-testid="ranking-margin"
          :data-state="stateOf(entry.row.margin)"
          :title="entry.row.margin === null ? t('pi.planner.ranking.status.costUnknown') : undefined"
        >{{ entry.row.margin === null ? '—' : `${formatNumber(entry.row.margin * PERCENT, MARGIN_DECIMALS)} %` }}</span>

        <span
          v-for="figure in [
            { testId: 'ranking-profit-day', value: entry.row.profitPerDay },
            { testId: 'ranking-profit-week', value: entry.row.profitPerWeek },
            { testId: 'ranking-profit-month', value: entry.row.profitPerMonth },
          ]"
          :key="figure.testId"
          class="text-right font-mono-tech"
          :class="profitClass(figure.value)"
          :data-testid="figure.testId"
          :data-state="stateOf(figure.value)"
        >{{ figure.value === null ? t('pi.planner.unknown') : formatPlannerIsk(figure.value) }}</span>

        <span
          class="text-right font-mono-tech"
          :class="entry.row.weeklyPurchasesIsk === null ? 'text-slate-500' : 'text-slate-300'"
          data-testid="ranking-purchases"
          :data-state="stateOf(entry.row.weeklyPurchasesIsk)"
        >{{ entry.row.weeklyPurchasesIsk === null ? '—' : formatPlannerIsk(entry.row.weeklyPurchasesIsk) }}</span>

        <button
          type="button"
          class="rounded-md border border-cyan-500/40 px-2 py-1 text-xs text-cyan-300 transition-colors hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:border-slate-700 disabled:text-slate-600 disabled:hover:bg-transparent"
          data-testid="ranking-add"
          :disabled="entry.row.planTarget === null"
          :title="entry.row.planTarget === null ? t('pi.planner.ranking.add.disabled') : undefined"
          @click="addToPlan(entry.row)"
        >{{ t('pi.planner.ranking.add.label') }}</button>
      </div>
    </div>

    <button
      v-if="hasHiddenRows"
      type="button"
      class="mt-3 text-sm text-cyan-400 hover:text-cyan-300"
      data-testid="ranking-show-all"
      @click="showAll = !showAll"
    >{{ showAll ? t('pi.planner.ranking.showLess', { count: DEFAULT_RANKING_ROWS }) : t('pi.planner.ranking.showAll', { count: rows.length }) }}</button>
  </section>
</template>
