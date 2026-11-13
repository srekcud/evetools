<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AssetSafetyLocation } from '@/utils/assetSafety'

defineProps<{
  locations: AssetSafetyLocation[]
}>()

const emit = defineEmits<{
  'show-content': [locationName: string]
}>()

const { t } = useI18n()

function formatNumber(n: number): string {
  return n.toLocaleString()
}
</script>

<template>
  <section
    data-testid="asset-safety-block"
    class="mb-6 rounded-lg border border-amber-500/40 bg-amber-500/5 p-4"
  >
    <div class="flex items-center gap-2 text-amber-400">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4" />
      </svg>
      <p class="font-semibold">{{ t('assets.assetSafety.title') }}</p>
    </div>
    <p data-testid="asset-safety-reminder" class="mt-1 text-sm text-slate-400">{{ t('assets.assetSafety.reminder') }}</p>
    <ul class="mt-3 divide-y divide-slate-800">
      <li
        v-for="location in locations"
        :key="location.locationName"
        class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0"
      >
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
          <span class="font-medium text-slate-200">{{ location.solarSystemName }}</span>
          <span data-testid="asset-safety-items" class="font-mono text-sm text-cyan-400">
            {{ t('assets.assetSafety.items', { count: formatNumber(location.itemCount) }) }}
          </span>
          <span data-testid="asset-safety-ships" class="font-mono text-sm text-indigo-400">
            {{ t('assets.assetSafety.ships', { count: formatNumber(location.shipCount) }) }}
          </span>
        </div>
        <button
          type="button"
          data-testid="asset-safety-show-content"
          class="text-sm text-cyan-400 hover:underline"
          @click="emit('show-content', location.locationName)"
        >
          {{ t('assets.assetSafety.showContent') }}
        </button>
      </li>
    </ul>
  </section>
</template>
