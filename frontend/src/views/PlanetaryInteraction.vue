<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { usePlanetaryStore, type Colony, type PlanetarySlotCharacter } from '@/stores/planetary'
import { useSyncStore } from '@/stores/sync'
import { useFormatters } from '@/composables/useFormatters'
import MainLayout from '@/layouts/MainLayout.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import PiKpiCards from '@/components/planetary/PiKpiCards.vue'
import PiAlertsBanner from '@/components/planetary/PiAlertsBanner.vue'
import PiProductionTable from '@/components/planetary/PiProductionTable.vue'
import PiColonyCard from '@/components/planetary/PiColonyCard.vue'
import PiSlotsBadge from '@/components/planetary/PiSlotsBadge.vue'
import { useColonyNextActionText } from '@/composables/useColonyNextActionText'
import {
  COLONY_FILTER_IDS,
  compareNextActions,
  filterColonies,
  filterCounts,
  nextActionOf,
  sortColonies,
  type ColonyFilterId,
  type ColonySortMode,
  type NextAction,
} from '@/utils/colonyNextAction'
import PiTierLegend from '@/components/planetary/PiTierLegend.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useUpdateFlash } from '@/composables/useUpdateFlash'
import { useTabQuery } from '@/composables/useTabQuery'
import SubTabs from '@/components/common/SubTabs.vue'
import PiRevenueTab from '@/components/planetary/PiRevenueTab.vue'
import PiPlannerTab from '@/components/planetary/PiPlannerTab.vue'
import PiStockTab from '@/components/planetary/PiStockTab.vue'

type PlanetaryTab = 'planner' | 'colonies' | 'stock' | 'revenue'
type ColonyGrouping = 'account' | 'character'

interface ColonyGroup {
  key: string
  title: string
  avatars: { characterId: number; characterName: string }[]
  colonies: Colony[]
  nextAction: NextAction | null
  slots: { used: number; max: number | null } | null
  slotCharacters: PlanetarySlotCharacter[]
  showCharacterName: boolean
}

const COLONY_GROUPINGS = ['account', 'character'] as const satisfies readonly ColonyGrouping[]

const PLANETARY_TABS = ['planner', 'colonies', 'stock', 'revenue'] as const satisfies readonly PlanetaryTab[]
const COLONY_SORT_MODES = ['nextAction', 'character', 'system'] as const satisfies readonly ColonySortMode[]

const CLOCK_REFRESH_MS = 60_000

const { t } = useI18n()
const route = useRoute()
const planetaryStore = usePlanetaryStore()
const coloniesUpdated = useUpdateFlash(() => planetaryStore.colonies)
const syncStore = useSyncStore()
const { formatTimeSince } = useFormatters()
const { labelOf: nextActionLabelOf, isOverdue: isNextActionOverdue, whenOf: nextActionWhenOf } = useColonyNextActionText()
const activeTab = useTabQuery<PlanetaryTab>(PLANETARY_TABS, 'colonies')
const subTabs = computed(() => PLANETARY_TABS.map(tab => ({ id: tab, label: t(`pi.tabs.${tab}`) })))

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
const colonyGrouping = ref<ColonyGrouping>('account')
const colonySortMode = ref<ColonySortMode>('nextAction')
const colonyFilter = ref<ColonyFilterId>('all')
const now = ref(Date.now())

const clockTimer = setInterval(() => {
  now.value = Date.now()
}, CLOCK_REFRESH_MS)

// ========== Data loading ==========

onMounted(async () => {
  await Promise.all([
    planetaryStore.fetchColonies(),
    planetaryStore.fetchStats(),
    planetaryStore.fetchSlots(),
    planetaryStore.fetchProduction(),
    planetaryStore.fetchInputNeeds(),
  ])

  const hasNoColony = planetaryStore.error === null && planetaryStore.colonies.length === 0
  if (hasNoColony && route.query.tab === undefined) {
    activeTab.value = 'planner'
  }
})

onUnmounted(() => {
  clearInterval(clockTimer)
  if (syncDebounce) {
    clearTimeout(syncDebounce)
  }
})

