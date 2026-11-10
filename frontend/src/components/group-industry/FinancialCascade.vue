<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useFormatters } from '@/composables/useFormatters'
import UnknownValue from '@/components/common/UnknownValue.vue'

type CascadeStepKey = 'revenue' | 'brokerFee' | 'salesTax' | 'materials' | 'jobInstall' | 'bpc' | 'lineRental' | 'courier'

type CascadeStep = {
  key: CascadeStepKey
  isCost: boolean
  amount: number | null
}

const WATERFALL_WIDTH = 1100
const WATERFALL_HEIGHT = 260
const WATERFALL_PADDING = 20
const WATERFALL_TOP = 24
const WATERFALL_BOTTOM = 34
const BAR_WIDTH_RATIO = 0.56
const MINIMUM_BAR_HEIGHT = 1
const LABEL_OFFSET = 6

const { t } = useI18n()
const distStore = useGroupDistributionStore()
const projectStore = useGroupProjectStore()
const { formatIsk } = useFormatters()

const dist = computed(() => distStore.distribution)
const isPersonalUse = computed(() => projectStore.currentProject?.personalUse === true)

const GAUGE_HEADROOM_RATIO = 1.25

const breakEvenPerUnit = computed((): number | null => dist.value?.breakEvenPerUnit ?? null)
const breakEvenUnknownReason = computed((): string | null => dist.value?.breakEvenUnknownReason ?? null)
const costPerUnit = computed((): number | null => dist.value?.costPerUnit ?? null)
const costPerUnitUnknownReason = computed((): string | null => dist.value?.costPerUnitUnknownReason ?? null)
const totalProjectCost = computed((): number | null => dist.value?.totalProjectCost ?? null)

const averagePriceObtained = computed((): number | null => {
  const sales = distStore.sales
  const units = sales.reduce((sum, sale) => sum + sale.quantity, 0)
  if (units === 0) return null
  return sales.reduce((sum, sale) => sum + sale.totalPrice, 0) / units
})

const averagePriceSide = computed((): 'above' | 'below' | 'none' => {
  if (breakEvenPerUnit.value === null || averagePriceObtained.value === null) return 'none'
  return averagePriceObtained.value >= breakEvenPerUnit.value ? 'above' : 'below'
})

const gaugeMarkers = computed(() => {
  if (breakEvenPerUnit.value === null) return null
  const scale = Math.max(breakEvenPerUnit.value, averagePriceObtained.value ?? 0) * GAUGE_HEADROOM_RATIO
  const percentOf = (value: number): number => value / scale * 100
  return {
    breakEven: percentOf(breakEvenPerUnit.value),
    averagePrice: averagePriceObtained.value === null ? null : percentOf(averagePriceObtained.value),
  }
})

function sumOrUnknown(values: (number | null)[]): number | null {
  let sum = 0
  for (const value of values) {
    if (value === null) return null
    sum += value
  }
  return sum
}

const steps = computed((): CascadeStep[] => {
  if (!dist.value) return []
  const members = dist.value.members
  const salesSteps: CascadeStep[] = isPersonalUse.value ? [] : [
    { key: 'revenue', isCost: false, amount: dist.value.totalRevenue },
    { key: 'brokerFee', isCost: true, amount: dist.value.brokerFee },
    { key: 'salesTax', isCost: true, amount: dist.value.salesTax },
  ]
  const all: CascadeStep[] = [
    ...salesSteps,
    { key: 'materials', isCost: true, amount: sumOrUnknown(members.map(m => m.materialCosts)) },
    { key: 'jobInstall', isCost: true, amount: sumOrUnknown(members.map(m => m.jobInstallCosts)) },
    { key: 'bpc', isCost: true, amount: sumOrUnknown(members.map(m => m.bpcCosts)) },
    { key: 'lineRental', isCost: true, amount: sumOrUnknown(members.map(m => m.lineRentalCosts)) },
    { key: 'courier', isCost: true, amount: sumOrUnknown(members.map(m => m.courierCosts)) },
  ]
  const optionalKeys: CascadeStepKey[] = ['bpc', 'lineRental', 'courier']
  return all.filter(step => !(optionalKeys.includes(step.key) && step.amount === 0))
})

const profit = computed((): number | null => {
  if (!dist.value || dist.value.netRevenue === null || dist.value.totalProjectCost === null) return null
  return dist.value.netRevenue - dist.value.totalProjectCost
})

const isLoss = computed(() => profit.value !== null && profit.value < 0)

const everythingKnown = computed(() => profit.value !== null && steps.value.every(step => step.amount !== null))

function signedAmount(step: CascadeStep): string {
  return formatIsk(step.isCost ? -(step.amount as number) : step.amount)
}

const profitClass = computed(() => {
  if (profit.value === null) return 'text-slate-500'
  return isLoss.value ? 'text-red-400' : 'text-emerald-400'
})

