<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import IndustryTodoPlaceGroup from '@/components/industry-todo/TodoPlaceGroup.vue'
import ProjectFlowCard from '@/components/group-industry/ProjectFlowCard.vue'
import { FLOW_COLUMNS, type FlowColumn } from '@/components/group-industry/projectFlow'
import { buildStationView, type StationProjectRow } from '@/components/group-industry/stationView'
import { useFlowReasonLabel } from '@/components/group-industry/useFlowReasonLabel'
import { useNow } from '@/composables/useNow'
import MainLayout from '@/layouts/MainLayout.vue'
import { useGroupStationViewStore } from '@/stores/group-industry/stationView'
import { useSyncStore } from '@/stores/sync'

const MS_PER_MINUTE = 60_000
const NO_VALUE = '—'

const { t } = useI18n()
const route = useRoute()
const store = useGroupStationViewStore()
const syncStore = useSyncStore()
const reasonLabel = useFlowReasonLabel()
const clock = useNow()

const locationId = computed(() => Number(route.params.locationId))
const now = computed(() => Math.floor(clock.value / MS_PER_MINUTE) * MS_PER_MINUTE)

const station = computed(() => buildStationView({
  locationId: locationId.value,
  sources: store.sources,
  actions: store.actions,
  now: now.value,
}))

const title = computed(() => station.value.name ?? t('groupIndustry.station.unnamed'))
const hasAccessToRead = computed(() => station.value.access.granted + station.value.access.unknown > 0)
const accessValue = computed(() => (hasAccessToRead.value ? t('groupIndustry.station.accessValue', { granted: station.value.access.granted, unknown: station.value.access.unknown }) : NO_VALUE))
const isEmpty = computed(() => store.loaded && store.error === null && station.value.rows.length === 0)

const tiles = computed(() => [
  { key: 'occupied', value: String(station.value.occupiedLines) },
  { key: 'expected', value: String(station.value.expectedCount) },
  { key: 'carryAway', value: String(station.value.carryAwayCount) },
  { key: 'access', value: accessValue.value },
])

function columnLabel(column: FlowColumn): string {
  return t(`groupIndustry.flow.columns.${column}`)
}

function roleLabel(row: StationProjectRow): string {
  return row.myRole === 'owner' ? t('groupIndustry.station.youOwn') : t('groupIndustry.station.ownedBy', { name: row.ownerName })
}

function projectLabel(row: StationProjectRow): string {
  return row.projectName === null || row.projectName === '' ? t('groupIndustry.station.unnamedProject') : row.projectName
}

onMounted(() => {
  void store.load()
})

watch(() => syncStore.getSyncProgress('industry-project'), () => {
  void store.load()
})
</script>

