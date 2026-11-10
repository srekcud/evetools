<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatExpiryThreshold } from '@/composables/usePlanetaryTimers'
import type { Colony } from '@/stores/planetary'

const { t } = useI18n()

defineProps<{
  expiredColonies: Colony[]
  expiringColonies: Colony[]
  thresholdMinutes: number | null
}>()
</script>

<template>
  <div v-if="expiredColonies.length > 0 || expiringColonies.length > 0" class="flex flex-wrap gap-2">
    <div v-if="expiredColonies.length > 0" class="flex items-center gap-2 px-3 py-1.5 bg-red-500/10 border border-red-500/30 rounded-lg text-sm">
      <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></div>
      <span class="text-red-400">{{ t('pi.alerts.extractorsExpired', expiredColonies.length) }}</span>
      <span class="text-red-300/60">-</span>
      <span class="text-red-300/80 text-xs">
        {{ expiredColonies.map(c => `${c.solarSystemName || t('pi.colony.unknown')} (${c.planetType})`).join(', ') }}
      </span>
    </div>
    <div v-if="expiringColonies.length > 0" class="flex items-center gap-2 px-3 py-1.5 bg-amber-500/10 border border-amber-500/30 rounded-lg text-sm">
      <div class="w-2 h-2 rounded-full bg-amber-500 timer-urgent"></div>
      <span class="text-amber-400">
        <template v-if="thresholdMinutes === null">{{ t('pi.alerts.expireSoon', { count: expiringColonies.length }) }}</template>
        <template v-else>{{ t('pi.alerts.expireInWindow', { count: expiringColonies.length, window: formatExpiryThreshold(thresholdMinutes) }) }}</template>
      </span>
    </div>
  </div>
</template>

<style scoped>
@keyframes urgentPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
.timer-urgent {
  animation: urgentPulse 1.5s ease-in-out infinite;
}
</style>