type WaterfallBar = {
  key: string
  x: number
  y: number
  width: number
  height: number
  fill: string
  result: 'profit' | 'loss' | null
  label: string
  labelY: number
  caption: string
}

type Waterfall = {
  bars: WaterfallBar[]
  zeroLineY: number
}

const waterfall = computed((): Waterfall | null => {
  if (isPersonalUse.value || !everythingKnown.value || profit.value === null) return null
  const columns = steps.value.length + 1
  const levels = [0]
  let level = 0
  for (const step of steps.value) {
    level += step.isCost ? -(step.amount as number) : (step.amount as number)
    levels.push(level)
  }
  const highest = Math.max(...levels)
  const lowest = Math.min(0, ...levels)
  const span = highest - lowest || 1
  const plotHeight = WATERFALL_HEIGHT - WATERFALL_TOP - WATERFALL_BOTTOM
  const yOf = (value: number): number => WATERFALL_TOP + plotHeight * (highest - value) / span
  const slot = (WATERFALL_WIDTH - WATERFALL_PADDING * 2) / columns
  const barWidth = slot * BAR_WIDTH_RATIO
  const xOf = (index: number): number => WATERFALL_PADDING + index * slot + (slot - barWidth) / 2

  const bars: WaterfallBar[] = steps.value.map((step, index) => {
    const from = levels[index]
    const to = levels[index + 1]
    const top = yOf(Math.max(from, to))
    const bottom = yOf(Math.min(from, to))
    return {
      key: step.key,
      x: xOf(index),
      y: top,
      width: barWidth,
      height: Math.max(MINIMUM_BAR_HEIGHT, bottom - top),
      fill: step.isCost ? '#ff7d73' : '#2fd0f0',
      result: null,
      label: signedAmount(step),
      labelY: top - LABEL_OFFSET,
      caption: t(`groupIndustry.financialCascade.${step.key}`),
    }
  })

  const resultTop = yOf(Math.max(level, 0))
  const resultBottom = yOf(Math.min(level, 0))
  bars.push({
    key: 'profit',
    x: xOf(steps.value.length),
    y: resultTop,
    width: barWidth,
    height: Math.max(MINIMUM_BAR_HEIGHT, resultBottom - resultTop),
    fill: level >= 0 ? '#46c35a' : '#ff7d73',
    result: isLoss.value ? 'loss' : 'profit',
    label: `${profit.value >= 0 ? '+' : ''}${formatIsk(profit.value)}`,
    labelY: level >= 0 ? resultTop - LABEL_OFFSET : resultBottom + LABEL_OFFSET * 2,
    caption: t(isLoss.value ? 'groupIndustry.financialCascade.loss' : 'groupIndustry.financialCascade.profit'),
  })
  return { bars, zeroLineY: yOf(0) }
})
</script>

