<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { MarketAlert } from '@/stores/market'

defineProps<{
  alerts: MarketAlert[]
  isDeleting?: boolean
}>()

const emit = defineEmits<{
  delete: [id: string]
  selectItem: [typeId: number]
}>()

const { t } = useI18n()
const { formatIsk, formatDateTime } = useFormatters()

function statusClasses(status: string): string {
  switch (status) {
    case 'active': return 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30'
    case 'triggered': return 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
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
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-800">
            <th class="text-left px-4 py-3 text-slate-400 font-medium">Item</th>
            <th class="text-left px-4 py-3 text-slate-400 font-medium hidden md:table-cell">{{ t('market.alert.direction') }}</th>
            <th class="text-right px-4 py-3 text-slate-400 font-medium">{{ t('market.alert.threshold') }}</th>
            <th class="text-right px-4 py-3 text-slate-400 font-medium hidden md:table-cell">{{ t('market.alert.currentPrice') }}</th>
            <th class="text-left px-4 py-3 text-slate-400 font-medium hidden lg:table-cell">{{ t('market.alert.priceSource') }}</th>
            <th class="text-center px-4 py-3 text-slate-400 font-medium">Status</th>
            <th class="text-right px-4 py-3 text-slate-400 font-medium hidden lg:table-cell">{{ t('common.actions.create') }}</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/50">
          <tr
            v-for="alert in alerts"
            :key="alert.id"
            class="hover:bg-slate-800/30 transition-colors"
          >
            <td class="px-4 py-3">
              <button
                class="flex items-center gap-2 hover:text-cyan-400 transition-colors text-left"
                @click="emit('selectItem', alert.typeId)"
              >
                <img
                  :src="`https://images.evetech.net/types/${alert.typeId}/icon?size=32`"
                  :alt="alert.typeName"
                  class="w-6 h-6 rounded shrink-0"
                  loading="lazy"
                />
                <span class="text-slate-100 font-medium truncate">{{ alert.typeName }}</span>
              </button>
            </td>
            <td class="px-4 py-3 hidden md:table-cell">
              <span
                class="text-xs font-medium px-2 py-0.5 rounded-full border"
                :class="alert.direction === 'above'
                  ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                  : 'bg-red-500/10 text-red-400 border-red-500/30'"
              >
                {{ alert.direction === 'above' ? t('market.alert.above') : t('market.alert.below') }}
              </span>
            </td>
            <td class="px-4 py-3 text-right font-mono text-amber-400">
              {{ formatIsk(alert.threshold) }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-slate-300 hidden md:table-cell">
              {{ formatIsk(alert.currentPrice) }}
            </td>
            <td class="px-4 py-3 text-slate-400 text-xs hidden lg:table-cell">
              {{ alert.priceSource === 'jita_sell' ? 'Jita Sell' : 'Jita Buy' }}
            </td>
            <td class="px-4 py-3 text-center">
              <span
                class="text-xs font-medium px-2 py-0.5 rounded-full border"
                :class="statusClasses(alert.status)"
              >
                {{ statusLabel(alert.status) }}
              </span>
            </td>
            <td class="px-4 py-3 text-right text-xs text-slate-500 hidden lg:table-cell">
              {{ formatDateTime(alert.createdAt) }}
            </td>
            <td class="px-4 py-3 text-right">
              <button
                @click="emit('delete', alert.id)"
                class="p-1.5 hover:bg-red-500/10 rounded-lg transition-colors text-slate-500 hover:text-red-400"
                :title="t('market.alert.delete')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
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
