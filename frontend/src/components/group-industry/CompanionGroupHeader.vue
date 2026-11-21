<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ACTIVITY_STYLES } from './companionActivityStyles'
import type { CompanionPane } from './companionCollapsedSections'
import type { ActivityGroup } from './companionTasks'

defineProps<{
  pane: CompanionPane
  group: ActivityGroup
  count: number
  open: boolean
}>()

defineEmits<{
  toggle: []
}>()

const { t } = useI18n()
</script>

<template>
  <button
    type="button"
    :data-testid="`companion-toggle-${pane}-${group}`"
    class="flex w-full items-center gap-1.5 text-left text-xs font-bold uppercase tracking-[0.14em]"
    :class="ACTIVITY_STYLES[group].text"
    :aria-expanded="open"
    @click="$emit('toggle')"
  >
    <svg class="h-2 w-2 flex-shrink-0 text-slate-500 transition-transform" :class="open ? 'rotate-90' : ''" viewBox="0 0 10 10" fill="currentColor" aria-hidden="true"><path d="M3 1l5 4-5 4z" /></svg>
    <span>{{ t(`groupIndustry.companion.activityGroups.${group}`) }}</span>
    <span class="font-mono font-normal normal-case tracking-normal text-slate-400">{{ count }}</span>
    <i class="h-px flex-1 bg-gradient-to-r from-current to-transparent opacity-30" aria-hidden="true" />
  </button>
</template>
