<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { MiningStats } from '@/stores/ledger'

defineProps<{
  miningByUsage: {
    sold: number
    corp_project: number
    industry: number
    unknown: number
    total: number
  }
  iskPerActiveDay: number
  activeDays: number
  miningStats: MiningStats | null
  mainSystem: { name: string; value: number; percent: number }
}>()

const { t } = useI18n()
const { formatIskShort, formatNumber } = useFormatters()
</script>

<template>
  <!-- Mining Stats - Row 1: Values -->
  <div class="grid grid-cols-3 gap-3">
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.totalValue') }}</span>
      <div class="mt-1 text-xl font-bold text-amber-400">{{ formatIskShort(miningStats?.totals.totalValue ?? 0) }}</div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-emerald-500/20">
      <span class="text-emerald-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.personal') }}</span>
      <div class="mt-1 text-xl font-bold text-emerald-400">{{ formatIskShort(miningByUsage.sold) }}</div>
      <div class="text-xs text-slate-500">{{ miningByUsage.total > 0 ? ((miningByUsage.sold / miningByUsage.total) * 100).toFixed(0) : 0 }}%</div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-amber-500/20">
      <span class="text-amber-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.corp') }}</span>
      <div class="mt-1 text-xl font-bold text-amber-400">{{ formatIskShort(miningByUsage.corp_project) }}</div>
      <div class="text-xs text-slate-500">{{ miningByUsage.total > 0 ? ((miningByUsage.corp_project / miningByUsage.total) * 100).toFixed(0) : 0 }}%</div>
    </div>
  </div>

  <!-- Mining Stats - Row 2: Activity -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.iskPerActiveDay') }}</span>
      <div class="mt-1 text-xl font-bold text-cyan-400">{{ formatIskShort(iskPerActiveDay) }}</div>
      <div class="text-xs text-slate-500">{{ t('ledger.mining.activeDays', { count: activeDays }) }}</div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.minedVolume') }}</span>
      <div class="mt-1 text-xl font-bold text-white">{{ formatNumber(miningStats?.totals.totalQuantity || 0) }}</div>
      <div class="text-xs text-slate-500">{{ t('ledger.mining.units') }}</div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.mainSystem') }}</span>
      <div class="mt-1 text-xl font-bold text-white truncate">{{ mainSystem.name }}</div>
      <div class="text-xs text-slate-500">{{ t('ledger.mining.percentOfVolume', { percent: mainSystem.percent.toFixed(0) }) }}</div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.usage.unknown') }}</span>
      <div class="mt-1 text-xl font-bold text-slate-400">{{ formatIskShort(miningByUsage.unknown) }}</div>
      <div v-if="miningByUsage.unknown > 0" class="text-xs text-amber-400">{{ t('ledger.mining.toCategorize') }}</div>
      <div v-else class="text-xs text-emerald-400">{{ t('ledger.mining.allAssigned') }}</div>
    </div>
  </div>

  <!-- Repartition bar -->
  <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
    <div class="flex items-center justify-between mb-2">
      <span class="text-sm text-slate-400">{{ t('ledger.mining.oreBreakdown') }}</span>
      <span class="text-xs text-slate-500">{{ formatIskShort(miningStats?.totals.totalValue ?? 0) }} total</span>
    </div>
    <div class="h-3 flex rounded-full overflow-hidden bg-slate-700">
      <div
        v-if="miningByUsage.sold > 0"
        class="bg-emerald-500 h-full transition-all"
        :style="{ width: `${(miningByUsage.sold / miningByUsage.total) * 100}%` }"
        :title="`Personnel: ${formatIskShort(miningByUsage.sold)}`"
      />
      <div
        v-if="miningByUsage.corp_project > 0"
        class="bg-amber-500 h-full transition-all"
        :style="{ width: `${(miningByUsage.corp_project / miningByUsage.total) * 100}%` }"
        :title="`Corpo: ${formatIskShort(miningByUsage.corp_project)}`"
      />
      <div
        v-if="miningByUsage.unknown > 0"
        class="bg-slate-500 h-full transition-all"
        :style="{ width: `${(miningByUsage.unknown / miningByUsage.total) * 100}%` }"
        :title="`Non assigne: ${formatIskShort(miningByUsage.unknown)}`"
      />
    </div>
    <div class="flex flex-wrap justify-center gap-6 mt-2 text-xs">
      <div class="flex items-center gap-1">
        <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
        <span class="text-slate-400">{{ t('ledger.mining.personal') }}</span>
      </div>
      <div class="flex items-center gap-1">
        <div class="w-2 h-2 bg-amber-500 rounded-full"></div>
        <span class="text-slate-400">{{ t('ledger.mining.corp') }}</span>
      </div>
      <div class="flex items-center gap-1">
        <div class="w-2 h-2 bg-slate-500 rounded-full"></div>
        <span class="text-slate-400">{{ t('ledger.mining.usage.unknown') }}</span>
      </div>
    </div>
  </div>
</template>
