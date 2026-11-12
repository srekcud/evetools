<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { AdminEsiErrorBudget } from '@/stores/admin'

const props = defineProps<{
  budget: AdminEsiErrorBudget
}>()

const { t } = useI18n()
const { formatTimeSince, formatNumber } = useFormatters()

const MINIMUM_BAR_PERCENT = 8
const RELATIVE_TIME_TICK_MS = 30000

const ticked = ref<Date | null>(null)
let tickTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  tickTimer = setInterval(() => {
    ticked.value = new Date()
  }, RELATIVE_TIME_TICK_MS)
})

onUnmounted(() => {
  if (tickTimer !== null) clearInterval(tickTimer)
})

const displayedLevel = computed<AdminEsiErrorBudget['level']>(() => {
  const windowEndsAt = props.budget.windowEndsAt
  if (windowEndsAt !== null && ticked.value !== null && ticked.value.getTime() > new Date(windowEndsAt).getTime()) return 'unknown'
  return props.budget.level
})

const levelClasses: Record<AdminEsiErrorBudget['level'], string> = {
  unknown: 'bg-slate-500/20 text-slate-400',
  ok: 'bg-emerald-500/20 text-emerald-400',
  low: 'bg-amber-500/20 text-amber-400',
  critical: 'bg-red-500/20 text-red-400',
}

const busiestHour = computed(() =>
  Math.max(1, ...props.budget.hourly.map((hour) => hour.limited420 + hour.limited429)),
)

function barHeightPercent(limited420: number, limited429: number): number {
  const total = limited420 + limited429
  if (total === 0) return MINIMUM_BAR_PERCENT
  return Math.max(MINIMUM_BAR_PERCENT, Math.round((total / busiestHour.value) * 100))
}

function barClass(limited420: number, limited429: number): string {
  if (limited420 > 0) return 'bg-red-400'
  if (limited429 > 0) return 'bg-amber-400'
  return 'bg-slate-700'
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-slate-200">{{ t('admin.esiBudget.title') }}</h3>
      <span
        data-testid="esi-budget-level"
        :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', levelClasses[displayedLevel]]"
      >{{ t(`admin.esiBudget.level.${displayedLevel}`) }}</span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
      <div>
        <p class="text-xs uppercase text-slate-500">{{ t('admin.esiBudget.remain') }}</p>
        <p data-testid="esi-budget-remain" class="text-2xl font-mono text-slate-100">{{ budget.remain === null ? '--' : budget.remain }}</p>
      </div>
      <div>
        <p class="text-xs uppercase text-slate-500">{{ t('admin.esiBudget.lastReset') }}</p>
        <p data-testid="esi-budget-observed" class="text-sm font-mono text-slate-300">{{ budget.observedAt ? formatTimeSince(budget.observedAt, ticked ?? undefined) : '--' }}</p>
        <p v-if="budget.resetSeconds !== null" class="text-xs text-slate-500">{{ t('admin.esiBudget.resetIn', { seconds: budget.resetSeconds }) }}</p>
      </div>
      <div>
        <p class="text-xs uppercase text-slate-500">{{ t('admin.esiBudget.limited420') }}</p>
        <p data-testid="esi-budget-limited-420" class="text-2xl font-mono text-red-400">{{ formatNumber(budget.limited420) }}</p>
      </div>
      <div>
        <p class="text-xs uppercase text-slate-500">{{ t('admin.esiBudget.limited429') }}</p>
        <p data-testid="esi-budget-limited-429" class="text-2xl font-mono text-amber-400">{{ formatNumber(budget.limited429) }}</p>
      </div>
    </div>

    <div class="flex items-end gap-0.5 h-10 mb-1" :aria-label="t('admin.esiBudget.hourly')">
      <div
        v-for="hour in budget.hourly"
        :key="hour.hour"
        data-testid="esi-budget-hour"
        :class="['flex-1 rounded-sm', barClass(hour.limited420, hour.limited429)]"
        :style="{ height: `${barHeightPercent(hour.limited420, hour.limited429)}%` }"
        :title="`${hour.limited420} / ${hour.limited429}`"
      ></div>
    </div>
    <p class="text-xs text-slate-500 mb-4">{{ t('admin.esiBudget.hourly') }}</p>

    <table v-if="budget.byFamily.length > 0" class="w-full text-sm">
      <thead>
        <tr class="text-slate-500 text-xs uppercase">
          <th class="text-left pb-2 font-medium">{{ t('admin.esiBudget.family') }}</th>
          <th class="text-right pb-2 font-medium">420</th>
          <th class="text-right pb-2 font-medium">429</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800">
        <tr v-for="family in budget.byFamily" :key="family.family" data-testid="esi-budget-family">
          <td class="py-1.5 font-mono text-xs text-slate-300">{{ family.family }}</td>
          <td class="py-1.5 text-right font-mono text-red-400">{{ family.limited420 }}</td>
          <td class="py-1.5 text-right font-mono text-amber-400">{{ family.limited429 }}</td>
        </tr>
      </tbody>
    </table>
    <p v-else class="text-sm text-slate-500">{{ t('admin.esiBudget.noLimited') }}</p>
  </div>
</template>
