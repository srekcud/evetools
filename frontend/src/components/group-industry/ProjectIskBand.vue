<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { IskBand, IskBandCell } from './projectIskBand'

defineProps<{
  band: IskBand
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

const UNKNOWN_HATCH = 'bg-[repeating-linear-gradient(45deg,transparent_0_6px,rgba(100,116,139,0.13)_6px_12px)]'
const ESTIMATE_VALUE = 'border-b border-dashed border-slate-500 text-slate-300'
const SIGNED_KEYS: IskBandCell['key'][] = ['profit']

function isUnknown(cell: IskBandCell): boolean {
  return cell.amount === null
}

function labelOf(cell: IskBandCell): string {
  if (cell.key !== 'profit') return t(`groupIndustry.iskBand.labels.${cell.key}`)
  if (cell.provisional) return t('groupIndustry.iskBand.labels.provisionalProfit')
  return t(cell.estimate ? 'groupIndustry.iskBand.labels.estimatedProfit' : 'groupIndustry.iskBand.labels.profit')
}

function noteOf(cell: IskBandCell): string {
  switch (cell.key) {
    case 'expected':
      return t('groupIndustry.iskBand.notes.expected', { market: cell.market ?? t('groupIndustry.sellForecast.jita') })
    case 'cashed':
      return cell.unsoldProductCount > 0
        ? t('groupIndustry.iskBand.notes.cashedUnsold', { count: cell.unsoldProductCount }, cell.unsoldProductCount)
        : t('groupIndustry.iskBand.notes.cashed')
    case 'profit':
      if (cell.provisional) return t('groupIndustry.iskBand.notes.provisionalProfit')
      return t(cell.estimate ? 'groupIndustry.iskBand.notes.estimatedProfit' : 'groupIndustry.iskBand.notes.profit')
    default:
      return t(`groupIndustry.iskBand.notes.${cell.key}`)
  }
}

function valueClassOf(cell: IskBandCell): string[] {
  if (cell.amount === null) return ['text-slate-500']
  const classes: string[] = []
  if (cell.estimate) classes.push(ESTIMATE_VALUE)
  if (SIGNED_KEYS.includes(cell.key) && !cell.estimate) classes.push(cell.amount < 0 ? 'text-red-400' : 'text-emerald-400')
  else if (!cell.estimate) classes.push('text-slate-100')
  return classes
}
</script>

<template>
  <div
    data-testid="isk-band"
    role="group"
    :aria-label="t('groupIndustry.iskBand.label')"
    class="mb-4 grid grid-cols-2 border border-slate-800 bg-slate-900/80 md:grid-cols-[repeat(var(--isk-band-cells),minmax(0,1fr))]"
    :style="{ '--isk-band-cells': band.cells.length }"
  >
    <div
      v-for="cell in band.cells"
      :key="cell.key"
      :data-testid="`isk-band-${cell.key}`"
      :data-estimate="cell.estimate && !isUnknown(cell) ? 'true' : undefined"
      :data-unknown="isUnknown(cell) ? 'true' : undefined"
      class="border-r border-slate-800 px-3.5 py-2.5 last:border-r-0"
      :class="isUnknown(cell) ? UNKNOWN_HATCH : ''"
    >
      <div class="font-display text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ labelOf(cell) }}</div>
      <div
        data-testid="isk-band-value"
        class="inline-block font-display text-[19px] font-bold tracking-[0.02em]"
        :class="valueClassOf(cell)"
        style="font-variant-numeric: tabular-nums;"
      >
        <span v-if="isUnknown(cell)" :title="t('groupIndustry.iskBand.unknown')">—</span>
        <template v-else>{{ formatIsk(cell.amount) }}</template>
      </div>
      <div class="text-xs text-slate-500">{{ noteOf(cell) }}</div>
    </div>
  </div>
</template>
