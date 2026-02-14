<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { AdminPveStats } from '@/stores/admin'

defineProps<{
  pve: AdminPveStats | undefined
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-slate-200">{{ t('admin.pve.revenueByCorpTitle') }}</h3>
      <div class="flex items-center gap-2">
        <div class="w-10 h-10 rounded-lg bg-green-500/20 flex items-center justify-center">
          <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
          </svg>
        </div>
        <div class="text-right">
          <p class="text-xl font-bold text-green-400">{{ formatIsk(pve?.totalIncome30d || 0) }}</p>
          <p class="text-xs text-slate-500">{{ t('admin.pve.total30d') }}</p>
        </div>
      </div>
    </div>

    <div v-if="pve?.byCorporation?.length" class="space-y-2">
      <div
        v-for="(corp, index) in pve.byCorporation"
        :key="corp.corporationId"
        class="flex items-center gap-3 p-3 bg-slate-900/50 rounded-lg"
      >
        <span class="text-slate-500 text-sm w-6">{{ index + 1 }}.</span>
        <img
          :src="`https://images.evetech.net/corporations/${corp.corporationId}/logo?size=32`"
          class="w-8 h-8 rounded"
          :alt="corp.corporationName"
        />
        <span class="flex-1 text-slate-200 truncate">{{ corp.corporationName }}</span>
        <span class="text-green-400 font-mono">{{ formatIsk(corp.total) }}</span>
      </div>
    </div>
    <p v-else class="text-slate-500 text-sm">{{ t('admin.pve.noData') }}</p>
  </div>
</template>
