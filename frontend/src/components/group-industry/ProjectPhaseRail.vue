<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ProjectPhase, ProjectPhaseState } from '@/stores/group-industry/types'

defineProps<{
  phases: ProjectPhase[]
}>()

const { t } = useI18n()

const CELL_CLASSES: Record<ProjectPhaseState, string> = {
  done: 'bg-emerald-500/10 border-emerald-400 text-emerald-300',
  current: 'bg-cyan-500/15 border-cyan-400 text-cyan-300',
  blocked: 'bg-amber-500/15 border-amber-400 text-amber-300',
  upcoming: 'bg-slate-800/50 border-slate-700 text-slate-500',
}
</script>

<template>
  <ol
    data-testid="cockpit-rail"
    class="flex gap-1.5 overflow-x-auto pb-1 mb-4"
    :aria-label="t('groupIndustry.cockpit.railLabel')"
  >
    <li
      v-for="phase in phases"
      :key="phase.key"
      :data-testid="`cockpit-phase-${phase.key}`"
      :data-state="phase.state"
      :aria-current="phase.state === 'current' ? 'step' : undefined"
      class="min-w-36 flex-1 basis-0 border-t-2 px-4 py-2.5 [clip-path:polygon(0_0,calc(100%_-_10px)_0,100%_10px,100%_100%,10px_100%,0_calc(100%_-_10px))]"
      :class="CELL_CLASSES[phase.state]"
    >
      <div class="flex items-center justify-between gap-2">
        <span class="truncate text-xs font-semibold uppercase tracking-wider" :title="t(`groupIndustry.cockpit.phases.${phase.key}`)">
          {{ t(`groupIndustry.cockpit.phases.${phase.key}`) }}
        </span>
        <svg v-if="phase.state === 'done'" aria-hidden="true" class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
      </div>
      <p class="font-mono text-sm mt-0.5">{{ phase.counter.done }} / {{ phase.counter.total }}</p>
      <p
        v-if="phase.blockedReason"
        class="truncate text-xs mt-0.5"
        :title="t(`groupIndustry.cockpit.blockedReasons.${phase.blockedReason.code}`, { count: phase.blockedReason.count })"
      >
        {{ t(`groupIndustry.cockpit.blockedReasons.${phase.blockedReason.code}`, { count: phase.blockedReason.count }) }}
      </p>
      <span class="sr-only">{{ t(`groupIndustry.cockpit.states.${phase.state}`) }}</span>
    </li>
  </ol>
</template>
