<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { MiningStats } from '@/stores/ledger'
import IskAmount from '@/components/common/IskAmount.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'

defineProps<{
  iskPerActiveDay: number | null
  activeDays: number
  miningStats: MiningStats | null
  loading: boolean
  mainSystem: { name: string; value: number; percent: number | null }
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
</script>

<template>
  <!-- Mining Stats - Row 2: Activity -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.iskPerActiveDay') }}</span>
      <div class="mt-1 text-xl font-bold text-cyan-400"><IskAmount :value="iskPerActiveDay" :loading="loading" short /></div>
      <div class="text-xs text-slate-500">{{ t('ledger.mining.activeDays', { count: activeDays }) }}</div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.minedVolume') }}</span>
      <div class="mt-1 text-xl font-bold text-white">
        <UnknownValue v-if="loading || miningStats === null" :loading="loading" />
        <template v-else>{{ formatNumber(miningStats.totals.totalQuantity) }}</template>
      </div>
      <div class="text-xs text-slate-500">{{ t('ledger.mining.units') }}</div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.mainSystem') }}</span>
      <div class="mt-1 text-xl font-bold text-white truncate">{{ mainSystem.name }}</div>
      <div class="text-xs text-slate-500">
        <UnknownValue v-if="mainSystem.percent === null" />
        <template v-else>{{ t('ledger.mining.percentOfVolume', { percent: mainSystem.percent.toFixed(0) }) }}</template>
      </div>
    </div>
  </div>
</template>
