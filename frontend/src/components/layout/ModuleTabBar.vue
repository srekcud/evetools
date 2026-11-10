<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

export interface ModuleTab {
  id: string
  label: string
  to: RouteLocationRaw
  count?: number
}

defineProps<{
  tabs: ModuleTab[]
  activeId?: string
  ariaLabel?: string
}>()

const TAB_BASE_CLASS = 'flex min-w-0 items-center gap-1.5 border-b-2 px-3 py-2.5 font-display text-sm font-semibold uppercase tracking-wider transition-colors'
const TAB_ACTIVE_CLASS = 'border-cyan-400 text-cyan-300'
const TAB_INACTIVE_CLASS = 'border-transparent text-slate-400 hover:border-slate-600 hover:text-slate-200'
</script>

<template>
  <nav :aria-label="ariaLabel" class="flex min-w-0 border-b border-slate-800">
    <router-link
      v-for="tab in tabs"
      :key="tab.id"
      :to="tab.to"
      :title="tab.label"
      :aria-current="tab.id === activeId ? 'page' : undefined"
      :class="[TAB_BASE_CLASS, tab.id === activeId ? TAB_ACTIVE_CLASS : TAB_INACTIVE_CLASS]"
    >
      <span class="truncate">{{ tab.label }}</span>
      <span
        v-if="tab.count !== undefined"
        class="shrink-0 rounded border border-cyan-500/30 bg-cyan-500/10 px-1.5 font-mono text-xs text-cyan-400"
      >{{ tab.count }}</span>
    </router-link>
  </nav>
</template>
