<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { MarketAlert } from '@/stores/market'

const props = defineProps<{
  alerts: MarketAlert[]
  isDeleting?: boolean
  structureName?: string | null
}>()

const emit = defineEmits<{
  delete: [id: string]
  selectItem: [typeId: number]
}>()

const { t } = useI18n()
const { formatIsk, formatDate } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

function statusClasses(status: string): string {
  switch (status) {
    case 'active': return 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30'
    case 'triggered': return 'bg-amber-500/10 text-amber-400 border-amber-500/30'
    case 'expired': return 'bg-slate-500/10 text-slate-400 border-slate-500/30'
    default: return 'bg-slate-500/10 text-slate-400 border-slate-500/30'
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case 'active': return t('market.alert.status.active')
    case 'triggered': return t('market.alert.status.triggered')
    case 'expired': return t('market.alert.status.expired')
    default: return status
  }
}

function extractSystemName(name: string | null | undefined): string {
  if (!name) return 'Structure'
  const dash = name.indexOf(' - ')
  return dash > 0 ? name.substring(0, dash) : name
}

function priceSourceLabel(priceSource: string): string {
  switch (priceSource) {
    case 'jita_sell': return 'Jita Sell'
    case 'jita_buy': return 'Jita Buy'
    case 'structure_sell': return extractSystemName(props.structureName) + ' Sell'
    case 'structure_buy': return extractSystemName(props.structureName) + ' Buy'
    default: return priceSource
  }
}

</script>

<template>
  <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl overflow-hidden">
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800/50">
      <h3 class="text-sm font-medium text-slate-300">{{ t('market.alert.status.active') }} Price Alerts</h3>
      <span class="text-xs text-slate-500">{{ alerts.length }} alerts</span>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-800/50">
            <th class="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Item</th>
            <th class="text-center px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider hidden md:table-cell">{{ t('market.alert.direction') }}</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">{{ t('market.alert.threshold') }}</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider hidden md:table-cell">{{ t('market.alert.currentPrice') }}</th>
            <th class="text-center px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Status</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider hidden lg:table-cell">Created</th>
            <th class="text-center px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider w-10"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/30">
          <tr
            v-for="alert in alerts"
            :key="alert.id"
            class="hover:bg-cyan-500/5 transition-colors"
            :class="{ 'alert-active': alert.status === 'active' }"
          >
            <td class="px-4 py-3">
              <button
                class="flex items-center gap-3 hover:text-cyan-400 transition-colors text-left"
                @click="emit('selectItem', alert.typeId)"
              >
                <img
                  :src="getTypeIconUrl(alert.typeId, 32)"
                  @error="onImageError"
                  :alt="alert.typeName"
                  class="w-7 h-7 rounded shrink-0"
                  loading="lazy"
                />
                <div>
                  <div class="text-sm font-medium text-slate-200">{{ alert.typeName }}</div>
                  <div class="text-xs text-slate-500">
                    {{ priceSourceLabel(alert.priceSource) }}
                  </div>
                </div>
              </button>
            </td>
            <td class="px-4 py-3 text-center hidden md:table-cell">
              <span
                class="inline-flex items-center gap-1 text-xs font-medium"
                :class="alert.direction === 'above' ? 'text-emerald-400' : 'text-red-400'"
              >
                <svg v-if="alert.direction === 'above'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                </svg>
                <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
                {{ alert.direction === 'above' ? t('market.alert.above') : t('market.alert.below') }}
              </span>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-slate-300">
              {{ formatIsk(alert.threshold) }} ISK
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-cyan-400 hidden md:table-cell">
              {{ formatIsk(alert.currentPrice) }} ISK
            </td>
            <td class="px-4 py-3 text-center">
              <span
                v-if="alert.status === 'active'"
                class="inline-flex items-center gap-1 px-2 py-1 bg-cyan-500/10 border border-cyan-500/30 rounded text-xs font-medium text-cyan-400"
              >
                <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                {{ statusLabel(alert.status) }}
              </span>
              <span
                v-else-if="alert.status === 'triggered'"
                class="inline-flex items-center gap-1 px-2 py-1 bg-amber-500/10 border border-amber-500/30 rounded text-xs font-medium text-amber-400"
              >
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                {{ statusLabel(alert.status) }}
              </span>
              <span
                v-else
                class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium border"
                :class="statusClasses(alert.status)"
              >
                {{ statusLabel(alert.status) }}
              </span>
            </td>
            <td class="px-4 py-3 text-right text-xs text-slate-500 hidden lg:table-cell">
              {{ formatDate(alert.createdAt) }}
            </td>
            <td class="px-4 py-3 text-center">
              <button
                @click="emit('delete', alert.id)"
                class="p-1 text-slate-600 hover:text-red-400 rounded transition-colors"
                :title="t('market.alert.delete')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty state -->
    <div v-if="alerts.length === 0" class="p-8 text-center">
      <svg class="w-10 h-10 text-slate-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
      </svg>
      <p class="text-slate-500 text-sm">{{ t('market.search.noResults') }}</p>
    </div>
  </div>
</template>

<style scoped>
@keyframes glow-pulse {
  0%, 100% { box-shadow: 0 0 4px rgba(6,182,212,0.2); }
  50% { box-shadow: 0 0 12px rgba(6,182,212,0.4); }
}
.alert-active {
  animation: glow-pulse 3s ease-in-out infinite;
}
</style>
