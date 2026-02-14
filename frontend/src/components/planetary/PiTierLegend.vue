<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { TIER_CONFIG } from '@/composables/usePlanetaryHelpers'

const { t } = useI18n()
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 p-4">
    <div class="flex items-center justify-between flex-wrap gap-4">
      <div class="flex items-center gap-6 text-xs flex-wrap">
        <span class="text-slate-500 uppercase tracking-wider font-medium">{{ t('pi.legend.piTiers') }}</span>
        <div v-for="(config, tier) in TIER_CONFIG" :key="tier" class="flex items-center gap-1.5">
          <span :class="['px-1.5 py-0.5 rounded border', config.badgeBg, config.badgeText, config.badgeBorder]">{{ tier }}</span>
          <span class="text-slate-500">{{ t(config.labelKey) }}</span>
        </div>
      </div>
      <div class="flex items-center gap-4 text-xs text-slate-500">
        <div class="flex items-center gap-1.5">
          <div class="w-2 h-2 rounded-full bg-emerald-400"></div>
          <span>{{ t('pi.legend.moreThan24h') }}</span>
        </div>
        <div class="flex items-center gap-1.5">
          <div class="w-2 h-2 rounded-full bg-amber-500"></div>
          <span>{{ t('pi.legend.lessThan24h') }}</span>
        </div>
        <div class="flex items-center gap-1.5">
          <div class="w-2 h-2 rounded-full bg-red-500"></div>
          <span>{{ t('pi.legend.expired') }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
