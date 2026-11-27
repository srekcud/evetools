<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { PLANETARY_TIER_FILTERS, type PlanetaryTierFilter } from '@/composables/usePlanetaryTierFilter'

defineProps<{ testId: string }>()
const selected = defineModel<PlanetaryTierFilter>({ required: true })
const { t } = useI18n()
</script>

<template>
  <div class="flex flex-wrap gap-2" role="group" :aria-label="t('pi.tierFilter.label')" :data-testid="testId">
    <button
      v-for="tier in PLANETARY_TIER_FILTERS"
      :key="tier"
      type="button"
      :data-tier="tier"
      :aria-pressed="selected === tier"
      :class="[
        'rounded-md border px-3 py-1 text-xs transition-colors',
        selected === tier ? 'border-cyan-500 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 bg-slate-800 text-slate-300 hover:border-slate-500',
      ]"
      @click="selected = tier"
    >{{ tier === 'all' ? t('pi.tierFilter.all') : tier }}</button>
  </div>
</template>
