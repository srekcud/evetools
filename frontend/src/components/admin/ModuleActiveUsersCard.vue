<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AdminModuleActiveUsers } from '@/stores/admin'

const props = defineProps<{
  modules: AdminModuleActiveUsers[]
  activeUsersLast30Days: number
}>()

const { t } = useI18n()

const UNKNOWN_COUNT = '—'
const FULL_BAR_PERCENT = 100

function countText(count: number | null): string {
  return count === null ? UNKNOWN_COUNT : String(count)
}

function barPercent(entry: AdminModuleActiveUsers): number {
  if (entry.activeLast30Days === null || props.activeUsersLast30Days <= 0) return 0
  return Math.min(FULL_BAR_PERCENT, Math.round((entry.activeLast30Days / props.activeUsersLast30Days) * FULL_BAR_PERCENT))
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <h3 class="text-lg font-semibold text-slate-200">{{ t('admin.moduleActiveUsers.title') }}</h3>
    <p class="text-xs text-slate-500 mb-4">{{ t('admin.moduleActiveUsers.description') }}</p>

    <table class="w-full text-sm">
      <thead>
        <tr class="text-slate-500 text-xs uppercase">
          <th class="text-left pb-2 font-medium">{{ t('admin.moduleActiveUsers.module') }}</th>
          <th class="text-right pb-2 font-medium">{{ t('admin.moduleActiveUsers.last7Days') }}</th>
          <th class="text-right pb-2 font-medium">{{ t('admin.moduleActiveUsers.last30Days') }}</th>
          <th class="w-1/3 pb-2" />
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800">
        <tr
          v-for="entry in modules"
          :key="entry.module"
          :data-testid="`module-active-users-row-${entry.module}`"
        >
          <td class="py-1.5 text-slate-300">{{ t(`admin.moduleActiveUsers.modules.${entry.module}`) }}</td>
          <td data-testid="module-active-users-7d" class="py-1.5 text-right font-mono text-slate-300">{{ countText(entry.activeLast7Days) }}</td>
          <td data-testid="module-active-users-30d" class="py-1.5 text-right font-mono text-cyan-400">{{ countText(entry.activeLast30Days) }}</td>
          <td class="py-1.5 pl-4">
            <div v-if="entry.activeLast30Days !== null" class="h-2 rounded bg-slate-800">
              <div
                data-testid="module-active-users-bar"
                class="h-2 rounded bg-cyan-500"
                :style="{ width: `${barPercent(entry)}%` }"
              />
            </div>
            <span v-else class="text-xs text-slate-500">{{ t('admin.moduleActiveUsers.unavailable') }}</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
