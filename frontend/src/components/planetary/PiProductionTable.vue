<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryHelpers } from '@/composables/usePlanetaryHelpers'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import type { ProductionTier } from '@/stores/planetary'

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const {
  getTierTotalVolume,
  getTierPercentage,
  getTierIskColor,
  getSupplyDeltaColor,
  getWorstDelta,
  formatDelta,
} = usePlanetaryHelpers()

const props = defineProps<{
  production: ProductionTier[]
  totalDailyIsk: number
}>()

const expandedTiers = ref<Set<string>>(new Set())

function toggleTier(tier: string): void {
  if (expandedTiers.value.has(tier)) {
    expandedTiers.value.delete(tier)
  } else {
    expandedTiers.value.add(tier)
  }
}
</script>

<template>
  <div v-if="props.production.length > 0" class="bg-slate-900 rounded-xl border border-slate-800 p-5">
    <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-4 flex items-center gap-2">
      <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
      {{ t('pi.production.title') }}
    </h3>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500 uppercase tracking-wider">
            <th class="text-left pb-3 font-medium w-6"></th>
            <th class="text-left pb-3 font-medium">{{ t('pi.production.tier') }}</th>
            <th class="text-right pb-3 font-medium">{{ t('pi.production.products') }}</th>
            <th class="text-right pb-3 font-medium">{{ t('pi.production.volumePerDay') }}</th>
            <th class="text-right pb-3 font-medium">
              <span class="cursor-help border-b border-dotted border-slate-600" :title="t('pi.production.jitaTooltip')">{{ t('pi.production.iskPerDay') }}</span>
            </th>
            <th class="text-right pb-3 font-medium">{{ t('pi.production.percentTotal') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <template v-for="tier in props.production" :key="tier.tier">
            <!-- Tier Row -->
            <tr
              class="cursor-pointer hover:bg-slate-800/30 transition-colors"
              :class="{ 'opacity-40': tier.items.length === 0 }"
              @click="toggleTier(tier.tier)"
            >
              <td class="py-2.5 pr-1">
                <svg
                  :class="['w-4 h-4 transition-transform duration-200', tier.items.length === 0 ? 'text-slate-600' : 'text-slate-500']"
                  :style="expandedTiers.has(tier.tier) ? 'transform: rotate(90deg)' : ''"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
              </td>
              <td class="py-2.5">
                <div class="flex items-center gap-2">
                  <span :class="['text-[10px] px-1.5 py-0.5 rounded-sm border', TIER_CONFIG[tier.tier]?.badgeBg, TIER_CONFIG[tier.tier]?.badgeText, TIER_CONFIG[tier.tier]?.badgeBorder]">
                    {{ tier.tier }}
                  </span>
                  <span class="text-slate-400">{{ TIER_CONFIG[tier.tier]?.labelKey ? t(TIER_CONFIG[tier.tier].labelKey) : tier.tier }}</span>
                </div>
              </td>
              <td class="py-2.5 text-right" :class="tier.items.length > 0 ? 'text-white font-medium' : 'text-slate-500'">
                {{ tier.items.length > 0 ? tier.items.length : '0' }}
              </td>
              <td class="py-2.5 text-right" :class="tier.items.length > 0 ? 'text-slate-300 font-mono-tech' : 'text-slate-500'">
                {{ tier.items.length > 0 ? formatNumber(getTierTotalVolume(tier), 0) : '-' }}
              </td>
              <td class="py-2.5 text-right" :class="tier.dailyIskValue > 0 ? getTierIskColor(tier.tier) + ' font-medium' : 'text-slate-500'">
                {{ tier.dailyIskValue > 0 ? formatIsk(tier.dailyIskValue) : '-' }}
              </td>
              <td class="py-2.5 text-right" :class="tier.dailyIskValue > 0 ? 'text-slate-300' : 'text-slate-500'">
                {{ tier.dailyIskValue > 0 ? getTierPercentage(tier.dailyIskValue, props.totalDailyIsk) : '-' }}
              </td>
            </tr>

            <!-- Tier Detail -->
            <tr v-if="expandedTiers.has(tier.tier) && tier.items.length > 0">
              <td></td>
              <td colspan="5" class="py-2 pl-2">
                <div class="bg-slate-800/50 rounded-lg p-3">
                  <!-- Header -->
                  <div class="flex items-center gap-4 text-[10px] text-slate-500 uppercase tracking-wider mb-2 px-1">
                    <span class="flex-1">{{ t('pi.production.products') }}</span>
                    <span v-if="tier.tier !== 'P0'" class="w-28 text-right">{{ t('pi.supply.inputs') }}</span>
                    <span class="w-14 text-right">{{ t('pi.supply.output') }}</span>
                    <span class="w-20 text-right">ISK/j</span>
                    <span v-if="tier.tier !== 'P0'" class="w-24 text-right">{{ t('pi.supply.supply') }}</span>
                  </div>
                  <!-- Items -->
                  <div class="space-y-1.5">
                    <div
                      v-for="item in tier.items"
                      :key="item.typeId"
                      class="flex items-center gap-4 text-xs px-1"
                    >
                      <div class="flex items-center gap-2 flex-1">
                        <span class="w-1.5 h-1.5 rounded-full" :class="TIER_CONFIG[tier.tier]?.badgeText?.replace('text-', 'bg-') || 'bg-cyan-400'"></span>
                        <span class="text-slate-300">{{ item.typeName }}</span>
                        <OpenInGameButton type="market" :targetId="item.typeId" />
                        <span v-if="item.inputs?.length" class="text-slate-600 text-[10px]">
                          &larr; {{ item.inputs.map(i => i.typeName).join(' + ') }}
                        </span>
                      </div>
                      <span v-if="tier.tier !== 'P0'" class="w-28 text-right text-slate-500 font-mono-tech">
                        {{ item.inputs?.length ? formatNumber(item.inputs.reduce((s, i) => s + i.dailyConsumed, 0), 0) : '-' }}
                      </span>
                      <span class="w-14 text-right text-slate-400 font-mono-tech">{{ formatNumber(item.dailyQuantity, 0) }}/j</span>
                      <span class="w-20 text-right font-mono-tech" :class="item.dailyIskValue > 0 ? 'text-cyan-400' : 'text-slate-500'">
                        {{ item.dailyIskValue > 0 ? formatIsk(item.dailyIskValue) : '-' }}
                      </span>
                      <div v-if="tier.tier !== 'P0'" class="w-24 flex items-center gap-1.5 justify-end">
                        <template v-if="item.inputs?.length">
                          <span
                            class="w-1.5 h-1.5 rounded-full"
                            :class="getSupplyDeltaColor(getWorstDelta(item.inputs)).dot"
                          ></span>
                          <span
                            class="font-mono-tech"
                            :class="getSupplyDeltaColor(getWorstDelta(item.inputs)).text"
                          >
                            {{ formatDelta(getWorstDelta(item.inputs)) }}
                          </span>
                        </template>
                        <span v-else class="text-slate-500">-</span>
                      </div>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
        <tfoot>
          <tr class="border-t border-slate-700">
            <td></td>
            <td class="py-3">
              <span class="text-cyan-400 font-semibold">{{ t('pi.production.total') }}</span>
            </td>
            <td class="py-3 text-right text-cyan-400 font-bold">
              {{ props.production.reduce((sum, t) => sum + t.items.length, 0) }}
            </td>
            <td class="py-3 text-right text-slate-500">-</td>
            <td class="py-3 text-right text-cyan-400 font-bold font-mono-tech">
              {{ formatIsk(props.totalDailyIsk) }}
            </td>
            <td class="py-3 text-right text-cyan-400 font-bold">100%</td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="mt-3 pt-3 border-t border-slate-800">
      <p class="text-[11px] text-slate-500 italic">
        {{ t('pi.production.footnote') }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.font-mono-tech {
  font-family: 'Share Tech Mono', monospace;
}
</style>
