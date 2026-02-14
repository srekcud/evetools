<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { LedgerSettings } from '@/stores/ledger'

defineProps<{
  settings: LedgerSettings | null
}>()

const emit = defineEmits<{
  'update-setting': [key: string, value: string]
}>()

const { t } = useI18n()
</script>

<template>
  <div class="space-y-6">
    <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
      <h3 class="text-lg font-semibold text-white mb-4">{{ t('ledger.settings.corpProjectTitle') }}</h3>
      <p class="text-slate-400 text-sm mb-4">
        {{ t('ledger.settings.corpProjectDescription') }}
      </p>

      <div class="space-y-3">
        <label
          class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-colors"
          :class="settings?.corpProjectAccounting === 'pve' ? 'bg-cyan-500/10 border-cyan-500/30' : 'border-slate-700 hover:border-slate-600'"
        >
          <input
            type="radio"
            name="corpProjectAccounting"
            value="pve"
            :checked="settings?.corpProjectAccounting === 'pve'"
            @change="emit('update-setting', 'corpProjectAccounting', 'pve')"
            class="mt-1"
          />
          <div>
            <div class="text-white font-medium">{{ t('ledger.settings.pveSide') }}</div>
            <div class="text-sm text-slate-400 mt-1">
              {{ t('ledger.settings.pveSideDescription') }}
            </div>
            <div class="text-xs text-cyan-400 mt-2">{{ t('ledger.settings.recommended') }}</div>
          </div>
        </label>

        <label
          class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-colors"
          :class="settings?.corpProjectAccounting === 'mining' ? 'bg-amber-500/10 border-amber-500/30' : 'border-slate-700 hover:border-slate-600'"
        >
          <input
            type="radio"
            name="corpProjectAccounting"
            value="mining"
            :checked="settings?.corpProjectAccounting === 'mining'"
            @change="emit('update-setting', 'corpProjectAccounting', 'mining')"
            class="mt-1"
          />
          <div>
            <div class="text-white font-medium">{{ t('ledger.settings.miningSide') }}</div>
            <div class="text-sm text-slate-400 mt-1">
              {{ t('ledger.settings.miningSideDescription') }}
            </div>
          </div>
        </label>
      </div>

      <div class="mt-4 p-3 bg-slate-900/50 rounded-lg">
        <div class="flex items-center gap-2 text-xs text-slate-500">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>{{ t('ledger.settings.salvageNote') }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
