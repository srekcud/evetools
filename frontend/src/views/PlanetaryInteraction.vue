<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlanetaryStore } from '@/stores/planetary'
import { useSyncStore } from '@/stores/sync'
import { useFormatters } from '@/composables/useFormatters'
import MainLayout from '@/layouts/MainLayout.vue'
import PiKpiCards from '@/components/planetary/PiKpiCards.vue'
import PiAlertsBanner from '@/components/planetary/PiAlertsBanner.vue'
import PiProductionTable from '@/components/planetary/PiProductionTable.vue'
import PiColonyCard from '@/components/planetary/PiColonyCard.vue'
import PiTierLegend from '@/components/planetary/PiTierLegend.vue'

const { t } = useI18n()
const planetaryStore = usePlanetaryStore()
const syncStore = useSyncStore()
const { formatTimeSince } = useFormatters()

// ========== Mercure sync tracking ==========

const planetarySyncProgress = computed(() => syncStore.getSyncProgress('planetary'))

const isSyncing = computed(() =>
  planetaryStore.isSyncing || syncStore.isLoading('planetary')
)

let syncDebounce: ReturnType<typeof setTimeout> | null = null

watch(
  () => planetarySyncProgress.value,
  (progress) => {
    if (!progress) return

    if (progress.status === 'completed') {
      if (syncDebounce) clearTimeout(syncDebounce)
      syncDebounce = setTimeout(() => {
        planetaryStore.finishSync()
        syncStore.clearSyncStatus('planetary')
        syncDebounce = null
      }, 3000)
    } else if (progress.status === 'error') {
      if (syncDebounce) { clearTimeout(syncDebounce); syncDebounce = null }
      planetaryStore.failSync(progress.message || undefined)
      syncStore.clearSyncStatus('planetary')
    } else if (progress.status === 'started' || progress.status === 'in_progress') {
      if (syncDebounce) { clearTimeout(syncDebounce); syncDebounce = null }
    }
  }
)

// ========== UI State ==========

const expandedColonies = ref<Set<string>>(new Set())

// ========== Data loading ==========

onMounted(async () => {
  await Promise.all([
    planetaryStore.fetchColonies(),
    planetaryStore.fetchStats(),
    planetaryStore.fetchProduction(),
  ])
})

onUnmounted(() => {
  if (syncDebounce) {
    clearTimeout(syncDebounce)
  }
})

// ========== Computed Stats ==========

const statsData = computed(() => planetaryStore.stats)

const totalCharacterCount = computed(() => {
  return planetaryStore.coloniesByCharacter.length
})

// ========== Colony expand/collapse ==========

async function toggleColony(colonyId: string): Promise<void> {
  if (expandedColonies.value.has(colonyId)) {
    expandedColonies.value.delete(colonyId)
  } else {
    expandedColonies.value.add(colonyId)
    const colony = planetaryStore.colonies.find(c => c.id === colonyId)
    if (colony && colony.pins.length === 0) {
      await planetaryStore.fetchColonyDetail(colonyId)
    }
  }
}
</script>

