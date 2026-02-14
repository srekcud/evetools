<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

const { t } = useI18n()
const { formatIsk } = useFormatters()

defineProps<{
  totalColonies: number
  totalCharacters: number
  activeExtractors: number
  expiredExtractors: number
  totalExtractors: number
  expiringExtractors: number
  estimatedDailyIsk: number
}>()
</script>

<template>
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <!-- Colonies -->
    <div class="relative bg-slate-900 rounded-xl p-5 border border-slate-800 overflow-hidden scan-line">
      <div class="flex items-center justify-between">
        <span class="text-slate-400 text-sm font-medium uppercase tracking-wider">{{ t('pi.kpi.colonies') }}</span>
        <svg class="w-5 h-5 text-cyan-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" />
        </svg>
      </div>
      <div class="mt-2 text-3xl font-bold text-white font-mono-tech">{{ totalColonies }}</div>
      <div class="mt-1 text-xs text-slate-500">{{ t('pi.kpi.onCharacters', totalCharacters) }}</div>
    </div>

    <!-- Extracteurs actifs -->
    <div class="relative bg-slate-900 rounded-xl p-5 border border-emerald-500/20 overflow-hidden">
      <div class="flex items-center justify-between">
        <span class="text-emerald-400 text-sm font-medium uppercase tracking-wider">{{ t('pi.kpi.activeExtractors') }}</span>
        <svg class="w-5 h-5 text-emerald-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
      </div>
      <div class="mt-2 flex items-baseline gap-3">
        <span class="text-3xl font-bold text-emerald-400 font-mono-tech">{{ activeExtractors }}</span>
        <span v-if="expiredExtractors > 0" class="text-sm text-red-400 font-medium">
          {{ t('pi.kpi.expired', expiredExtractors) }}
        </span>
      </div>
      <div class="mt-1 text-xs text-slate-500">{{ t('pi.kpi.totalExtractors', { count: totalExtractors }) }}</div>
    </div>

    <!-- Expirent bientot -->
    <div class="relative bg-slate-900 rounded-xl p-5 border border-amber-500/20 overflow-hidden">
      <div class="flex items-center justify-between">
        <span class="text-amber-400 text-sm font-medium uppercase tracking-wider">{{ t('pi.kpi.expiringSoon') }}</span>
        <svg class="w-5 h-5 text-amber-400/60 timer-urgent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <div class="mt-2 text-3xl font-bold text-amber-400 font-mono-tech">{{ expiringExtractors }}</div>
      <div class="mt-1 text-xs text-slate-500">{{ t('pi.kpi.inNext24h') }}</div>
    </div>

    <!-- Production theorique -->
    <div class="relative bg-slate-900 rounded-xl p-5 border border-cyan-500/20 overflow-hidden">
      <div class="flex items-center justify-between">
        <span class="text-cyan-400 text-sm font-medium uppercase tracking-wider">{{ t('pi.kpi.theoreticalProduction') }}</span>
        <svg class="w-5 h-5 text-cyan-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
        </svg>
      </div>
      <div class="mt-2 text-3xl font-bold text-cyan-400 font-mono-tech">{{ formatIsk(estimatedDailyIsk) }}</div>
      <div class="mt-1 text-xs text-slate-500 flex items-center gap-1">
        {{ t('pi.kpi.iskPerDay') }}
        <span class="text-slate-600 cursor-help border-b border-dotted border-slate-600" :title="t('pi.production.jitaSellPriceTooltip')">{{ t('pi.kpi.sellPriceJita') }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.font-mono-tech {
  font-family: 'Share Tech Mono', monospace;
}

/* Scan line effect on KPI */
.scan-line {
  position: relative;
}
.scan-line::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(6,182,212,0.4), transparent);
  animation: scanDown 4s linear infinite;
}
@keyframes scanDown {
  0% { top: 0; opacity: 0; }
  10% { opacity: 1; }
  90% { opacity: 1; }
  100% { top: 100%; opacity: 0; }
}

/* Timer pulse for expiring soon */
@keyframes urgentPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
.timer-urgent {
  animation: urgentPulse 1.5s ease-in-out infinite;
}
</style>
