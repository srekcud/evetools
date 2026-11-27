<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import PiStockPin from '@/components/planetary/PiStockPin.vue'
import PiTierBadge from '@/components/planetary/PiTierBadge.vue'
import PiTierFilter from '@/components/planetary/PiTierFilter.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryStockTodo } from '@/composables/usePlanetaryStockTodo'
import { usePlanetaryTierFilter } from '@/composables/usePlanetaryTierFilter'
import { usePlanetaryTiers } from '@/composables/usePlanetaryTiers'
import { useTypeNames } from '@/composables/useTypeNames'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { usePlanetarySettingsStore } from '@/stores/planetarySettings'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'

const ICON_SIZE = 32
const PERCENT = 100
const COVERAGE_DECIMALS = 1

const emit = defineEmits<{ 'open-planner': [] }>()
const { t, locale } = useI18n()
const { formatNumber } = useFormatters()
const { typeNameLabel } = useTypeNames()
const { getTypeIconUrl, onImageError } = useEveImages()
const store = usePlanetaryStockStore()
const planner = usePlanetaryPlannerStore()
const settingsStore = usePlanetarySettingsStore()
const todo = usePlanetaryStockTodo()
const { tierOf } = usePlanetaryTiers()
const { selectedTier, matchesTier } = usePlanetaryTierFilter()

const visibleRows = computed(() => store.rows.filter(row => matchesTier(row.typeId)))

function weeksText(weeks: number): string {
  const formatted = weeks.toLocaleString(locale.value === 'fr' ? 'fr-FR' : 'en-US', {
    minimumFractionDigits: COVERAGE_DECIMALS,
    maximumFractionDigits: COVERAGE_DECIMALS,
  })

  return t('pi.stock.grid.weeksValue', { weeks: formatted })
}

function isBelowTarget(weeks: number | null): boolean {
  return weeks !== null && store.stock !== null && weeks < store.stock.coverageWeeks
}

function barWidth(weeks: number | null): string {
  if (weeks === null || store.stock === null || store.stock.coverageWeeks <= 0) return '0%'

  return `${Math.min(PERCENT, (weeks / store.stock.coverageWeeks) * PERCENT)}%`
}

function openPlanner(): void {
  emit('open-planner')
}