<template>
  <MainLayout>
    <div class="space-y-6">

      <!-- ============ HEADER ============ -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <div class="relative w-10 h-10">
            <svg class="w-10 h-10 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
            </svg>
            <div class="absolute inset-[-4px] border border-cyan-500/20 rounded-full orbit-ring"></div>
          </div>
          <div>
            <h1 class="text-2xl font-bold text-white tracking-wide">{{ t('pi.title') }}</h1>
            <p class="text-sm text-slate-500 -mt-0.5">{{ t('pi.subtitle') }}</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="text-xs text-slate-500">
            {{ t('pi.lastSync') }}
            <span class="text-slate-400">{{ planetaryStore.lastSyncAt ? formatTimeSince(planetaryStore.lastSyncAt) : t('common.status.never') }}</span>
          </span>
          <button
            @click="planetaryStore.syncColonies()"
            :disabled="isSyncing"
            class="flex items-center gap-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            :title="t('pi.syncTooltip')"
          >
            <svg
              :class="['w-4 h-4', isSyncing ? 'animate-spin' : '']"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ isSyncing ? t('common.actions.syncing') : t('common.actions.sync') }}
          </button>
        </div>
      </div>

      <!-- ============ ERROR BANNER ============ -->
      <div
        v-if="planetaryStore.error"
        class="flex items-center gap-3 px-4 py-3 bg-red-500/10 border border-red-500/30 rounded-lg"
      >
        <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <span class="text-red-400 text-sm">{{ planetaryStore.error }}</span>
        <button @click="planetaryStore.clearError()" class="ml-auto text-red-400 hover:text-red-300">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- ============ LOADING STATE ============ -->
      <div v-if="planetaryStore.isLoading && !planetaryStore.colonies.length" class="flex items-center justify-center py-20">
        <div class="flex flex-col items-center gap-4">
          <svg class="animate-spin h-10 w-10 text-cyan-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span class="text-slate-400 text-sm">{{ t('pi.loadingColonies') }}</span>
        </div>
      </div>

      <!-- ============ EMPTY STATE ============ -->
      <div
        v-else-if="!planetaryStore.isLoading && planetaryStore.colonies.length === 0 && !planetaryStore.error"
        class="flex flex-col items-center justify-center py-20 text-center"
      >
        <svg class="w-16 h-16 text-slate-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" />
        </svg>
        <h3 class="text-lg font-semibold text-slate-300 mb-2">{{ t('pi.noColonies') }}</h3>
        <p class="text-sm text-slate-500 max-w-md">
          {{ t('pi.noColoniesDescription') }}
        </p>
      </div>

      <!-- ============ MAIN CONTENT ============ -->
      <template v-else-if="planetaryStore.colonies.length > 0">

        <PiKpiCards
          :total-colonies="statsData?.totalColonies ?? planetaryStore.colonies.length"
          :total-characters="totalCharacterCount"
          :active-extractors="statsData?.activeExtractors ?? 0"
          :expired-extractors="statsData?.expiredExtractors ?? 0"
          :total-extractors="statsData?.totalExtractors ?? 0"
          :expiring-extractors="statsData?.expiringExtractors ?? 0"
          :estimated-daily-isk="statsData?.estimatedDailyIsk ?? planetaryStore.totalDailyIsk"
        />

        <PiAlertsBanner
          :expired-colonies="planetaryStore.expiredColonies"
          :expiring-colonies="planetaryStore.expiringColonies"
        />

        <PiProductionTable
          :production="planetaryStore.production"
          :total-daily-isk="planetaryStore.totalDailyIsk"
        />

        <!-- ============ COLONIES BY CHARACTER ============ -->
        <div
          v-for="group in planetaryStore.coloniesByCharacter"
          :key="group.characterId"
          class="space-y-2"
        >
          <!-- Character Header -->
          <div class="flex items-center gap-3 px-2">
            <div class="w-8 h-8 rounded-full bg-slate-700 border border-slate-600 overflow-hidden flex-shrink-0">
              <img
                :src="`https://images.evetech.net/characters/${group.characterId}/portrait?size=64`"
                :alt="group.characterName"
                class="w-8 h-8 rounded-full"
              />
            </div>
            <h2 class="text-lg font-semibold text-white">{{ group.characterName }}</h2>
            <span class="text-xs px-2 py-0.5 bg-slate-800 rounded-full text-slate-400">
              {{ t('common.units.colony', group.colonies.length) }}
            </span>
            <div class="flex-1 h-px bg-slate-800"></div>
          </div>

          <!-- Colony Cards -->
          <div class="space-y-1">
            <PiColonyCard
              v-for="colony in group.colonies"
              :key="colony.id"
              :colony="colony"
              :expanded="expandedColonies.has(colony.id)"
              @toggle="toggleColony(colony.id)"
            />
          </div>
        </div>

        <PiTierLegend />

      </template>

    </div>
  </MainLayout>
</template>

<style scoped>
/* Orbital ring animation */
@keyframes orbit {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.orbit-ring {
  animation: orbit 20s linear infinite;
}
</style>
