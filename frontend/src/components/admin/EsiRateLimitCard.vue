<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { AdminEsiRateLimitGroup } from '@/stores/admin'

defineProps<{
  groups: AdminEsiRateLimitGroup[]
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()

const levelClasses: Record<AdminEsiRateLimitGroup['level'], string> = {
  unknown: 'bg-slate-500/20 text-slate-400',
  ok: 'bg-emerald-500/20 text-emerald-400',
  low: 'bg-amber-500/20 text-amber-400',
  critical: 'bg-red-500/20 text-red-400',
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <h3 class="text-lg font-semibold text-slate-200 mb-4">{{ t('admin.esiRateLimit.title') }}</h3>

    <table v-if="groups.length > 0" class="w-full text-sm">
      <thead>
        <tr class="text-slate-500 text-xs uppercase">
          <th class="text-left pb-2 font-medium">{{ t('admin.esiRateLimit.group') }}</th>
          <th class="text-right pb-2 font-medium">{{ t('admin.esiRateLimit.remaining') }}</th>
          <th class="text-right pb-2 font-medium">{{ t('admin.esiRateLimit.level') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800">
        <tr v-for="group in groups" :key="group.group" data-testid="esi-rate-limit-row">
          <td data-testid="esi-rate-limit-group" class="py-1.5 font-mono text-xs text-slate-300">{{ group.group }}</td>
          <td class="py-1.5 text-right font-mono text-slate-100">
            <span data-testid="esi-rate-limit-remaining">{{ formatNumber(group.remaining) }}</span>
            <span class="text-slate-500"> / </span>
            <span data-testid="esi-rate-limit-limit" class="text-slate-400">{{ formatNumber(group.limit) }}</span>
          </td>
          <td class="py-1.5 text-right">
            <span
              data-testid="esi-rate-limit-level"
              :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', levelClasses[group.level]]"
            >{{ t(`admin.esiBudget.level.${group.level}`) }}</span>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else data-testid="esi-rate-limit-empty" class="text-sm text-slate-500">{{ t('admin.esiRateLimit.empty') }}</p>
  </div>
</template>
