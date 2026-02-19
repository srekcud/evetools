<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

const props = defineProps<{
  jitaSell: number | null
  jitaBuy: number | null
  structureSell: number | null
  structureBuy: number | null
  structureName?: string
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

const jitaSpread = computed(() => {
  if (props.jitaSell && props.jitaBuy) {
    return ((1 - props.jitaBuy / props.jitaSell) * 100).toFixed(1) + '%'
  }
  return '---'
})

const structureSpread = computed(() => {
  if (props.structureSell && props.structureBuy) {
    return ((1 - props.structureBuy / props.structureSell) * 100).toFixed(1) + '%'
  }
  return '--'
})

function formatDifference(structurePrice: number | null, jitaPrice: number | null): string {
  if (structurePrice === null || jitaPrice === null || jitaPrice === 0) return 'N/A'
  const diff = structurePrice - jitaPrice
  const pct = ((diff / jitaPrice) * 100).toFixed(1)
  const sign = diff >= 0 ? '+' : ''
  return `${sign}${formatIsk(diff)} (${sign}${pct}%)`
}

const diffSellColor = computed(() => {
  if (props.structureSell === null || props.jitaSell === null) return 'text-slate-600'
  return props.structureSell > props.jitaSell ? 'text-red-400' : 'text-emerald-400'
})

const diffBuyColor = computed(() => {
  if (props.structureBuy === null || props.jitaBuy === null) return 'text-slate-600'
  return props.structureBuy > props.jitaBuy ? 'text-emerald-400' : 'text-red-400'
})

const displayStructureName = computed(() => {
  return props.structureName || t('market.detail.sourceStructure')
})
</script>

<template>
  <div>
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr class="border-b border-slate-800/50">
            <th class="text-left px-4 py-2.5 text-xs text-slate-600 font-medium">Source</th>
            <th class="text-right px-4 py-2.5 text-xs text-slate-600 font-medium">{{ t('market.detail.sell') }}</th>
            <th class="text-right px-4 py-2.5 text-xs text-slate-600 font-medium">{{ t('market.detail.buy') }}</th>
            <th class="text-right px-4 py-2.5 text-xs text-slate-600 font-medium">{{ t('market.detail.spread') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/30">
          <!-- Jita row -->
          <tr class="hover:bg-cyan-500/5 transition-colors">
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-cyan-500"></div>
                <span class="text-sm text-slate-300">Jita 4-4 CNAP</span>
              </div>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-cyan-400">
              <template v-if="jitaSell !== null">{{ formatIsk(jitaSell) }}</template>
              <template v-else><span class="text-slate-600">--</span></template>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-amber-400">
              <template v-if="jitaBuy !== null">{{ formatIsk(jitaBuy) }}</template>
              <template v-else><span class="text-slate-600">--</span></template>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-slate-400">{{ jitaSpread }}</td>
          </tr>
          <!-- Structure row -->
          <tr class="hover:bg-cyan-500/5 transition-colors">
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-indigo-500"></div>
                <span class="text-sm text-slate-300">{{ displayStructureName }}</span>
              </div>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm">
              <template v-if="structureSell !== null">
                <span class="text-cyan-400">{{ formatIsk(structureSell) }}</span>
              </template>
              <template v-else><span class="text-slate-600 text-xs italic">{{ t('market.detail.noOrders') }}</span></template>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm">
              <template v-if="structureBuy !== null">
                <span class="text-amber-400">{{ formatIsk(structureBuy) }}</span>
              </template>
              <template v-else><span class="text-slate-600 text-xs italic">{{ t('market.detail.noOrders') }}</span></template>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-slate-400">{{ structureSpread }}</td>
          </tr>
          <!-- Difference row -->
          <tr class="border-t border-slate-700/30">
            <td class="px-4 py-3">
              <span class="text-xs text-slate-500 italic">{{ t('market.detail.difference') }}</span>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm" :class="diffSellColor">
              {{ formatDifference(structureSell, jitaSell) }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm" :class="diffBuyColor">
              {{ formatDifference(structureBuy, jitaBuy) }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-slate-600"></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="px-4 py-2.5 border-t border-slate-800/30 bg-slate-800/20">
      <span class="text-xs text-slate-500">{{ t('market.detail.structureNote') }}</span>
    </div>
  </div>
</template>