// ========== Computed Stats ==========

const statsData = computed(() => planetaryStore.stats)

const totalCharacterCount = computed(() => {
  return planetaryStore.coloniesByCharacter.length
})

// ========== Colony groups ==========

const slotCharacters = computed(() => planetaryStore.slots?.characters ?? [])

const accountName = computed(() => {
  const main = slotCharacters.value.find(character => character.isMain) ?? slotCharacters.value[0]
  return main?.characterName ?? planetaryStore.coloniesByCharacter[0]?.characterName ?? ''
})

const inputNeeds = computed(() => planetaryStore.inputNeeds)

const chipCounts = computed(() => filterCounts(planetaryStore.colonies, inputNeeds.value, now.value))

function earliestActionOf(colonies: Colony[]): NextAction | null {
  const actions = colonies
    .map(colony => nextActionOf(colony, inputNeeds.value))
    .filter((action): action is NextAction => action !== null)
  if (actions.length === 0) return null
  return actions.reduce((nearest, action) => (action.at < nearest.at ? action : nearest))
}

function arrange(colonies: Colony[]): Colony[] {
  return sortColonies(filterColonies(colonies, colonyFilter.value, inputNeeds.value, now.value), colonySortMode.value, inputNeeds.value)
}

const actionByColonyId = computed(() => new Map(
  planetaryStore.colonies.map(colony => [colony.id, nextActionOf(colony, inputNeeds.value)] as const),
))

const colonyGroups = computed<ColonyGroup[]>(() => {
  const byCharacter = planetaryStore.coloniesByCharacter
  const slots = planetaryStore.slots

  if (colonyGrouping.value === 'account') {
    const colonies = arrange(byCharacter.flatMap(group => group.colonies))
    if (colonies.length === 0) return []
    return [{
      key: 'account',
      title: t('pi.groupBy.accountTitle', { name: accountName.value }),
      avatars: byCharacter.map(({ characterId, characterName }) => ({ characterId, characterName })),
      colonies,
      nextAction: earliestActionOf(colonies),
      slots: slots ? { used: slots.totalUsed, max: slots.totalMax } : null,
      slotCharacters: slotCharacters.value,
      showCharacterName: true,
    }]
  }

  const groups = byCharacter
    .map(group => {
      const characterSlots = slotCharacters.value.find(character => character.characterId === group.characterId)
      const colonies = arrange(group.colonies)
      return {
        key: String(group.characterId),
        title: group.characterName,
        avatars: [{ characterId: group.characterId, characterName: group.characterName }],
        colonies,
        nextAction: earliestActionOf(colonies),
        slots: characterSlots ? { used: characterSlots.coloniesUsed, max: characterSlots.maxColonies } : null,
        slotCharacters: [],
        showCharacterName: false,
      }
    })
    .filter(group => group.colonies.length > 0)

  if (colonySortMode.value !== 'nextAction') return groups
  return groups
    .map((group, index) => ({ group, index }))
    .sort((a, b) => compareNextActions(a.group.nextAction, b.group.nextAction) || a.index - b.index)
    .map(entry => entry.group)
})

const hasNoMatchingColony = computed(() => colonyGroups.value.length === 0)