function excludeType(typeId: number): void {
  void settingsStore.excludeType(typeId)
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="pi-stock-grid">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.stock.grid.title') }}</h3>
    <PiTierFilter v-model="selectedTier" test-id="stock-tier-filter" class="mb-4" />
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs uppercase tracking-wider text-slate-500">
            <th class="pb-3 text-left font-medium">{{ t('pi.stock.grid.type') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.grid.home') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.grid.hub') }}</th>
            <th class="pb-3 text-right font-medium" :title="t('pi.stock.grid.customsOfficeHint')">{{ t('pi.stock.grid.customsOffice') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.grid.corp') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.grid.weeklyConsumption') }}</th>
            <th class="pb-3 text-left font-medium">{{ t('pi.stock.grid.coverage') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.grid.openOrders') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.grid.coverageWithOrders') }}</th>
            <th class="pb-3 text-right font-medium">{{ t('pi.stock.grid.toBuy') }}</th>
            <th class="pb-3 text-left font-medium">{{ t('pi.stock.grid.decision') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr v-for="row in visibleRows" :key="row.typeId" :data-testid="`stock-row-${row.typeId}`" class="transition-colors hover:bg-slate-800/30">
            <td class="py-2.5">
              <div class="flex items-center gap-2">
                <img :src="getTypeIconUrl(row.typeId, ICON_SIZE)" alt="" class="h-6 w-6 shrink-0 rounded-sm" @error="onImageError" />
                <span class="text-slate-100">{{ typeNameLabel(row.typeName, row.typeId) }}</span>
                <PiTierBadge :tier="tierOf(row.typeId)" />
              </div>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`stock-cell-home-${row.typeId}`">
              <UnknownValue v-if="row.home === null" />
              <template v-else>{{ formatNumber(row.home, 0) }}</template>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`stock-cell-hub-${row.typeId}`">
              {{ formatNumber(row.hub, 0) }}<PiStockPin kind="bringBack" :type-id="row.typeId" :number="todo.pins.bringBack[row.typeId]" />
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`stock-cell-customs-${row.typeId}`">{{ formatNumber(row.customsOffice, 0) }}</td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`stock-cell-corp-${row.typeId}`">
              <UnknownValue v-if="row.corp === null" />
              <template v-else>{{ formatNumber(row.corp, 0) }}</template>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`stock-cell-weeklyConsumption-${row.typeId}`">
              <UnknownValue v-if="row.weeklyConsumption === null" />
              <template v-else>{{ formatNumber(row.weeklyConsumption, 0) }}</template>
            </td>
            <td class="py-2.5" :data-testid="`stock-cell-coverage-${row.typeId}`">
              <UnknownValue v-if="row.coverageWeeks === null" />
              <div v-else class="flex items-center gap-2">
                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-800">
                  <div
                    :data-testid="`stock-coverage-bar-${row.typeId}`"
                    :class="['h-full rounded-full', isBelowTarget(row.coverageWeeks) ? 'bg-red-500' : 'bg-emerald-500']"
                    :style="{ width: barWidth(row.coverageWeeks) }"
                  ></div>
                </div>
                <span class="font-mono-tech text-slate-300">{{ weeksText(row.coverageWeeks) }}</span>
              </div>
            </td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`stock-cell-openOrders-${row.typeId}`">{{ formatNumber(row.openOrders, 0) }}</td>
            <td class="py-2.5 text-right font-mono-tech text-slate-300" :data-testid="`stock-cell-coverageWithOrders-${row.typeId}`">
              <UnknownValue v-if="row.coverageWithOrdersWeeks === null" />
              <div v-else class="flex items-center justify-end gap-2">
                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-800">
                  <div
                    :data-testid="`stock-coverage-orders-bar-${row.typeId}`"
                    :class="['h-full rounded-full', isBelowTarget(row.coverageWithOrdersWeeks) ? 'bg-red-500' : 'bg-emerald-500']"
                    :style="{ width: barWidth(row.coverageWithOrdersWeeks) }"
                  ></div>
                </div>
                <span>{{ weeksText(row.coverageWithOrdersWeeks) }}</span>
              </div>
            </td>
            <td class="py-2.5 text-right font-mono-tech font-medium text-cyan-400" :data-testid="`stock-cell-toBuy-${row.typeId}`">
              <UnknownValue v-if="row.toBuy === null" />
              <template v-else>{{ formatNumber(row.toBuy, 0) }}</template>
              <PiStockPin kind="toBuy" :type-id="row.typeId" :number="todo.pins.toBuy[row.typeId]" />
            </td>
            <td class="py-2.5" :data-testid="`stock-cell-decision-${row.typeId}`">
              <div class="flex items-center gap-2">
                <span v-if="planner.choices[row.typeId] === 'produce'" class="text-emerald-300">{{ t('pi.stock.grid.produce') }}</span>
                <span v-else-if="planner.choices[row.typeId] === 'buy'" class="text-amber-300">{{ t('pi.stock.grid.buy') }}</span>
                <span v-else class="text-slate-500">—</span>
                <button
                  type="button"
                  class="text-xs text-cyan-300 hover:text-cyan-200 hover:underline"
                  :data-testid="`stock-open-planner-${row.typeId}`"
                  @click="openPlanner"
                >{{ t('pi.stock.grid.openPlanner') }}</button>
                <button
                  type="button"
                  class="shrink-0 rounded p-1 text-slate-500 transition-colors hover:bg-red-500/10 hover:text-red-300"
                  :title="t('pi.stock.grid.exclude')"
                  :aria-label="t('pi.stock.grid.exclude')"
                  :data-testid="`stock-exclude-${row.typeId}`"
                  @click="excludeType(row.typeId)"
                >
                  <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" /></svg>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
