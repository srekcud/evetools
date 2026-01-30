<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { usePveStore } from '@/stores/pve'
import { useFormatters } from '@/composables/useFormatters'

const props = defineProps<{
  days?: number
}>()

const pveStore = usePveStore()
const { formatIsk } = useFormatters()

async function loadData() {
  const d = props.days ?? pveStore.selectedDays
  await Promise.all([
    pveStore.fetchStats(d),
    pveStore.fetchDailyStats(d),
  ])
}

onMounted(() => {
  loadData()
})

watch(() => props.days, () => {
  loadData()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Loading state -->
    <div v-if="pveStore.isLoading" class="flex items-center justify-center py-12">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-cyan-500"></div>
      <span class="ml-3 text-slate-400">Chargement des statistiques...</span>
    </div>

    <template v-else>
      <!-- Stats Summary Cards -->
      <div v-if="pveStore.stats" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-slate-800 rounded-lg p-4">
          <div class="text-slate-400 text-sm">Revenus totaux</div>
          <div class="text-2xl font-bold text-emerald-400">
            {{ formatIsk(pveStore.stats.totals.income) }}
          </div>
        </div>
        <div class="bg-slate-800 rounded-lg p-4">
          <div class="text-slate-400 text-sm">Dépenses totales</div>
          <div class="text-2xl font-bold text-red-400">
            {{ formatIsk(pveStore.stats.totals.expenses) }}
          </div>
        </div>
        <div class="bg-slate-800 rounded-lg p-4">
          <div class="text-slate-400 text-sm">Bénéfice net</div>
          <div
            :class="[
              'text-2xl font-bold',
              pveStore.stats.totals.profit >= 0 ? 'text-emerald-400' : 'text-red-400'
            ]"
          >
            {{ formatIsk(pveStore.stats.totals.profit) }}
          </div>
        </div>
        <div class="bg-slate-800 rounded-lg p-4">
          <div class="text-slate-400 text-sm">ISK/jour (moy.)</div>
          <div class="text-2xl font-bold text-cyan-400">
            {{ formatIsk(pveStore.stats.iskPerDay) }}
          </div>
        </div>
      </div>

      <!-- Income breakdown -->
      <div v-if="pveStore.stats" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-slate-800/50 rounded-lg p-3">
          <div class="text-slate-500 text-xs">Bounties</div>
          <div class="text-lg font-medium text-emerald-400">
            {{ formatIsk(pveStore.stats.totals.bounties) }}
          </div>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-3">
          <div class="text-slate-500 text-xs">ESS Payouts</div>
          <div class="text-lg font-medium text-violet-400">
            {{ formatIsk(pveStore.stats.totals.ess) }}
          </div>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-3">
          <div class="text-slate-500 text-xs">Missions</div>
          <div class="text-lg font-medium text-sky-400">
            {{ formatIsk(pveStore.stats.totals.missions) }}
          </div>
        </div>
        <div class="bg-slate-800/50 rounded-lg p-3">
          <div class="text-slate-500 text-xs">Ventes de loot</div>
          <div class="text-lg font-medium text-amber-400">
            {{ formatIsk(pveStore.stats.totals.lootSales) }}
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div
        v-if="!pveStore.stats && !pveStore.isLoading"
        class="text-center py-12 text-slate-500"
      >
        <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
        </svg>
        <p class="text-lg">Aucune donnée à afficher</p>
        <p class="text-sm mt-1">Commencez à suivre vos activités PVE pour voir les statistiques</p>
      </div>
    </template>
  </div>
</template>
