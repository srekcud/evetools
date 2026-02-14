<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryTimers } from '@/composables/usePlanetaryTimers'
import { usePlanetaryHelpers, TIER_CONFIG } from '@/composables/usePlanetaryHelpers'
import type { Colony } from '@/stores/planetary'

const { t } = useI18n()
const { formatIsk, formatNumber, formatTimeSince } = useFormatters()
const {
  getTimerInfo,
  getTimerColorClasses,
  formatCycleTime,
  formatDailyOutput,
  isStaleData,
} = usePlanetaryTimers()
const {
  getSecurityColorClass,
  formatSecurity,
  getPlanetConfig,
  getPlanetIconUrl,
  getExtractorPins,
  getFactoryPins,
  getStoragePins,
  getColonyBorderClass,
  getNetFlowColorClass,
  formatNetFlow,
  getStorageFlowData,
  getColonyProduction,
} = usePlanetaryHelpers()

defineProps<{
  colony: Colony
  expanded: boolean
}>()

defineEmits<{
  toggle: []
}>()
</script>

<template>
  <div
    class="colony-card bg-slate-900 rounded-xl overflow-hidden cursor-pointer"
    :class="[getColonyBorderClass(colony), expanded ? 'expanded' : '']"
    style="border-width: 1px;"
  >
    <!-- Colony Header Row -->
    <div class="px-5 py-4" @click="$emit('toggle')">
      <div class="flex items-center gap-4">
        <!-- Planet info -->
        <div class="flex items-center gap-3 min-w-[240px]">
          <img
            :src="getPlanetIconUrl(colony.planetType)"
            :alt="colony.planetType"
            class="w-9 h-9 rounded-full"
          />
          <div>
            <div class="flex items-center gap-2">
              <span class="text-white font-semibold text-[15px]">
                {{ colony.planetName || `${colony.solarSystemName || t('pi.colony.unknown')} ${colony.planetId}` }}
              </span>
              <span :class="['text-[10px] px-1.5 py-0.5 rounded-full font-medium uppercase tracking-wider border',
                getPlanetConfig(colony.planetType).badgeBg,
                getPlanetConfig(colony.planetType).badgeText,
                getPlanetConfig(colony.planetType).badgeBorder
              ]">
                {{ colony.planetType }}
              </span>
            </div>
            <div class="flex items-center gap-1.5 text-xs text-slate-500">
              <span>{{ colony.solarSystemName || t('pi.colony.unknown') }}</span>
              <span class="text-slate-600">|</span>
              <span :class="getSecurityColorClass(colony.solarSystemSecurity)" class="font-medium">{{ formatSecurity(colony.solarSystemSecurity) }}</span>
            </div>
          </div>
        </div>

        <!-- Upgrade level -->
        <div class="flex items-center gap-1.5 min-w-[90px]">
          <span class="text-xs text-slate-500 mr-1">CC Lv</span>
          <div class="flex gap-0.5">
            <div
              v-for="level in 5"
              :key="level"
              :class="['w-3.5 h-1.5 rounded-sm', level <= colony.upgradeLevel ? 'bg-cyan-400' : 'bg-slate-700']"
            ></div>
          </div>
        </div>

        <!-- Installations -->
        <div class="min-w-[60px] text-center">
          <span class="text-white font-medium">{{ colony.numPins }}</span>
          <span class="text-slate-500 text-xs ml-1">inst.</span>
        </div>

        <!-- Extractor timers -->
        <div class="flex items-center gap-3 flex-1">
          <!-- Detail loaded: show individual extractor timers -->
          <template v-if="colony.pins.length > 0">
            <template v-for="pin in getExtractorPins(colony)" :key="pin.pinId">
              <div class="flex items-center gap-1.5">
                <div :class="['w-2 h-2 rounded-full', getTimerColorClasses(getTimerInfo(pin.expiryTime).status).dot, getTimerColorClasses(getTimerInfo(pin.expiryTime).status).pulse]"></div>
                <span :class="['font-mono-tech text-sm', getTimerColorClasses(getTimerInfo(pin.expiryTime).status).text, getTimerInfo(pin.expiryTime).status === 'expired' ? 'font-bold' : '']">
                  {{ getTimerInfo(pin.expiryTime).formatted }}
                </span>
              </div>
            </template>
            <span v-if="getExtractorPins(colony).length === 0" class="text-slate-500 text-xs italic">
              {{ t('pi.colony.noExtractors') }}
            </span>
          </template>
          <!-- Collection mode: show summary from colony-level metadata -->
          <template v-else-if="colony.extractorCount > 0">
            <div class="flex items-center gap-1.5">
              <div :class="['w-2 h-2 rounded-full', getTimerColorClasses(getTimerInfo(colony.nearestExpiry).status).dot, getTimerColorClasses(getTimerInfo(colony.nearestExpiry).status).pulse]"></div>
              <span :class="['font-mono-tech text-sm', getTimerColorClasses(getTimerInfo(colony.nearestExpiry).status).text, getTimerInfo(colony.nearestExpiry).status === 'expired' ? 'font-bold' : '']">
                {{ getTimerInfo(colony.nearestExpiry).formatted }}
              </span>
            </div>
            <span class="text-slate-500 text-xs">
              &middot; {{ t('common.units.extractor', colony.extractorCount) }}
            </span>
          </template>
          <!-- No extractors at all -->
          <span v-else class="text-slate-500 text-xs italic">
            {{ t('pi.colony.noExtractors') }}
          </span>
        </div>

        <!-- Last update -->
        <div class="text-right min-w-[100px]">
          <div class="text-xs text-slate-500">MAJ {{ formatTimeSince(colony.cachedAt) }}</div>
          <div v-if="isStaleData(colony.cachedAt)" class="mt-0.5">
            <span class="stale-badge text-[10px] text-slate-900 px-1.5 py-0.5 rounded-full font-medium">{{ t('pi.colony.stale') }}</span>
          </div>
        </div>

        <!-- Expand chevron -->
        <svg
          :class="['w-5 h-5 text-slate-500 transition-transform duration-200', expanded ? 'rotate-180' : '']"
          fill="none" stroke="currentColor" viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </div>
    </div>

    <!-- ============ EXPANDED COLONY DETAIL ============ -->
    <div v-if="expanded" class="border-t border-slate-800">
      <div class="px-5 py-4 space-y-5">

        <!-- Extractors Detail -->
        <div v-if="getExtractorPins(colony).length > 0">
          <h4 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-3 flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            {{ t('pi.colony.extractors') }}
          </h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div
              v-for="pin in getExtractorPins(colony)"
              :key="pin.pinId"
              class="bg-slate-800/50 rounded-lg p-3 border border-slate-700"
            >
              <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                  <div :class="['w-7 h-7 rounded border flex items-center justify-center',
                    pin.outputTier ? TIER_CONFIG[pin.outputTier]?.badgeBg || 'bg-slate-700' : 'bg-slate-700',
                    pin.outputTier ? TIER_CONFIG[pin.outputTier]?.badgeBorder || 'border-slate-600' : 'border-slate-600']">
                    <span :class="['text-[9px] font-bold',
                      pin.outputTier ? TIER_CONFIG[pin.outputTier]?.badgeText || 'text-slate-400' : 'text-slate-400']">
                      {{ pin.outputTier || 'P0' }}
                    </span>
                  </div>
                  <div>
                    <span class="text-white text-sm font-medium">{{ pin.extractorProductName || pin.typeName || t('pi.colony.unknown') }}</span>
                    <div class="text-[10px] text-slate-500">{{ t('pi.colony.extractionUnit') }}</div>
                  </div>
                </div>
                <div class="text-right">
                  <div :class="['font-mono-tech text-lg font-bold', getTimerColorClasses(getTimerInfo(pin.expiryTime).status).text]">
                    {{ getTimerInfo(pin.expiryTime).formatted }}
                  </div>
                  <div class="text-[10px] text-slate-500">{{ t('common.time.remaining') }}</div>
                </div>
              </div>
              <div class="grid grid-cols-3 gap-2 text-xs">
                <div class="bg-slate-800/50 rounded px-2 py-1.5">
                  <span class="text-slate-500 block">{{ t('pi.colony.cycle') }}</span>
                  <span class="text-white font-medium">{{ formatCycleTime(pin.extractorCycleTime) }}</span>
                </div>
                <div class="bg-slate-800/50 rounded px-2 py-1.5">
                  <span class="text-slate-500 block">{{ t('pi.colony.qtyPerCycle') }}</span>
                  <span class="text-white font-medium">{{ pin.extractorQtyPerCycle ? formatNumber(pin.extractorQtyPerCycle, 0) : '-' }}</span>
                </div>
                <div class="bg-slate-800/50 rounded px-2 py-1.5">
                  <span class="text-slate-500 block">{{ t('pi.colony.extractorHeads') }}</span>
                  <span class="text-white font-medium">{{ pin.extractorNumHeads ?? '-' }}</span>
                </div>
              </div>
              <div class="mt-2 flex items-center justify-between text-xs">
                <span class="text-slate-500">{{ t('pi.colony.initialRate') }}</span>
                <span class="text-cyan-400 font-medium">{{ formatDailyOutput(pin) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Factories Detail -->
        <div v-if="getFactoryPins(colony).length > 0">
          <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
            </svg>
            {{ t('pi.colony.factories') }} <span class="text-slate-500 font-normal">({{ getFactoryPins(colony).length }})</span>
          </h4>
          <div class="bg-slate-800/60 rounded-lg border border-slate-700/30 overflow-hidden">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-[10px] text-slate-500 uppercase tracking-wider border-b border-slate-700/30">
                  <th class="text-left px-3 py-2 font-medium">{{ t('pi.colony.schema') }}</th>
                  <th class="text-left px-3 py-2 font-medium">{{ t('pi.colony.inputs') }}</th>
                  <th class="text-left px-3 py-2 font-medium">{{ t('pi.colony.output') }}</th>
                  <th class="text-right px-3 py-2 font-medium">{{ t('pi.colony.cycle') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-700/20">
                <tr v-for="pin in getFactoryPins(colony)" :key="pin.pinId" class="hover:bg-slate-700/20">
                  <td class="px-3 py-2 text-white font-medium">{{ pin.schematicName || pin.typeName || t('pi.colony.unknown') }}</td>
                  <td class="px-3 py-2 text-slate-400 text-xs">
                    <template v-if="pin.schematicInputs && pin.schematicInputs.length > 0">
                      {{ pin.schematicInputs.map(i => `${formatNumber(i.quantity, 0)} ${i.typeName}`).join(' + ') }}
                    </template>
                    <span v-else class="text-slate-500">-</span>
                  </td>
                  <td class="px-3 py-2">
                    <template v-if="pin.schematicOutput">
                      <div class="flex items-center gap-1.5">
                        <span v-if="pin.outputTier" :class="['text-[9px] px-1 py-0.5 rounded border font-bold',
                          TIER_CONFIG[pin.outputTier]?.badgeBg, TIER_CONFIG[pin.outputTier]?.badgeText, TIER_CONFIG[pin.outputTier]?.badgeBorder]">
                          {{ pin.outputTier }}
                        </span>
                        <span :class="['text-xs', pin.outputTier ? TIER_CONFIG[pin.outputTier]?.badgeText || 'text-white' : 'text-white']">
                          {{ formatNumber(pin.schematicOutput.quantity, 0) }} {{ pin.schematicOutput.typeName }}
                        </span>
                      </div>
                    </template>
                    <span v-else class="text-slate-500 text-xs">-</span>
                  </td>
                  <td class="px-3 py-2 text-right text-slate-400 text-xs font-mono-tech">
                    {{ formatCycleTime(pin.schematicCycleTime) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Production + Storage side by side -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

          <!-- Per-colony production summary -->
          <div v-if="getColonyProduction(colony).length > 0">
            <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
              <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
              </svg>
              {{ t('pi.colony.theoreticalPerDay') }}
            </h4>
            <div class="bg-slate-800/60 rounded-lg border border-slate-700/30 overflow-hidden">
              <div class="divide-y divide-slate-700/20">
                <div
                  v-for="prod in getColonyProduction(colony)"
                  :key="prod.typeName"
                  class="flex items-center justify-between px-3 py-2.5"
                >
                  <div class="flex items-center gap-1.5">
                    <span v-if="prod.outputTier" :class="['text-[9px] px-1 py-0.5 rounded border font-bold',
                      TIER_CONFIG[prod.outputTier]?.badgeBg, TIER_CONFIG[prod.outputTier]?.badgeText, TIER_CONFIG[prod.outputTier]?.badgeBorder]">
                      {{ prod.outputTier }}
                    </span>
                    <span :class="['text-sm', prod.outputTier ? TIER_CONFIG[prod.outputTier]?.badgeText || 'text-white' : 'text-white']">
                      {{ prod.typeName }}
                    </span>
                  </div>
                  <div class="text-right flex items-center gap-3">
                    <span class="text-slate-500 text-xs font-mono-tech">{{ formatNumber(prod.dailyQuantity, 0) }}/j</span>
                    <span class="text-cyan-400 text-sm font-medium font-mono-tech min-w-[70px] text-right">
                      {{ prod.dailyIskValue > 0 ? formatIsk(prod.dailyIskValue) : '-' }}
                    </span>
                  </div>
                </div>
              </div>
              <!-- Total row -->
              <div v-if="getColonyProduction(colony).length > 0" class="border-t border-slate-700/50 px-3 py-2.5 flex items-center justify-between bg-slate-800/30">
                <span class="text-cyan-400 text-sm font-semibold">{{ t('pi.production.total') }}</span>
                <span class="text-cyan-400 text-sm font-bold font-mono-tech">
                  {{ formatIsk(getColonyProduction(colony).reduce((sum, p) => sum + p.dailyIskValue, 0)) }}
                </span>
              </div>
            </div>
          </div>

          <!-- Flow View (routes-based) -->
          <div v-if="getStoragePins(colony).length > 0">
            <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
              <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
              </svg>
              {{ t('pi.colony.storageFlows') }}
            </h4>
            <div class="space-y-2">
              <div
                v-for="storagePin in getStoragePins(colony)"
                :key="storagePin.pinId"
                class="bg-slate-800/60 rounded-lg border border-slate-700/30 overflow-hidden"
              >
                <!-- Pin header -->
                <div class="px-3 py-2 flex items-center justify-between">
                  <span class="text-slate-300 text-sm font-medium">{{ storagePin.typeName || 'Stockage' }}</span>
                  <span v-if="storagePin.capacity" class="text-[10px] text-slate-500 font-mono-tech">
                    {{ t('pi.colony.capacity', { value: formatNumber(storagePin.capacity, 0) }) }}
                  </span>
                </div>

                <!-- Flow data -->
                <template v-if="colony.routes.length > 0">
                  <template v-for="flowData in [getStorageFlowData(colony, storagePin)]" :key="storagePin.pinId">
                    <div class="px-3 pb-3 space-y-2">
                      <!-- Incoming flows -->
                      <div v-if="flowData.incoming.length > 0" class="space-y-1">
                        <div class="flex items-center gap-1.5 text-[10px] text-emerald-400/70 uppercase tracking-wider font-medium">
                          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                          </svg>
                          {{ t('pi.colony.incoming') }}
                        </div>
                        <div
                          v-for="flow in flowData.incoming"
                          :key="'in-' + flow.contentTypeId"
                          class="flex items-center justify-between pl-4 text-xs"
                        >
                          <span class="text-emerald-400">{{ flow.contentTypeName }}</span>
                          <div class="flex items-center gap-3 text-slate-400 font-mono-tech">
                            <span>{{ formatNumber(flow.quantityPerCycle, 0) }}/cycle</span>
                            <span v-if="flow.dailyQuantity !== null" class="text-emerald-400/80">
                              ~{{ formatNumber(flow.dailyQuantity, 0) }}/jour
                            </span>
                          </div>
                        </div>
                      </div>

                      <!-- Outgoing flows -->
                      <div v-if="flowData.outgoing.length > 0" class="space-y-1">
                        <div class="flex items-center gap-1.5 text-[10px] text-amber-400/70 uppercase tracking-wider font-medium">
                          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                          </svg>
                          {{ t('pi.colony.outgoing') }}
                        </div>
                        <div
                          v-for="flow in flowData.outgoing"
                          :key="'out-' + flow.contentTypeId"
                          class="flex items-center justify-between pl-4 text-xs"
                        >
                          <span class="text-amber-400">{{ flow.contentTypeName }}</span>
                          <div class="flex items-center gap-3 text-slate-400 font-mono-tech">
                            <span>{{ formatNumber(flow.quantityPerCycle, 0) }}/cycle</span>
                            <span v-if="flow.dailyQuantity !== null" class="text-amber-400/80">
                              ~{{ formatNumber(flow.dailyQuantity, 0) }}/jour
                            </span>
                          </div>
                        </div>
                      </div>

                      <!-- No flows -->
                      <div
                        v-if="flowData.incoming.length === 0 && flowData.outgoing.length === 0"
                        class="text-xs text-slate-500 italic py-1"
                      >
                        {{ t('pi.colony.noFlowConfigured') }}
                      </div>

                      <!-- Net flow summary -->
                      <div
                        v-if="flowData.incoming.length > 0 || flowData.outgoing.length > 0"
                        class="border-t border-slate-700/30 pt-2 mt-1 flex items-center justify-between text-xs"
                      >
                        <span class="text-slate-500">{{ t('pi.colony.netFlow') }}</span>
                        <div class="flex items-center gap-3">
                          <span :class="['font-mono-tech font-medium', getNetFlowColorClass(flowData.netDailyVolume)]">
                            {{ formatNetFlow(flowData.netDailyVolume) }}
                          </span>
                          <span
                            v-if="flowData.fillDays !== null"
                            class="text-slate-500 font-mono-tech"
                          >
                            {{ t('pi.colony.fillDays', { days: flowData.fillDays }) }}
                          </span>
                        </div>
                      </div>
                    </div>
                  </template>
                </template>

                <!-- No routes loaded -->
                <div v-else class="px-3 pb-2">
                  <span class="text-xs text-slate-500 italic">{{ t('pi.colony.loadingRoutes') }}</span>
                </div>
              </div>
            </div>
          </div>

        </div>

        <!-- No detail fallback -->
        <div v-if="getExtractorPins(colony).length === 0 && getFactoryPins(colony).length === 0 && getStoragePins(colony).length === 0">
          <p class="text-sm text-slate-500 italic">{{ t('pi.colony.noDetail') }}</p>
        </div>

      </div>
    </div>
  </div>
</template>

<style scoped>
.font-mono-tech {
  font-family: 'Share Tech Mono', monospace;
}

/* Timer pulse for expiring soon */
@keyframes urgentPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
.timer-urgent {
  animation: urgentPulse 1.5s ease-in-out infinite;
}

/* Stale data shimmer */
@keyframes staleShimmer {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}
.stale-badge {
  background: linear-gradient(90deg, #f59e0b, #d97706, #f59e0b);
  background-size: 200% 100%;
  animation: staleShimmer 3s ease infinite;
}

/* Colony card hover */
.colony-card {
  transition: all 0.2s ease;
  border-left: 3px solid transparent;
}
.colony-card:hover {
  background: rgba(30, 41, 59, 0.5);
  border-left-color: rgba(6, 182, 212, 0.5);
}
.colony-card.expanded {
  border-left-color: rgb(6, 182, 212);
  background: rgba(30, 41, 59, 0.5);
}
</style>
