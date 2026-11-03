<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { MiningOperationStatus } from '@/stores/miningOperation'

defineProps<{
  status: MiningOperationStatus
}>()

const { t } = useI18n()

const STATUS_BADGE_CLASS: Record<MiningOperationStatus, string> = {
  planned: 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300',
  in_progress: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  closing: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
  shares: 'border-slate-600 bg-slate-800 text-slate-300',
  settled: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  completed: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
}
</script>

<template>
  <span class="shrink-0 rounded-sm border px-2 py-0.5 font-mono text-xs uppercase tracking-wider" :class="STATUS_BADGE_CLASS[status]">
    {{ t(`miningOperation.status.${status}`) }}
  </span>
</template>
