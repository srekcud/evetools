<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  totalItems: number
  totalTypes: number
  totalSystems: number
  totalLocations: number
}>()

const { t } = useI18n()

function formatNumber(n: number): string {
  return n.toLocaleString()
}
</script>

<template>
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
      <p class="text-2xl font-bold text-cyan-400">{{ formatNumber(totalItems) }}</p>
      <p class="text-sm text-slate-500">{{ t('assets.stats.totalItems') }}</p>
    </div>
    <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
      <p class="text-2xl font-bold text-indigo-400">{{ formatNumber(totalTypes) }}</p>
      <p class="text-sm text-slate-500">{{ t('assets.stats.itemTypes') }}</p>
    </div>
    <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
      <p class="text-2xl font-bold text-amber-400">{{ formatNumber(totalSystems) }}</p>
      <p class="text-sm text-slate-500">{{ t('assets.stats.systems') }}</p>
    </div>
    <div class="bg-slate-900 rounded-lg p-4 border border-slate-800">
      <p class="text-2xl font-bold text-emerald-400">{{ formatNumber(totalLocations) }}</p>
      <p class="text-sm text-slate-500">{{ t('assets.stats.locations') }}</p>
    </div>
  </div>
</template>
