<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PartialPriceMarker from '@/components/industry/PartialPriceMarker.vue'
import type { PriceSource } from '@/stores/industry/types'

const props = withDefaults(defineProps<{
  source: PriceSource | null | undefined
  showCoverage?: boolean
}>(), {
  showCoverage: true,
})

const { t } = useI18n()

const coveragePercent = computed((): number | null => props.showCoverage ? (props.source?.coveragePercent ?? null) : null)

const label = computed((): string => {
  const source = props.source
  if (!source) return ''
  const params = {
    market: t(`industry.priceSource.markets.${source.market}`),
    method: t(`industry.priceSource.methods.${source.method}`, { percentile: source.percentile }),
  }
  return source.side === 'buy' ? t('industry.priceSource.labelBuy', params) : t('industry.priceSource.label', params)
})

const tooltip = computed((): string => {
  const source = props.source
  if (!source) return ''
  const description = t('industry.priceSource.tooltip', {
    market: t(`industry.priceSource.markets.${source.market}`),
    side: t(`industry.priceSource.sides.${source.side}`),
    method: t(`industry.priceSource.methodNames.${source.method}`, { percentile: source.percentile }),
  })
  return coveragePercent.value === null ? description : `${description}\n${t('industry.priceSource.tooltipCoverage', { percent: coveragePercent.value })}`
})
</script>

<template>
  <span v-if="source" class="inline-flex max-w-full min-w-0 items-baseline">
    <span class="truncate cursor-help font-mono text-xs font-normal uppercase tracking-wider text-slate-500" :title="tooltip">{{ label }}</span>
    <PartialPriceMarker :percent="coveragePercent" />
  </span>
</template>
