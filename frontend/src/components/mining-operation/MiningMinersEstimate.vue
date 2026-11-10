<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningMinersEstimate, MiningMinersEstimateType } from '@/stores/miningOperation'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatDuration, formatEveClock, formatEveDate } from '@/views/MiningOperation/miningOperationTime'

const SECONDS_PER_MINUTE = 60

const props = defineProps<{ estimate: MiningMinersEstimate }>()

const { t, locale } = useI18n()

const selectedTypeId = ref<number | null>(null)
const activeType = computed<MiningMinersEstimateType | null>(() => props.estimate.types.find(type => type.typeId === selectedTypeId.value) ?? props.estimate.types[0] ?? null)

function typeLabel(type: MiningMinersEstimateType): string {
  return type.typeName ?? t('common.unknownTypeName', { typeId: type.typeId })
}

function durationText(minutes: number): string {
  return formatDuration(Math.round(minutes * SECONDS_PER_MINUTE))
}

const neededText = computed(() => {
  const type = activeType.value
  if (type === null || type.minersNeeded === null || type.deadline === null || type.availableMinutes === null) return ''
  return t(
    'miningOperation.minersEstimate.needed',
    { type: typeLabel(type), miners: type.minersNeeded, deadline: formatEveClock(type.deadline), start: formatEveClock(props.estimate.startAt), available: durationText(type.availableMinutes) },
    type.minersNeeded,
  )
})
const calculationText = computed(() => {
  const work = activeType.value?.workMinerMinutes
  return work === null || work === undefined ? '' : t('miningOperation.minersEstimate.calculation', { work: formatWhole(work, locale.value) })
})
</script>

<template>
  <section class="eve-card space-y-3 p-5" data-testid="miners-estimate" :data-active-type-id="activeType?.typeId">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.minersEstimate.title') }}</h2>
        <p class="text-xs text-slate-400">{{ t('miningOperation.minersEstimate.subtitle') }}</p>
      </div>
      <div v-if="activeType !== null" class="flex flex-wrap gap-1" role="tablist" :aria-label="t('miningOperation.minersEstimate.tabs')">
        <button
          v-for="type in estimate.types"
          :key="type.typeId"
          type="button"
          role="tab"
          class="rounded-md border px-3 py-1 text-xs"
          :class="type.typeId === activeType.typeId ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-200' : 'border-slate-700 text-slate-300 hover:bg-slate-800'"
          :aria-selected="type.typeId === activeType.typeId"
          data-testid="miners-type-tab"
          :data-type-id="type.typeId"
          @click="selectedTypeId = type.typeId"
        >
          {{ typeLabel(type) }}
        </button>
      </div>
    </div>

    <p v-if="activeType === null" class="text-sm text-slate-400" data-testid="miners-empty">{{ t('miningOperation.minersEstimate.empty') }}</p>

    <template v-else>
      <p v-if="activeType.status === 'insufficient_history'" class="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-200" data-testid="miners-insufficient" role="status">
        {{ t('miningOperation.minersEstimate.insufficient', { count: activeType.knownSiteCount }, activeType.knownSiteCount) }}
      </p>
      <template v-else>
        <p class="text-sm text-slate-200" data-testid="miners-needed" :data-miners="activeType.minersNeeded">{{ neededText }}</p>
        <div class="grid grid-cols-3 gap-2 md:grid-cols-6">
          <div
            v-for="cell in activeType.durations"
            :key="cell.miners"
            class="rounded-md border p-2"
            :class="cell.miners === activeType.minersNeeded ? 'border-cyan-500/50' : 'border-slate-700'"
            data-testid="miners-duration"
            :data-miners="cell.miners"
            :data-before-deadline="String(cell.beforeDeadline)"
          >
            <div class="text-xs text-slate-400">{{ t('miningOperation.minersEstimate.crew', { count: cell.miners }, cell.miners) }}</div>
            <div class="font-mono text-lg text-slate-100">{{ durationText(cell.minutes) }}</div>
            <div class="text-xs" :class="cell.beforeDeadline ? 'text-emerald-300' : 'text-slate-400'">
              {{ cell.beforeDeadline ? t('miningOperation.minersEstimate.beforeDowntime') : t('miningOperation.minersEstimate.afterDowntime') }}
            </div>
          </div>
        </div>
        <p class="font-mono text-xs text-slate-400">{{ calculationText }}</p>
      </template>

      <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-400">
        <span>{{ t('miningOperation.minersEstimate.sample', { count: activeType.sites.length }, activeType.sites.length) }}</span>
        <span>{{ t('miningOperation.minersEstimate.assumption') }}</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="text-xs uppercase tracking-wider text-slate-400">
            <tr>
              <th class="py-1 pr-3">{{ t('miningOperation.minersEstimate.columns.date') }}</th>
              <th class="py-1 pr-3">{{ t('miningOperation.minersEstimate.columns.site') }}</th>
              <th class="py-1 pr-3 text-right">{{ t('miningOperation.minersEstimate.columns.miners') }}</th>
              <th class="py-1 pr-3 text-right">{{ t('miningOperation.minersEstimate.columns.lifespan') }}</th>
              <th class="py-1 text-right">{{ t('miningOperation.minersEstimate.columns.work') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="site in activeType.sites" :key="`${site.label}-${site.firstScanAt}`" class="border-t border-slate-800" data-testid="miners-site-row" :data-unknown-reason="site.unknownReason ?? undefined">
              <td class="py-1 pr-3 font-mono text-slate-300">{{ formatEveDate(site.firstScanAt) }}</td>
              <td class="py-1 pr-3 text-slate-200">{{ site.label }}</td>
              <td class="py-1 pr-3 text-right font-mono text-slate-200">{{ site.miners }}</td>
              <td class="py-1 pr-3 text-right font-mono text-slate-200">{{ durationText(site.lifespanMinutes) }}</td>
              <td class="py-1 text-right font-mono" :class="site.workMinerMinutes === null ? 'text-amber-300' : 'text-slate-200'" data-testid="miners-site-work" :data-unknown="String(site.workMinerMinutes === null)">
                {{ site.workMinerMinutes === null ? t('miningOperation.minersEstimate.noMiner') : formatWhole(site.workMinerMinutes, locale) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>
