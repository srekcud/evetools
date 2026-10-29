<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { MiningStats } from '@/stores/ledger'
import type { MiningValueByUsage } from '@/composables/useMiningPricing'
import IskAmount from '@/components/common/IskAmount.vue'
import UnknownValue from '@/components/common/UnknownValue.vue'

const props = defineProps<{
  miningByUsage: MiningValueByUsage
  iskPerActiveDay: number | null
  activeDays: number
  miningStats: MiningStats | null
  loading: boolean
  mainSystem: { name: string; value: number; percent: number | null }
  missingPriceTypeLabels: string[]
}>()

const { t } = useI18n()
const { formatIskShort, formatNumber } = useFormatters()

function shareOfTotal(value: number | null): number | null {
  const total = props.miningByUsage.total
  if (value === null || total === null) return null
  return total > 0 ? (value / total) * 100 : 0
}

const shares = computed(() => ({
  sold: shareOfTotal(props.miningByUsage.sold),
  corpProject: shareOfTotal(props.miningByUsage.corp_project),
  unknown: shareOfTotal(props.miningByUsage.unknown),
}))
</script>

<template>
  <!-- Mining Stats - Row 1: Values -->
  <div class="grid grid-cols-3 gap-3">
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.totalValue') }}</span>
      <div class="mt-1 text-xl font-bold text-amber-400"><IskAmount :value="miningStats?.totals.totalValue ?? null" :loading="loading" short /></div>
      <div v-if="missingPriceTypeLabels.length > 0" class="text-xs text-amber-400">{{ t('ledger.mining.missingPriceTypes', { types: missingPriceTypeLabels.join(', ') }) }}</div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-emerald-500/20">
      <span class="text-emerald-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.personal') }}</span>
      <div class="mt-1 text-xl font-bold text-emerald-400"><IskAmount :value="miningByUsage.sold" short /></div>
      <div class="text-xs text-slate-500">
        <UnknownValue v-if="shares.sold === null" />
        <template v-else>{{ shares.sold.toFixed(0) }}%</template>
      </div>
    </div>
    <div class="bg-slate-900 rounded-xl p-4 border border-amber-500/20">
      <span class="text-amber-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.corp') }}</span>
      <div class="mt-1 text-xl font-bold text-amber-400"><IskAmount :value="miningByUsage.corp_project" short /></div>
      <div class="text-xs text-slate-500">
        <UnknownValue v-if="shares.corpProject === null" />
        <template v-else>{{ shares.corpProject.toFixed(0) }}%</template>
      </div>
    </div>
  </div>

  <!-- Mining Stats - Row 2: Activity -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
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
    <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
      <span class="text-slate-400 text-xs uppercase tracking-wider">{{ t('ledger.mining.usage.unknown') }}</span>
      <div class="mt-1 text-xl font-bold text-slate-400"><IskAmount :value="miningByUsage.unknown" short /></div>
      <div v-if="miningByUsage.unknown !== null && miningByUsage.unknown > 0" class="text-xs text-amber-400">{{ t('ledger.mining.toCategorize') }}</div>
      <div v-else-if="miningByUsage.unknown === 0" class="text-xs text-emerald-400">{{ t('ledger.mining.allAssigned') }}</div>
    </div>
  </div>

  <!-- Repartition bar -->
  <div class="bg-slate-900 rounded-xl p-4 border border-slate-800">
    <div class="flex items-center justify-between mb-2">
      <span class="text-sm text-slate-400">{{ t('ledger.mining.oreBreakdown') }}</span>
      <span class="text-xs text-slate-500"><IskAmount :value="miningStats?.totals.totalValue ?? null" :loading="loading" short /> total</span>
    </div>
    <div class="h-3 flex rounded-full overflow-hidden bg-slate-700">
      <div
        v-if="shares.sold !== null && shares.sold > 0"
        class="bg-emerald-500 h-full transition-all"
        :style="{ width: `${shares.sold}%` }"
        :title="`Personnel: ${formatIskShort(miningByUsage.sold)}`"
      />
      <div
        v-if="shares.corpProject !== null && shares.corpProject > 0"
        class="bg-amber-500 h-full transition-all"
        :style="{ width: `${shares.corpProject}%` }"
        :title="`Corpo: ${formatIskShort(miningByUsage.corp_project)}`"
      />
      <div
        v-if="shares.unknown !== null && shares.unknown > 0"
        class="bg-slate-500 h-full transition-all"
        :style="{ width: `${shares.unknown}%` }"
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
