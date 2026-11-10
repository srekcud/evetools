<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { SETTINGS_PATH } from '@/constants/settingsSections'

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
  settingsSection?: string
}>()

const { t } = useI18n()

const TAB_BASE_CLASS = 'flex min-w-0 items-center gap-1.5 border-b-2 px-3 py-2.5 font-display text-sm font-semibold uppercase tracking-wider transition-colors'
const TAB_ACTIVE_CLASS = 'border-cyan-400 text-cyan-300'
const TAB_INACTIVE_CLASS = 'border-transparent text-slate-400 hover:border-slate-600 hover:text-slate-200'
const SHORTCUT_CLASS = 'ml-auto flex shrink-0 items-center gap-1.5 border-b-2 border-transparent px-3 py-2.5 font-display text-xs font-semibold uppercase tracking-wider text-slate-400 transition-colors hover:text-cyan-300'
const SETTINGS_ICON_PATH = 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'
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
    <router-link
      v-if="settingsSection !== undefined"
      :to="`${SETTINGS_PATH}/${settingsSection}`"
      :aria-label="t('settings.shortcut')"
      :class="SHORTCUT_CLASS"
    >
      <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="SETTINGS_ICON_PATH" />
      </svg>
      <span class="hidden sm:inline">{{ t('settings.shortcut') }}</span>
    </router-link>
  </nav>
</template>
