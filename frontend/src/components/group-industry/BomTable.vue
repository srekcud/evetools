<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import type { BomItem, ContainerVerification } from '@/stores/group-industry/types'

const { t } = useI18n()
const projectStore = useGroupProjectStore()
const { getTypeIconUrl, onImageError } = useEveImages()
const { formatIsk, formatNumber } = useFormatters()

defineEmits<{
  contribute: [bomItem: BomItem]
}>()

const materials = computed(() => projectStore.materials)
const verificationMap = computed(() => {
  const map = new Map<string, ContainerVerification>()
  for (const v of projectStore.containerVerification) {
    map.set(v.bomItemId, v)
  }
  return map
})

const totalItems = computed(() => materials.value.length)
const totalEstCost = computed(() =>
  materials.value.reduce((sum, m) => sum + (m.estimatedTotal ?? 0), 0)
)

const overallFulfillment = computed(() => {
  if (materials.value.length === 0) return 0
  const totalRequired = materials.value.reduce((s, m) => s + m.requiredQuantity, 0)
  const totalFulfilled = materials.value.reduce((s, m) => s + m.fulfilledQuantity, 0)
  return totalRequired > 0 ? Math.round((totalFulfilled / totalRequired) * 100) : 0
})

const RING_RADIUS = 16
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS

function ringOffset(percent: number): number {
  return RING_CIRCUMFERENCE * (1 - percent / 100)
}

// Maps fulfillment percent to a color tier: emerald (done), amber (partial), red (empty)
function fulfillmentColor(percent: number): 'emerald' | 'amber' | 'red' {
  if (percent >= 100) return 'emerald'
  if (percent > 0) return 'amber'
  return 'red'
}

const BAR_CLASS: Record<string, string> = { emerald: 'bg-emerald-500/70', amber: 'bg-amber-500/70', red: 'bg-red-500/70' }
const TEXT_CLASS: Record<string, string> = { emerald: 'text-emerald-400', amber: 'text-amber-400', red: 'text-red-400' }

function progressBarClass(percent: number): string {
  return BAR_CLASS[fulfillmentColor(percent)]
}

function fulfillmentTextClass(percent: number): string {
  return TEXT_CLASS[fulfillmentColor(percent)]
}

function percentTextClass(percent: number): string {
  if (percent >= 100) return 'text-emerald-400'
  if (percent > 0) return 'text-slate-500'
  return 'text-red-400'
}

function ringGlowClass(percent: number): string {
  return percent >= 100 ? 'ring-glow-emerald' : 'ring-glow-amber'
}

function ringStrokeColor(percent: number): string {
  return percent >= 100 ? '#34d399' : '#fbbf24'
}
</script>