<template>
  <div v-if="dist" class="mb-8">
    <h2 class="text-lg font-semibold text-slate-100 mb-4">{{ t('groupIndustry.financialCascade.title') }}</h2>
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-6 space-y-4">
      <dl class="grid grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-3">
        <div
          v-for="step in steps"
          :key="step.key"
          :data-testid="`cascade-step-${step.key}`"
          class="flex items-center justify-between"
        >
          <dt class="text-sm text-slate-400">{{ t(`groupIndustry.financialCascade.${step.key}`) }}</dt>
          <dd
            class="font-mono text-sm"
            :class="step.isCost ? 'text-red-400' : 'text-slate-100'"
            style="font-variant-numeric: tabular-nums;"
          >
            <UnknownValue v-if="step.amount === null" /><template v-else>{{ signedAmount(step) }}</template>
          </dd>
        </div>
        <div
          v-if="isPersonalUse"
          class="flex items-center justify-between border-t border-slate-700 pt-2"
        >
          <dt class="text-sm font-semibold text-slate-200">{{ t('groupIndustry.financialCascade.personalUse.totalCost') }}</dt>
          <dd data-testid="personal-use-total-cost" class="font-mono text-lg font-bold text-slate-100" style="font-variant-numeric: tabular-nums;">
            <UnknownValue v-if="totalProjectCost === null" /><template v-else>{{ formatIsk(totalProjectCost) }}</template>
          </dd>
        </div>
        <div
          v-else
          data-testid="cascade-step-profit"
          class="flex items-center justify-between border-t border-slate-700 pt-2"
          :class="profitClass"
        >
          <dt class="text-sm font-semibold">{{ t(isLoss ? 'groupIndustry.financialCascade.loss' : 'groupIndustry.financialCascade.profit') }}</dt>
          <dd class="font-mono text-lg font-bold" style="font-variant-numeric: tabular-nums;">
            <UnknownValue v-if="profit === null" /><template v-else>{{ profit >= 0 ? '+' : '' }}{{ formatIsk(profit) }}</template>
          </dd>
        </div>
      </dl>

      <div v-if="isPersonalUse" class="space-y-1">
        <p v-if="costPerUnit === null" data-testid="personal-use-unknown" class="text-xs text-amber-400">
          <UnknownValue />
          <template v-if="costPerUnitUnknownReason"> {{ t(`groupIndustry.financialCascade.breakEven.unknown.${costPerUnitUnknownReason}`) }}</template>
        </p>
        <div v-else class="flex items-center gap-3 text-sm">
          <span class="text-slate-400">{{ t('groupIndustry.financialCascade.personalUse.costPerUnit') }}</span>
          <span data-testid="personal-use-cost-per-unit" class="font-mono text-slate-100" style="font-variant-numeric: tabular-nums;">{{ formatIsk(costPerUnit) }}</span>
        </div>
      </div>

      <svg
        v-else-if="waterfall"
        class="w-full"
        :viewBox="`0 0 ${WATERFALL_WIDTH} ${WATERFALL_HEIGHT}`"
        role="img"
        :aria-label="t('groupIndustry.financialCascade.waterfallLabel')"
      >
        <line
          :x1="WATERFALL_PADDING"
          :x2="WATERFALL_WIDTH - WATERFALL_PADDING"
          :y1="waterfall.zeroLineY"
          :y2="waterfall.zeroLineY"
          stroke="rgba(166,181,194,.55)"
        />
        <g v-for="bar in waterfall.bars" :key="bar.key">
          <rect
            :data-testid="`cascade-bar-${bar.key}`"
            :data-result="bar.result"
            :x="bar.x"
            :y="bar.y"
            :width="bar.width"
            :height="bar.height"
            :fill="bar.fill"
            opacity="0.8"
          />
          <text :x="bar.x + bar.width / 2" :y="bar.labelY" text-anchor="middle" fill="#cbd5e1" font-size="13">{{ bar.label }}</text>
          <text :x="bar.x + bar.width / 2" :y="WATERFALL_HEIGHT - 8" text-anchor="middle" fill="#94a3b8" font-size="12">{{ bar.caption.toUpperCase() }}</text>
        </g>
      </svg>
      <p v-else class="text-xs text-amber-400">{{ t('groupIndustry.financialCascade.unavailable') }}</p>

      <div v-if="!isPersonalUse" data-testid="break-even" class="border-t border-slate-800 pt-4 space-y-3">
        <h3 class="text-sm font-semibold text-slate-200">{{ t('groupIndustry.financialCascade.breakEven.title') }}</h3>
        <p v-if="breakEvenPerUnit === null" data-testid="break-even-unknown" class="text-xs text-amber-400">
          <UnknownValue />
          <template v-if="breakEvenUnknownReason"> {{ t(`groupIndustry.financialCascade.breakEven.unknown.${breakEvenUnknownReason}`) }}</template>
        </p>
        <template v-else>
          <div class="flex flex-wrap gap-x-8 gap-y-1 text-sm">
            <div class="flex items-center gap-3">
              <span class="text-slate-400">{{ t('groupIndustry.financialCascade.breakEven.perUnit') }}</span>
              <span data-testid="break-even-value" class="font-mono text-slate-100" style="font-variant-numeric: tabular-nums;">{{ formatIsk(breakEvenPerUnit) }}</span>
            </div>
            <div v-if="averagePriceObtained !== null" class="flex items-center gap-3">
              <span class="text-slate-400">{{ t('groupIndustry.financialCascade.breakEven.averagePrice') }}</span>
              <span
                data-testid="break-even-average-price"
                class="font-mono"
                :class="averagePriceSide === 'above' ? 'text-emerald-400' : 'text-red-400'"
                style="font-variant-numeric: tabular-nums;"
              >{{ formatIsk(averagePriceObtained) }}</span>
            </div>
          </div>
          <div
            v-if="gaugeMarkers"
            data-testid="break-even-gauge"
            :data-average-price-side="averagePriceSide"
            role="img"
            :aria-label="t('groupIndustry.financialCascade.breakEven.gaugeLabel')"
            class="relative h-2 rounded bg-slate-800"
          >
            <div class="absolute inset-y-0 left-0 rounded-l bg-red-400/40" :style="{ width: `${gaugeMarkers.breakEven}%` }" />
            <div class="absolute -top-1 -bottom-1 w-0.5 bg-slate-200" :style="{ left: `${gaugeMarkers.breakEven}%` }" />
            <div
              v-if="gaugeMarkers.averagePrice !== null"
              class="absolute -top-1 -bottom-1 w-1 rounded"
              :class="averagePriceSide === 'above' ? 'bg-emerald-400' : 'bg-red-400'"
              :style="{ left: `${gaugeMarkers.averagePrice}%` }"
            />
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
