<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem } from '@/stores/group-industry/types'

defineProps<{
  item: BomItem
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
</script>

<template>
  <div
    v-if="item.unclaimedQuantity != null && item.unclaimedQuantity > 0"
    data-testid="unclaimed-stock-badge"
    class="mt-1 inline-flex flex-wrap items-center gap-x-1.5 rounded border border-amber-500/30 bg-amber-500/10 px-1.5 py-0.5 text-xs text-amber-300"
    :title="t('groupIndustry.bom.unclaimedStockHint')"
  >
    <span class="font-mono">{{ t('groupIndustry.bom.unclaimedStock', { quantity: formatNumber(item.unclaimedQuantity, 0) }) }}</span>
    <span
      v-if="item.unclaimedValue != null"
      data-testid="unclaimed-stock-value"
      class="font-mono text-amber-200/80"
    >{{ t('groupIndustry.bom.unclaimedStockValue', { value: formatIsk(item.unclaimedValue, 1) }) }}</span>
    <span
      v-else
      data-testid="unclaimed-stock-launch-price-unknown"
      class="text-amber-200/80"
    >{{ t('groupIndustry.bom.unclaimedStockLaunchPriceUnknown') }}</span>
  </div>
</template>