<template>
  <!-- Materials to Source -->
  <div class="mb-6">
    <h2 class="text-sm font-semibold text-slate-200 uppercase tracking-wider mb-3">
      {{ t('groupIndustry.bom.materialsToSource') }}
    </h2>
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
      <!-- Loading skeleton -->
      <div v-if="projectStore.bomLoading && materials.length === 0" class="p-8 text-center text-slate-500">
        <svg class="w-6 h-6 animate-spin text-cyan-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ t('common.status.loading') }}
      </div>

      <!-- Table -->
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800/50">
              <th class="text-left py-2.5 px-6 w-8"></th>
              <th class="text-left py-2.5 px-3">{{ t('groupIndustry.bom.item') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('groupIndustry.bom.required') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('groupIndustry.bom.fulfilled') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('groupIndustry.bom.remaining') }}</th>
              <th class="text-left py-2.5 px-3 w-36">{{ t('groupIndustry.bom.progress') }}</th>
              <th class="text-right py-2.5 px-6">
                <div class="flex items-center justify-end gap-1">
                  {{ t('groupIndustry.bom.estCost') }}
                  <svg class="w-3 h-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div class="text-[10px] text-slate-600 font-normal normal-case tracking-normal mt-0.5">(Jita 5% avg)</div>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/30">
            <tr
              v-for="item in materials"
              :key="item.id"
              class="hover:bg-slate-800/30"
            >
              <!-- Icon -->
              <td class="py-2.5 px-6">
                <div class="w-7 h-7 rounded bg-slate-800 border border-slate-700 overflow-hidden">
                  <img
                    :src="getTypeIconUrl(item.typeId, 32)"
                    :alt="item.typeName"
                    class="w-full h-full"
                    @error="onImageError"
                  />
                </div>
              </td>

              <!-- Name + Container verification -->
              <td class="py-2.5 px-3">
                <span class="text-slate-200">{{ item.typeName }}</span>
                <!-- Container verification indicator -->
                <template v-if="verificationMap.has(item.id)">
                  <div class="flex items-center gap-1 mt-1">
                    <!-- Verified -->
                    <template v-if="verificationMap.get(item.id)!.status === 'verified'">
                      <svg class="w-3 h-3 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                      <span class="text-[10px] text-emerald-400">
                        Verified: {{ formatNumber(verificationMap.get(item.id)!.containerQuantity, 0) }} / {{ formatNumber(verificationMap.get(item.id)!.requiredQuantity, 0) }} in container
                      </span>
                    </template>
                    <!-- Partial -->
                    <template v-else-if="verificationMap.get(item.id)!.status === 'partial'">
                      <svg class="w-3 h-3 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                      <span class="text-[10px] text-amber-400">
                        Partial: {{ formatNumber(verificationMap.get(item.id)!.containerQuantity, 0) }} / {{ formatNumber(verificationMap.get(item.id)!.requiredQuantity, 0) }} in container
                      </span>
                    </template>
                  </div>
                </template>
              </td>

              <!-- Required -->
              <td class="py-2.5 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(item.requiredQuantity, 0) }}
              </td>

              <!-- Fulfilled -->
              <td class="py-2.5 px-3 text-right font-mono" :class="fulfillmentTextClass(item.fulfillmentPercent)" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(item.fulfilledQuantity, 0) }}
              </td>

              <!-- Remaining -->
              <td class="py-2.5 px-3 text-right font-mono" :class="fulfillmentTextClass(item.fulfillmentPercent)" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(item.remainingQuantity, 0) }}
              </td>

              <!-- Progress bar -->
              <td class="py-2.5 px-3">
                <div class="flex items-center gap-2">
                  <div class="flex-1 h-1.5 bg-slate-700 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full"
                      :class="progressBarClass(item.fulfillmentPercent)"
                      :style="{ width: Math.min(item.fulfillmentPercent, 100) + '%' }"
                    ></div>
                  </div>
                  <span class="text-xs font-mono w-8 text-right" :class="percentTextClass(item.fulfillmentPercent)">
                    {{ item.fulfillmentPercent }}%
                  </span>
                  <!-- Lock icon for fully fulfilled -->
                  <svg
                    v-if="item.isFulfilled"
                    class="w-3 h-3 text-slate-600 inline ml-1"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                  </svg>
                </div>
              </td>

              <!-- Est. Cost -->
              <td class="py-2.5 px-6 text-right font-mono text-slate-400" style="font-variant-numeric: tabular-nums;">
                {{ item.estimatedTotal != null ? formatIsk(item.estimatedTotal, 1) : '---' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Footer Summary -->
  <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-5 mb-6">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-6">
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('groupIndustry.bom.totalItems') }}</p>
          <p class="text-lg font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">{{ totalItems }}</p>
        </div>
        <div class="w-px h-8 bg-slate-800"></div>
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('groupIndustry.bom.totalEstCost') }}</p>
          <p class="text-lg font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">{{ formatIsk(totalEstCost, 1) }}</p>
        </div>
        <div class="w-px h-8 bg-slate-800"></div>
        <div>
          <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('groupIndustry.bom.overallFulfillment') }}</p>
          <div class="flex items-center gap-3">
            <!-- Ring chart -->
            <div class="relative w-10 h-10 flex-shrink-0" :class="ringGlowClass(overallFulfillment)">
              <svg viewBox="0 0 40 40" class="w-10 h-10" style="transform: rotate(-90deg)">
                <circle cx="20" cy="20" :r="RING_RADIUS" fill="none" stroke="rgb(30 41 59)" stroke-width="4" />
                <circle
                  cx="20" cy="20" :r="RING_RADIUS" fill="none"
                  :stroke="ringStrokeColor(overallFulfillment)"
                  stroke-width="4" stroke-linecap="round"
                  :stroke-dasharray="RING_CIRCUMFERENCE"
                  :stroke-dashoffset="ringOffset(overallFulfillment)"
                />
              </svg>
              <div class="absolute inset-0 flex items-center justify-center">
                <span class="text-[9px] font-mono font-bold" :class="fulfillmentTextClass(overallFulfillment)">
                  {{ overallFulfillment }}%
                </span>
              </div>
            </div>
            <p class="text-lg font-mono font-semibold" :class="fulfillmentTextClass(overallFulfillment)" style="font-variant-numeric: tabular-nums;">
              {{ overallFulfillment }}%
            </p>
          </div>
        </div>
      </div>
    </div>
    <p class="text-xs text-slate-600 mt-3">
      Prices: <span class="text-cyan-400/60">Jita top 5%</span> sell avg
    </p>
  </div>
</template>

<style scoped>
.ring-glow-amber { filter: drop-shadow(0 0 6px rgba(251,191,36,0.15)); }
.ring-glow-emerald { filter: drop-shadow(0 0 6px rgba(52,211,153,0.15)); }
</style>