function isOverMaximum(character: PlanetarySlotCharacter): boolean {
  return character.maxColonies !== null && character.coloniesUsed > character.maxColonies
}

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

      <SubTabs v-model="activeTab" :tabs="subTabs" />

      <PiPlannerTab v-if="activeTab === 'planner'" />

      <PiStockTab v-else-if="activeTab === 'stock'" />

      <PiRevenueTab v-else-if="activeTab === 'revenue'" />

      <template v-else>

      <!-- ============ ERROR BANNER ============ -->
      <ErrorBanner v-if="planetaryStore.error" :message="planetaryStore.error" @dismiss="planetaryStore.clearError()" />

      <!-- ============ LOADING STATE ============ -->
      <div v-if="planetaryStore.isLoading && !planetaryStore.colonies.length" class="flex items-center justify-center py-20">
        <div class="flex flex-col items-center gap-4">
          <LoadingSpinner size="xl" class="text-cyan-500" />
          <span class="text-slate-400 text-sm">{{ t('pi.loadingColonies') }}</span>
        </div>
      </div>

      <!-- ============ EMPTY STATE ============ -->
      <div
        v-else-if="!planetaryStore.isLoading && planetaryStore.colonies.length === 0 && !planetaryStore.error"
        class="flex flex-col items-center justify-center py-20 text-center"
      >
        <svg class="w-16 h-16 text-slate-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
          :active-extractors="statsData?.activeExtractors ?? null"
          :expired-extractors="statsData?.expiredExtractors ?? null"
          :total-extractors="statsData?.totalExtractors ?? null"
          :expiring-extractors="statsData?.expiringExtractors ?? null"
          :estimated-daily-isk="statsData?.estimatedDailyIsk ?? planetaryStore.totalDailyIsk"
          :threshold-minutes="statsData?.expiryThresholdMinutes ?? null"
          :updated="coloniesUpdated"
        />

        <PiAlertsBanner
          :expired-colonies="planetaryStore.expiredColonies"
          :expiring-colonies="planetaryStore.expiringColonies"
          :threshold-minutes="statsData?.expiryThresholdMinutes ?? null"
        />

        <div class="flex justify-end px-2">
          <RouterLink
            :to="{ name: 'settings', params: { section: 'notifications' } }"
            class="text-xs text-slate-400 hover:text-cyan-300 hover:underline"
            data-testid="pi-alert-settings-link"
          >
            {{ t('pi.alerts.settingsLink') }}
          </RouterLink>
        </div>

        <PiProductionTable
          :production="planetaryStore.production"
          :total-daily-isk="planetaryStore.totalDailyIsk"
        />

        <!-- ============ COLONIES GROUPS ============ -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-2">
          <div class="flex items-center gap-2">
            <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.groupBy.label') }}</span>
            <div class="flex items-center gap-1 p-0.5 bg-slate-900 border border-slate-800 rounded-lg">
              <button
                v-for="grouping in COLONY_GROUPINGS"
                :key="grouping"
                type="button"
                :data-testid="`group-by-${grouping}`"
                :aria-pressed="colonyGrouping === grouping"
                :class="[
                  'px-3 py-1 rounded-md text-xs font-semibold transition-colors',
                  colonyGrouping === grouping ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white',
                ]"
                @click="colonyGrouping = grouping"
              >
                {{ t(`pi.groupBy.${grouping}`) }}
              </button>
            </div>
          </div>
          <label class="flex items-center gap-2">
            <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.sort.label') }}</span>
            <select
              v-model="colonySortMode"
              data-testid="sort-select"
              class="px-2 py-1 bg-slate-900 border border-slate-800 rounded-lg text-xs font-semibold text-slate-300 focus:outline-none focus:border-cyan-500"
            >
              <option v-for="mode in COLONY_SORT_MODES" :key="mode" :value="mode">{{ t(`pi.sort.${mode}`) }}</option>
            </select>
          </label>
        </div>

        <div class="flex flex-wrap items-center gap-2 px-2" role="group" :aria-label="t('pi.filters.label')">
          <button
            v-for="filterId in COLONY_FILTER_IDS"
            :key="filterId"
            type="button"
            :data-testid="`filter-chip-${filterId}`"
            :data-count="chipCounts[filterId] ?? '?'"
            :aria-pressed="colonyFilter === filterId"
            :title="chipCounts[filterId] === null ? t('pi.filters.refillUnknown') : undefined"
            :class="[
              'flex items-center gap-1.5 px-3 py-1 rounded-full border text-xs font-semibold uppercase tracking-wider whitespace-nowrap transition-colors',
              colonyFilter === filterId ? 'bg-cyan-600 border-cyan-500 text-white' : 'bg-slate-900 border-slate-800 text-slate-400 hover:text-white',
              chipCounts[filterId] === 0 && colonyFilter !== filterId ? 'opacity-40' : '',
            ]"
            @click="colonyFilter = filterId"
          >
            <span>{{ t(`pi.filters.${filterId}`) }}</span>
            <span class="font-mono-tech">{{ chipCounts[filterId] ?? '?' }}</span>
          </button>
        </div>

        <div
          v-if="hasNoMatchingColony"
          data-testid="filter-empty"
          class="flex flex-col items-center gap-3 py-10 text-center"
        >
          <p class="text-sm text-slate-400">{{ t('pi.filters.empty') }}</p>
          <button
            type="button"
            data-testid="filter-reset"
            class="px-4 py-1.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-xs font-semibold text-slate-200 transition-colors"
            @click="colonyFilter = 'all'"
          >
            {{ t('pi.filters.reset') }}
          </button>
        </div>

        <div
          v-for="group in colonyGroups"
          :key="group.key"
          data-testid="colony-group-section"
          class="space-y-2"
        >
          <div class="flex items-center gap-3 px-2">
            <div class="flex items-center -space-x-2 shrink-0">
              <div
                v-for="avatar in group.avatars"
                :key="avatar.characterId"
                class="w-8 h-8 rounded-full bg-slate-700 border border-slate-600 overflow-hidden shrink-0"
              >
                <img
                  data-testid="account-avatar"
                  :src="`https://images.evetech.net/characters/${avatar.characterId}/portrait?size=64`"
                  :alt="avatar.characterName"
                  class="w-8 h-8 rounded-full"
                />
              </div>
            </div>
            <h2 data-testid="colony-group-title" class="text-lg font-semibold text-white truncate min-w-0">{{ group.title }}</h2>
            <span class="text-xs px-2 py-0.5 bg-slate-800 rounded-full text-slate-400 whitespace-nowrap">
              {{ t('common.units.colony', group.colonies.length) }}
            </span>
            <PiSlotsBadge v-if="group.slots" :used="group.slots.used" :max="group.slots.max" />
            <div class="flex-1 h-px bg-slate-800"></div>
            <span
              v-if="group.nextAction"
              data-testid="group-next-action"
              :data-action-type="group.nextAction.type"
              :class="['text-xs font-mono-tech whitespace-nowrap truncate max-w-[280px]', isNextActionOverdue(group.nextAction, now) ? 'text-red-400' : 'text-slate-400']"
              :title="nextActionLabelOf(group.nextAction.type)"
            >
              {{ t('pi.nextAction.group') }} {{ nextActionWhenOf(group.nextAction, now) }}
            </span>
          </div>

          <ul
            v-if="group.slotCharacters.length > 0"
            :aria-label="t('pi.slots.characters')"
            class="flex flex-wrap gap-2 px-2"
          >
            <li
              v-for="character in group.slotCharacters"
              :key="character.characterId"
              data-testid="slots-character"
              class="flex items-center gap-2 px-2.5 py-1 bg-slate-900 border border-slate-800 rounded-lg text-xs max-w-[240px]"
              :title="character.maxColonies === null ? t('pi.slots.unknownTooltip') : undefined"
            >
              <span class="text-slate-300 truncate">{{ character.characterName }}</span>
              <span :class="['font-mono-tech whitespace-nowrap', isOverMaximum(character) ? 'text-red-400' : 'text-slate-400']">
                {{ character.coloniesUsed }} / {{ character.maxColonies === null ? t('pi.slots.unknownMax') : character.maxColonies }}
              </span>
            </li>
          </ul>

          <div class="space-y-1">
            <PiColonyCard
              v-for="colony in group.colonies"
              :key="colony.id"
              :colony="colony"
              :expanded="expandedColonies.has(colony.id)"
              :threshold-minutes="statsData?.expiryThresholdMinutes"
              :show-character-name="group.showCharacterName"
              :next-action="actionByColonyId.get(colony.id) ?? null"
              :now="now"
              @toggle="toggleColony(colony.id)"
            />
          </div>
        </div>

        <PiTierLegend />

      </template>

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
