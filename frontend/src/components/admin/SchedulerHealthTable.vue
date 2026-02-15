<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { SchedulerHealthEntry } from '@/stores/admin'

defineProps<{
  entries: SchedulerHealthEntry[]
  syncActionMap: Record<string, { action: () => Promise<{ success: boolean; message: string }>; key: string }>
  actionLoading: string | null
}>()

const emit = defineEmits<{
  'execute-action': [key: string, action: () => Promise<{ success: boolean; message: string }>]
}>()

const { t } = useI18n()
const { formatTimeSince } = useFormatters()

function formatInterval(seconds: number): string {
  if (seconds >= 3600) return `${seconds / 3600}h`
  return `${seconds / 60}min`
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <h3 class="text-lg font-semibold text-slate-200 mb-4">{{ t('admin.sync.title') }}</h3>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-slate-500 text-xs uppercase">
            <th class="text-left pb-3 font-medium">{{ t('admin.sync.type') }}</th>
            <th class="text-left pb-3 font-medium">{{ t('admin.sync.lastRun') }}</th>
            <th class="text-center pb-3 font-medium">{{ t('admin.sync.status') }}</th>
            <th class="text-center pb-3 font-medium">{{ t('admin.sync.health') }}</th>
            <th class="text-left pb-3 font-medium">{{ t('admin.sync.message') }}</th>
            <th class="text-center pb-3 font-medium w-16"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr v-for="entry in entries" :key="entry.type" class="hover:bg-slate-800/30">
            <td class="py-2.5 pr-3">
              <span class="text-slate-200 font-medium">{{ entry.label }}</span>
              <span class="text-slate-600 text-xs ml-1">({{ formatInterval(entry.expectedInterval) }})</span>
            </td>
            <td class="py-2.5 pr-3">
              <span class="text-slate-300 font-mono text-xs">{{ entry.completedAt ? formatTimeSince(entry.completedAt) : '--' }}</span>
            </td>
            <td class="py-2.5 text-center">
              <span
                :class="[
                  'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                  entry.status === 'ok' ? 'bg-emerald-500/20 text-emerald-400' :
                  entry.status === 'running' ? 'bg-blue-500/20 text-blue-400' :
                  entry.status === 'error' ? 'bg-red-500/20 text-red-400' :
                  'bg-slate-500/20 text-slate-400'
                ]"
              >
                <svg v-if="entry.status === 'running'" class="w-3 h-3 mr-1 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                {{ entry.status === 'ok' ? 'OK' : entry.status === 'running' ? t('admin.sync.running') : entry.status === 'error' ? t('common.status.error') : '--' }}
              </span>
            </td>
            <td class="py-2.5 text-center">
              <span
                :class="[
                  'inline-block w-2.5 h-2.5 rounded-full',
                  entry.health === 'healthy' ? 'bg-emerald-400' :
                  entry.health === 'late' ? 'bg-amber-400' :
                  entry.health === 'stale' ? 'bg-red-400' :
                  entry.health === 'running' ? 'bg-blue-400 animate-pulse' :
                  'bg-slate-600'
                ]"
                :title="entry.health === 'healthy' ? t('admin.health.onTime') :
                        entry.health === 'late' ? t('admin.health.late') :
                        entry.health === 'stale' ? t('admin.health.stale') :
                        entry.health === 'running' ? t('admin.sync.running') : t('admin.health.unknown')"
              ></span>
            </td>
            <td class="py-2.5 pl-3">
              <span class="text-slate-500 text-xs truncate max-w-[200px] inline-block">{{ entry.message || '--' }}</span>
            </td>
            <td class="py-2.5 text-center">
              <button
                v-if="syncActionMap[entry.type]"
                @click="emit('execute-action', syncActionMap[entry.type].key, syncActionMap[entry.type].action)"
                :disabled="actionLoading !== null"
                class="p-1.5 rounded-sm hover:bg-cyan-500/20 text-slate-500 hover:text-cyan-400 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                :title="t('admin.triggerSync')"
              >
                <svg :class="['w-3.5 h-3.5', actionLoading === syncActionMap[entry.type].key ? 'animate-spin' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="flex items-center gap-4 mt-4 pt-3 border-t border-slate-800 text-xs text-slate-500">
      <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> {{ t('admin.health.onTime') }}</div>
      <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-400"></span> {{ t('admin.health.lateShort') }}</div>
      <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-400"></span> {{ t('admin.health.staleShort') }}</div>
      <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-slate-600"></span> {{ t('admin.health.neverRun') }}</div>
    </div>
  </div>
</template>
