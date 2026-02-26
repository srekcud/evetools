<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { GroupProject } from '@/stores/group-industry/types'

const { t } = useI18n()
const { formatIsk } = useFormatters()

const props = defineProps<{
  projects: GroupProject[]
}>()

const activeCount = computed(() =>
  props.projects.filter(p => p.status !== 'completed').length
)

const totalContributions = computed(() =>
  props.projects.reduce((sum, p) => sum + p.totalBomValue * (p.fulfillmentPercent / 100), 0)
)

const pipelineValue = computed(() =>
  props.projects.reduce((sum, p) => sum + p.totalBomValue, 0)
)
</script>

<template>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Active Projects -->
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-4 card-enter" style="animation-delay: 60ms;">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('groupIndustry.kpis.activeProjects') }}</p>
        <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span>
      </div>
      <p class="text-3xl font-bold text-cyan-400 font-mono value-reveal" style="animation-delay: 300ms; font-variant-numeric: tabular-nums;">
        {{ activeCount }}
      </p>
      <p class="text-xs text-slate-600 mt-1">{{ t('groupIndustry.kpis.activeDesc') }}</p>
    </div>

    <!-- Total Contributions -->
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-4 card-enter" style="animation-delay: 120ms;">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('groupIndustry.kpis.totalContributions') }}</p>
        <svg class="w-4 h-4 text-emerald-500/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
        </svg>
      </div>
      <p class="text-3xl font-bold text-emerald-400 font-mono value-reveal" style="animation-delay: 360ms; font-variant-numeric: tabular-nums;">
        {{ formatIsk(totalContributions, 1) }}
      </p>
      <p class="text-xs text-slate-600 mt-1">{{ t('groupIndustry.kpis.contributionsDesc') }}</p>
    </div>

    <!-- Pending Approvals -->
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-4 card-enter" style="animation-delay: 180ms;">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('groupIndustry.kpis.pendingApprovals') }}</p>
        <svg class="w-4 h-4 text-amber-500/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <p class="text-3xl font-bold text-amber-400 font-mono value-reveal" style="animation-delay: 420ms; font-variant-numeric: tabular-nums;">
        0
      </p>
      <p class="text-xs text-slate-600 mt-1">{{ t('groupIndustry.kpis.approvalsDesc') }}</p>
    </div>

    <!-- Pipeline Value -->
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 p-4 card-enter" style="animation-delay: 240ms;">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('groupIndustry.kpis.pipelineValue') }}</p>
        <svg class="w-4 h-4 text-cyan-500/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
        </svg>
      </div>
      <p class="text-3xl font-bold text-white font-mono value-reveal" style="animation-delay: 480ms; font-variant-numeric: tabular-nums;">
        {{ formatIsk(pipelineValue, 1) }}
      </p>
      <p class="text-xs text-slate-600 mt-1">{{ t('groupIndustry.kpis.pipelineDesc') }}</p>
    </div>
  </div>
</template>

<style scoped>
@keyframes fadeIn {
  from { opacity: 0; filter: blur(4px); }
  to { opacity: 1; filter: blur(0); }
}
.value-reveal {
  animation: fadeIn 0.6s ease-out backwards;
}

@keyframes cardSlideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
.card-enter {
  animation: cardSlideUp 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) backwards;
}
</style>
