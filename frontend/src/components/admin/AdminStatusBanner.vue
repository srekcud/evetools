<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AdminStatus, AdminStatusRule } from '@/stores/admin'

const props = defineProps<{ status: AdminStatus | null }>()

const { t } = useI18n()

const level = computed(() => props.status?.level ?? 'unknown')

const LEVEL_CLASSES: Record<string, string> = {
  ok: 'bg-emerald-500/10 border-emerald-500/50 text-emerald-400',
  degraded: 'bg-amber-500/10 border-amber-500/50 text-amber-400',
  down: 'bg-red-500/10 border-red-500/50 text-red-400',
  unknown: 'bg-slate-800 border-slate-600 text-slate-400',
}

const DOT_CLASSES: Record<string, string> = {
  ok: 'bg-emerald-400',
  degraded: 'bg-amber-400',
  down: 'bg-red-400 animate-pulse',
  unknown: 'bg-slate-500',
}

function ruleText(rule: AdminStatusRule): string {
  return t(`admin.status.rules.${rule.key}`, { count: rule.value, threshold: rule.threshold ?? 0 })
}

function goToSection(rule: AdminStatusRule): void {
  document.getElementById(`admin-section-${rule.section}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
</script>

<template>
  <div
    data-testid="admin-status-banner"
    :data-level="level"
    :class="['rounded-xl border px-4 py-3', LEVEL_CLASSES[level]]"
    role="status"
  >
    <div class="flex items-center gap-3">
      <span :class="['w-2.5 h-2.5 rounded-full shrink-0', DOT_CLASSES[level]]"></span>
      <span class="font-semibold uppercase tracking-wide text-sm">{{ t(`admin.status.levels.${level}`) }}</span>
    </div>
    <ul v-if="status && status.rules.length > 0" class="mt-2 space-y-1">
      <li v-for="rule in status.rules" :key="rule.key">
        <button
          type="button"
          data-testid="admin-status-rule"
          :data-rule-key="rule.key"
          :data-severity="rule.severity"
          class="text-sm text-left underline-offset-2 hover:underline"
          :class="rule.severity === 'down' ? 'text-red-300' : 'text-amber-300'"
          @click="goToSection(rule)"
        >
          {{ ruleText(rule) }}
        </button>
      </li>
    </ul>
  </div>
</template>
