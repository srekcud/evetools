<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  actionLoading: string | null
}>()

const emit = defineEmits<{
  'execute-action': [name: string, action: string]
}>()

const { t } = useI18n()
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <h3 class="text-lg font-semibold text-slate-200 mb-4">{{ t('admin.maintenance') }}</h3>
    <div class="space-y-3">
      <button
        @click="emit('execute-action', 'retry-failed', 'retryFailed')"
        :disabled="actionLoading !== null"
        class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-orange-500/20 hover:bg-orange-500/30 text-orange-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <svg :class="['w-4 h-4', actionLoading === 'retry-failed' ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        {{ t('admin.actions.retryFailed') }}
      </button>

      <button
        @click="emit('execute-action', 'purge-failed', 'purgeFailed')"
        :disabled="actionLoading !== null"
        class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-red-500/20 hover:bg-red-500/30 text-red-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <svg :class="['w-4 h-4', actionLoading === 'purge-failed' ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
        </svg>
        {{ t('admin.actions.purgeFailed') }}
      </button>

      <button
        @click="emit('execute-action', 'clear-cache', 'clearCache')"
        :disabled="actionLoading !== null"
        class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-slate-500/20 hover:bg-slate-500/30 text-slate-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <svg :class="['w-4 h-4', actionLoading === 'clear-cache' ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
        </svg>
        {{ t('admin.clearCache') }}
      </button>

      <div class="border-t border-slate-700 my-3"></div>
      <p class="text-xs text-slate-500 uppercase mb-2">{{ t('admin.actions.marketAndNotifications') }}</p>

      <button
        @click="emit('execute-action', 'check-market-alerts', 'checkMarketAlerts')"
        :disabled="actionLoading !== null"
        class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <svg :class="['w-4 h-4', actionLoading === 'check-market-alerts' ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
        </svg>
        {{ t('admin.actions.checkMarketAlerts') }}
      </button>

      <button
        @click="emit('execute-action', 'purge-notifications', 'purgeNotifications')"
        :disabled="actionLoading !== null"
        class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <svg :class="['w-4 h-4', actionLoading === 'purge-notifications' ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
        </svg>
        {{ t('admin.actions.purgeNotifications') }}
      </button>

      <button
        @click="emit('execute-action', 'purge-market-history', 'purgeMarketHistory')"
        :disabled="actionLoading !== null"
        class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      >
        <svg :class="['w-4 h-4', actionLoading === 'purge-market-history' ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
        </svg>
        {{ t('admin.actions.purgeMarketHistory') }}
      </button>
    </div>
  </div>
</template>
