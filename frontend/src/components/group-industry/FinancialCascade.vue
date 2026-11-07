<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import UnknownValue from '@/components/common/UnknownValue.vue'

type CascadeStepKey = 'revenue' | 'brokerFee' | 'salesTax' | 'materials' | 'jobInstall' | 'bpc' | 'lineRental'

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
const { formatIsk } = useFormatters()

const dist = computed(() => distStore.distribution)

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
  const all: CascadeStep[] = [
    { key: 'revenue', isCost: false, amount: dist.value.totalRevenue },
    { key: 'brokerFee', isCost: true, amount: dist.value.brokerFee },
    { key: 'salesTax', isCost: true, amount: dist.value.salesTax },
    { key: 'materials', isCost: true, amount: sumOrUnknown(members.map(m => m.materialCosts)) },
    { key: 'jobInstall', isCost: true, amount: sumOrUnknown(members.map(m => m.jobInstallCosts)) },
    { key: 'bpc', isCost: true, amount: sumOrUnknown(members.map(m => m.bpcCosts)) },
    { key: 'lineRental', isCost: true, amount: sumOrUnknown(members.map(m => m.lineRentalCosts)) },
  ]
  const optionalKeys: CascadeStepKey[] = ['bpc', 'lineRental']
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
  if (!everythingKnown.value || profit.value === null) return null
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

      <svg
        v-if="waterfall"
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
    </div>
  </div>
</template>
