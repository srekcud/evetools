<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

const props = defineProps<{
  jitaSell: number | null
  jitaBuy: number | null
  structureSell: number | null
  structureBuy: number | null
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

interface PriceRow {
  source: string
  sell: number | null
  buy: number | null
  spread: string
}

const rows = computed<PriceRow[]>(() => {
  const result: PriceRow[] = [
    {
      source: 'Jita',
      sell: props.jitaSell,
      buy: props.jitaBuy,
      spread: props.jitaSell && props.jitaBuy
        ? ((1 - props.jitaBuy / props.jitaSell) * 100).toFixed(1) + '%'
        : '---',
    },
  ]

  if (props.structureSell !== null || props.structureBuy !== null) {
    result.push({
      source: 'Structure',
      sell: props.structureSell,
      buy: props.structureBuy,
      spread: props.structureSell && props.structureBuy
        ? ((1 - props.structureBuy / props.structureSell) * 100).toFixed(1) + '%'
        : '---',
    })
  }

  return result
})

const cheapestSellIdx = computed(() => {
  let min = Infinity
  let idx = -1
  rows.value.forEach((r, i) => {
    if (r.sell !== null && r.sell < min) {
      min = r.sell
      idx = i
    }
  })
  return idx
})

const highestBuyIdx = computed(() => {
  let max = -Infinity
  let idx = -1
  rows.value.forEach((r, i) => {
    if (r.buy !== null && r.buy > max) {
      max = r.buy
      idx = i
    }
  })
  return idx
})
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-700">
          <th class="text-left px-3 py-2 text-slate-400 font-medium text-xs">Source</th>
          <th class="text-right px-3 py-2 text-slate-400 font-medium text-xs">{{ t('market.detail.sell') }}</th>
          <th class="text-right px-3 py-2 text-slate-400 font-medium text-xs">{{ t('market.detail.buy') }}</th>
          <th class="text-right px-3 py-2 text-slate-400 font-medium text-xs">{{ t('market.detail.spread') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800/50">
        <tr v-for="(row, i) in rows" :key="row.source">
          <td class="px-3 py-2 text-slate-300 font-medium">{{ row.source }}</td>
          <td class="px-3 py-2 text-right font-mono">
            <span
              v-if="row.sell !== null"
              :class="i === cheapestSellIdx && rows.length > 1 ? 'text-cyan-400 font-semibold' : 'text-slate-300'"
            >
              {{ formatIsk(row.sell) }}
            </span>
            <span v-else class="text-slate-600">N/A</span>
          </td>
          <td class="px-3 py-2 text-right font-mono">
            <span
              v-if="row.buy !== null"
              :class="i === highestBuyIdx && rows.length > 1 ? 'text-emerald-400 font-semibold' : 'text-slate-300'"
            >
              {{ formatIsk(row.buy) }}
            </span>
            <span v-else class="text-slate-600">N/A</span>
          </td>
          <td class="px-3 py-2 text-right font-mono text-slate-400">{{ row.spread }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
