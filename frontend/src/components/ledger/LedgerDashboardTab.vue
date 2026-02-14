<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { LedgerDashboard, LedgerDailyStats } from '@/stores/ledger'

defineProps<{
  dashboard: LedgerDashboard
  dailyStats: LedgerDailyStats | null
  selectedDays: number
  maxDailyValue: number
}>()

const { t } = useI18n()
const { formatIskShort, formatIskFull, formatDate, formatDateTime } = useFormatters()

function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`
}
</script>

<template>
  <div class="space-y-6">
    <!-- KPI Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <!-- Total -->
      <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
        <div class="flex items-center justify-between">
          <span class="text-slate-400 text-sm">{{ t('common.status.total') }}</span>
          <span class="text-xs text-slate-500">{{ t('ledger.period.nDays', { n: selectedDays }) }}</span>
        </div>
        <div class="mt-2 text-2xl font-bold text-white">
          {{ formatIskShort(dashboard.totals.total) }}
        </div>
        <div class="mt-2 flex items-center gap-2">
          <div class="flex-1 h-2 bg-slate-700 rounded-full overflow-hidden">
            <div
              class="h-full bg-linear-to-r from-cyan-500 to-blue-500"
              :style="{ width: `${dashboard.pvePercent}%` }"
            />
          </div>
        </div>
        <div class="mt-1 flex justify-between text-xs text-slate-500">
          <span>PVE {{ formatPercent(dashboard.pvePercent) }}</span>
          <span>Mining {{ formatPercent(dashboard.miningPercent) }}</span>
        </div>
      </div>

      <!-- PVE -->
      <div class="bg-slate-900 rounded-xl p-6 border border-cyan-500/20">
        <div class="flex items-center justify-between">
          <span class="text-cyan-400 text-sm">PVE</span>
          <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
        </div>
        <div class="mt-2 text-2xl font-bold text-cyan-400">
          {{ formatIskShort(dashboard.totals.pve) }}
        </div>
        <div class="mt-2 text-xs text-slate-500">
          Bounties: {{ formatIskShort(dashboard.pveBreakdown.bounties) }}
        </div>
      </div>

      <!-- Mining -->
      <div class="bg-slate-900 rounded-xl p-6 border border-amber-500/20">
        <div class="flex items-center justify-between">
          <span class="text-amber-400 text-sm">Mining</span>
          <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
        </div>
        <div class="mt-2 text-2xl font-bold text-amber-400">
          {{ formatIskShort(dashboard.totals.mining) }}
        </div>
        <div class="mt-2 text-xs text-slate-500">
          ISK/jour: {{ formatIskShort(dashboard.iskPerDay) }}
        </div>
      </div>
    </div>

    <!-- Stacked Bar Chart -->
    <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
      <h3 class="text-lg font-semibold text-white mb-4">{{ t('ledger.dashboard.dailyRevenue') }}</h3>
      <div v-if="dailyStats?.daily" class="h-64 flex items-end gap-1">
        <div
          v-for="day in dailyStats.daily"
          :key="day.date"
          class="flex-1 flex flex-col justify-end gap-px"
          :title="`${formatDate(day.date)}\nPVE: ${formatIskFull(day.pve)}\nMining: ${formatIskFull(day.mining)}`"
        >
          <!-- Mining bar -->
          <div
            v-if="day.mining > 0"
            class="bg-amber-500/80 rounded-t-sm min-h-[2px]"
            :style="{ height: `${(day.mining / maxDailyValue) * 200}px` }"
          />
          <!-- PVE bar -->
          <div
            v-if="day.pve > 0"
            class="bg-cyan-500/80 min-h-[2px]"
            :class="{ 'rounded-t-sm': day.mining === 0 }"
            :style="{ height: `${(day.pve / maxDailyValue) * 200}px` }"
          />
        </div>
      </div>
      <div class="mt-4 flex items-center justify-center gap-6 text-sm">
        <div class="flex items-center gap-2">
          <div class="w-3 h-3 bg-cyan-500 rounded-sm" />
          <span class="text-slate-400">PVE</span>
        </div>
        <div class="flex items-center gap-2">
          <div class="w-3 h-3 bg-amber-500 rounded-sm" />
          <span class="text-slate-400">Mining</span>
        </div>
      </div>
    </div>

    <!-- Breakdown -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- PVE Breakdown -->
      <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
        <h3 class="text-lg font-semibold text-white mb-4">{{ t('ledger.dashboard.pveBreakdown') }}</h3>
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-slate-400">Bounties</span>
            <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.bounties) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-400">ESS</span>
            <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.ess) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-400">{{ t('ledger.pve.missionRewards') }}</span>
            <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.missions) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-400">{{ t('ledger.dashboard.loot') }}</span>
            <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.lootSales) }}</span>
          </div>
          <div v-if="dashboard.settings.corpProjectAccounting === 'pve'" class="flex items-center justify-between">
            <span class="text-slate-400">{{ t('ledger.pve.corpProject') }}</span>
            <span class="text-white font-medium">{{ formatIskShort(dashboard.pveBreakdown.corpProjects) }}</span>
          </div>
        </div>
      </div>

      <!-- Mining Breakdown -->
      <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
        <h3 class="text-lg font-semibold text-white mb-4">{{ t('ledger.dashboard.miningBreakdown') }}</h3>
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-slate-400">{{ t('ledger.mining.usage.sold') }}</span>
            <span class="text-emerald-400 font-medium">{{ formatIskShort(dashboard.miningBreakdown.sold) }}</span>
          </div>
          <div v-if="dashboard.settings.corpProjectAccounting === 'mining'" class="flex items-center justify-between">
            <span class="text-slate-400">{{ t('ledger.mining.usage.corpProject') }}</span>
            <span class="text-amber-400 font-medium">{{ formatIskShort(dashboard.miningBreakdown.corpProject) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-400">{{ t('ledger.mining.usage.unknown') }}</span>
            <span class="text-slate-500 font-medium">{{ formatIskShort(dashboard.miningBreakdown.unknown) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Last sync info -->
    <div class="text-center text-xs text-slate-500">
      <span v-if="dashboard.lastSync.pve">PVE: {{ formatDateTime(dashboard.lastSync.pve) }}</span>
      <span v-if="dashboard.lastSync.pve && dashboard.lastSync.mining"> | </span>
      <span v-if="dashboard.lastSync.mining">Mining: {{ formatDateTime(dashboard.lastSync.mining) }}</span>
    </div>
  </div>
</template>