<template>
  <MainLayout>
    <IndustryModuleTabs active="projects" />
    <div data-testid="station-page">
      <nav class="mb-3 text-xs text-slate-500">
        <router-link :to="{ name: 'industry-projects' }" data-testid="station-back" class="text-cyan-400 hover:text-cyan-300">{{ t('groupIndustry.station.back') }}</router-link>
        <span class="mx-1">›</span>
        <span>{{ title }}</span>
      </nav>

      <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 data-testid="station-title" class="font-display text-xl font-bold tracking-wide text-cyan-400">{{ title }}</h1>
        <span v-if="station.solarSystemName !== null" data-testid="station-system" class="text-sm text-slate-400">{{ station.solarSystemName }}</span>
        <span
          v-if="station.here === true"
          data-testid="station-here"
          class="whitespace-nowrap rounded-sm border border-emerald-400/30 px-1.5 text-[10.5px] text-emerald-400"
        >{{ t('groupIndustry.flow.here') }}</span>
      </div>

      <p
        v-if="store.error !== null"
        data-testid="station-error"
        role="alert"
        class="mb-3 border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300"
      >{{ t('groupIndustry.station.loadError') }} {{ store.error }}</p>
      <p v-if="store.loading && !store.loaded" data-testid="station-loading" class="text-sm text-slate-400">{{ t('common.status.loading') }}</p>

      <template v-if="store.loaded">
        <div class="mb-4 grid grid-cols-2 gap-2 lg:grid-cols-4">
          <div
            v-for="tile in tiles"
            :key="tile.key"
            :data-testid="`station-tile-${tile.key === 'carryAway' ? 'carry-away' : tile.key}`"
            class="border border-slate-800 bg-slate-900 p-3"
          >
            <div data-testid="station-tile-label" class="font-display text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{{ t(`groupIndustry.station.tiles.${tile.key}`) }}</div>
            <div data-testid="station-tile-value" class="font-display text-lg font-bold text-slate-100">{{ tile.value }}</div>
            <div class="text-xs text-slate-500">{{ t(`groupIndustry.station.tileDetails.${tile.key}`) }}</div>
          </div>
        </div>

        <p
          v-for="entry in station.unavailable"
          :key="entry.projectId"
          data-testid="station-unavailable"
          role="alert"
          class="mb-2 border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-200"
        >{{ t('groupIndustry.station.unavailable', { project: entry.projectName ?? t('groupIndustry.station.unnamedProject'), reason: reasonLabel(entry.reason) }) }}</p>
        <p
          v-if="store.unreadable.length > 0"
          data-testid="station-unreadable"
          role="alert"
          class="mb-2 border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-300"
        >{{ t('groupIndustry.station.unreadable', { projects: store.unreadable.map(entry => entry.projectName ?? t('groupIndustry.station.unnamedProject')).join(', ') }) }}</p>

        <p v-if="isEmpty" data-testid="station-empty" class="border border-slate-800 bg-slate-950 px-3 py-6 text-sm text-slate-400">{{ t('groupIndustry.station.empty') }}</p>
        <template v-else-if="station.rows.length > 0">
          <div class="mb-2 font-display text-[13px] font-semibold uppercase tracking-[0.1em] text-slate-400">{{ t('groupIndustry.station.byProject') }}</div>
          <div class="overflow-x-auto pb-1">
            <div class="grid min-w-[930px] grid-cols-[150px_repeat(5,minmax(150px,1fr))] gap-1.5">
              <div />
              <div
                v-for="column in FLOW_COLUMNS"
                :key="column"
                class="px-1.5 py-1 font-display text-[11.5px] font-bold uppercase tracking-[0.12em] text-slate-500"
              >{{ columnLabel(column) }}</div>
              <div v-for="row in station.rows" :key="row.projectId" data-testid="station-project-row" class="contents">
                <div class="flex min-w-0 flex-col gap-1 border border-slate-800 bg-slate-900 p-2">
                  <router-link
                    :to="{ name: 'industry-project', params: { id: row.projectId } }"
                    data-testid="station-project-link"
                    class="min-w-0 hover:underline"
                  >
                    <span data-testid="station-project-name" class="break-words font-display text-[15px] font-bold tracking-wide text-cyan-400">{{ projectLabel(row) }}</span>
                  </router-link>
                  <span data-testid="station-project-role" class="text-[11px] text-slate-500">{{ roleLabel(row) }}</span>
                </div>
                <div
                  v-for="column in FLOW_COLUMNS"
                  :key="column"
                  :data-testid="`flow-cell-${column}`"
                  class="flex min-h-[70px] min-w-0 flex-col gap-1.5 border border-slate-800 bg-slate-950 p-1.5"
                >
                  <ProjectFlowCard v-for="card in row.cells[column]" :key="card.key" :card="card" />
                </div>
              </div>
            </div>
          </div>
          <p class="mt-2 text-xs text-slate-500">{{ t('groupIndustry.station.note') }}</p>
        </template>

        <section v-if="station.todo !== null" data-testid="station-todo" class="mt-4 border border-slate-800 bg-slate-950">
          <h2 data-testid="station-todo-title" class="border-b border-slate-800 px-3 py-2 font-display text-sm font-semibold tracking-wide text-slate-100">{{ t('groupIndustry.station.todoHere') }}</h2>
          <IndustryTodoPlaceGroup :group="station.todo" />
        </section>
      </template>
    </div>
  </MainLayout>
</template>
