<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useMiningOperationStore, type MiningSeasonPeriod } from '@/stores/miningOperation'
import { formatSeasonDuration } from './miningOperationSeason'
import { formatWhole } from './miningOperationQuantity'

const SEASON_PERIODS = ['30d', '90d', 'all'] as const satisfies readonly MiningSeasonPeriod[]
const PERCENT_MAX = 100

const { t, locale } = useI18n()
const store = useMiningOperationStore()

const period = ref<MiningSeasonPeriod>('30d')
const season = computed(() => store.season)
const longestPlayerMinutes = computed(() => Math.max(0, ...(season.value?.players ?? []).map(player => player.minutes)))

function amountText(amount: number | null): string {
  return amount === null ? t('miningOperation.quantities.unknown') : formatWhole(amount, locale.value)
}

function barWidth(minutes: number): string {
  return `${longestPlayerMinutes.value === 0 ? 0 : Math.round((minutes / longestPlayerMinutes.value) * PERCENT_MAX)}%`
}

async function reload(): Promise<void> {
  await store.fetchSeason(period.value)
}

async function choosePeriod(chosen: MiningSeasonPeriod): Promise<void> {
  period.value = chosen
  await reload()
}

defineExpose({ reload })

onMounted(reload)
</script>

<template>
  <div v-if="season === null" class="flex justify-center py-16">
    <p v-if="store.error !== null" class="eve-card p-6 text-sm text-red-400" role="alert">{{ store.error }}</p>
    <LoadingSpinner v-else size="xl" class="text-cyan-400" />
  </div>

  <div v-else>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-slate-400">{{ t('miningOperation.season.hint') }}</p>
      <div class="flex gap-1" role="group" :aria-label="t('miningOperation.season.period')">
        <button
          v-for="seasonPeriod in SEASON_PERIODS"
          :key="seasonPeriod"
          type="button"
          :aria-pressed="period === seasonPeriod"
          class="rounded-sm border px-3 py-1 font-mono text-xs transition-colors"
          :class="period === seasonPeriod ? 'border-cyan-400 bg-cyan-500/10 text-cyan-200' : 'border-slate-700 text-slate-400 hover:text-slate-200'"
          @click="choosePeriod(seasonPeriod)"
        >{{ t(`miningOperation.season.periods.${seasonPeriod}`) }}</button>
      </div>
    </div>

    <p
      v-if="season.unknownValueOperationsCount > 0"
      class="mb-4 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm text-amber-200"
      role="status"
      data-testid="season-unknown-banner"
    >
      <span class="block font-display font-semibold">{{ t('miningOperation.season.unknownBanner.title') }}</span>
      <span class="block text-xs">{{ t('miningOperation.season.unknownBanner.text', { count: season.unknownValueOperationsCount }) }}</span>
    </p>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div class="eve-card p-4" data-testid="season-operations">
        <p class="font-mono text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.season.kpi.operations') }}</p>
        <p class="mt-1 font-mono text-2xl text-slate-100">{{ formatWhole(season.operationsCount, locale) }}</p>
        <p v-if="season.unknownValueOperationsCount > 0" class="text-xs text-amber-300">{{ t('miningOperation.season.kpi.withoutPrice', { count: season.unknownValueOperationsCount }) }}</p>
      </div>
      <div class="eve-card p-4" data-testid="season-known-value">
        <p class="font-mono text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.season.kpi.knownValue') }}</p>
        <p class="mt-1 font-mono text-2xl" :class="season.knownValue === null ? 'text-amber-300' : 'text-slate-100'">{{ amountText(season.knownValue) }}</p>
      </div>
      <div class="eve-card p-4" data-testid="season-mined-hours">
        <p class="font-mono text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.season.kpi.minedHours') }}</p>
        <p class="mt-1 font-mono text-2xl text-slate-100">{{ formatSeasonDuration(season.playedMinutes) }}</p>
        <p class="text-xs text-slate-400">{{ t('miningOperation.season.kpi.players', { count: season.players.length }) }}</p>
      </div>
      <div class="eve-card p-4" data-testid="season-isk-per-hour">
        <p class="font-mono text-xs uppercase tracking-wider text-slate-400">{{ t('miningOperation.season.kpi.iskPerHour') }}</p>
        <p class="mt-1 font-mono text-2xl" :class="season.iskPerHour === null ? 'text-amber-300' : 'text-slate-100'">{{ amountText(season.iskPerHour) }}</p>
      </div>
    </div>

    <p v-if="season.operationsCount === 0" class="eve-card p-6 text-sm text-slate-300">{{ t('miningOperation.season.empty') }}</p>

    <div v-else class="eve-card overflow-x-auto p-2">
      <h2 class="px-3 py-2 font-display text-base font-semibold text-slate-100">{{ t('miningOperation.season.players.title') }}</h2>
      <table class="w-full text-left text-sm">
        <thead>
          <tr class="border-b border-slate-800 font-mono text-xs uppercase tracking-wider text-slate-400">
            <th class="px-3 py-2 font-medium">{{ t('miningOperation.season.players.columns.player') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('miningOperation.season.players.columns.operations') }}</th>
            <th class="w-1/3 px-3 py-2 font-medium">{{ t('miningOperation.season.players.columns.hours') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('miningOperation.season.players.columns.minedValue') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="player in season.players" :key="player.name" class="border-b border-slate-800/60" data-testid="season-player-row">
            <td class="max-w-56 px-3 py-2.5 font-display font-semibold text-slate-100 [overflow-wrap:anywhere]" data-testid="season-player-name">{{ player.name }}</td>
            <td class="px-3 py-2.5 text-right font-mono text-slate-300">{{ player.operationsCount }}</td>
            <td class="px-3 py-2.5">
              <span class="block font-mono text-xs text-cyan-200">{{ formatSeasonDuration(player.minutes) }}</span>
              <span class="mt-1 block h-1.5 rounded-sm bg-slate-800" aria-hidden="true">
                <span class="block h-full rounded-sm bg-cyan-400" :style="{ width: barWidth(player.minutes) }" />
              </span>
            </td>
            <td class="px-3 py-2.5 text-right font-mono">
              <span :class="player.minedValue === null ? 'text-amber-300' : 'text-slate-100'">{{ amountText(player.minedValue) }}</span>
              <span v-if="player.hasUnknownValue" class="block text-xs text-amber-300">{{ t('miningOperation.season.players.plusUnknown') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
