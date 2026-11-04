<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import {
  calendarDays,
  chunkClock,
  chunkCountdown,
  chunkDay,
  chunkTimeZone,
  formatChunkTime,
  moonChunkStatusAt,
  useCorporationMiningExtractions,
  type ChunkTimeMode,
  type ChunkView,
  type CorporationMiningExtraction,
  type CorporationMiningExtractions,
  type MoonChunkStatus,
} from '@/composables/useCorporationMiningExtractions'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import MoonChunkName from './MoonChunkName.vue'
import MoonChunkValueCell from './MoonChunkValueCell.vue'
import MoonChunkValueDetail from './MoonChunkValueDetail.vue'

const CLOCK_TICK_MS = 30_000

const LIST_COLUMNS = ['moon', 'structure', 'system', 'arrival', 'decay', 'countdown', 'status', 'value']

const views: ChunkView[] = ['list', 'week', 'month']
const timeModes: ChunkTimeMode[] = ['eve', 'local']
const statusGlyphs: Record<MoonChunkStatus, string> = { extracting: '◔', ready: '●', exploded: '✕' }
const statusClasses: Record<MoonChunkStatus, string> = {
  extracting: 'border-cyan-500/40 text-cyan-300',
  ready: 'border-emerald-500/40 text-emerald-300',
  exploded: 'border-slate-600 text-slate-400',
}

const { t, locale } = useI18n()
const authStore = useAuthStore()
const { corporations, isLoading, hasError, isRefreshing, syncProgress, timeMode, load, refresh } = useCorporationMiningExtractions()

const view = ref<ChunkView>('list')
const openDetailIds = ref<Set<string>>(new Set())
const now = ref(new Date())
let clockTimer: ReturnType<typeof setInterval> | null = null

const timeZone = computed(() => chunkTimeZone(timeMode.value))
const today = computed(() => chunkDay(now.value.toISOString(), timeZone.value))
const days = computed(() => (view.value === 'list' ? [] : calendarDays(view.value, today.value)))
const progressLabel = computed(() => {
  const percent = syncProgress.value?.progress ?? null
  return percent === null ? t('ledger.mining.moonChunks.syncing') : t('ledger.mining.moonChunks.syncProgress', { percent })
})

function chunksByArrival(extractions: CorporationMiningExtraction[]): CorporationMiningExtraction[] {
  return [...extractions].sort((left, right) => new Date(left.chunkArrivalTime).getTime() - new Date(right.chunkArrivalTime).getTime())
}

function chunksOfDay(entry: CorporationMiningExtractions, day: string): CorporationMiningExtraction[] {
  return chunksByArrival(entry.extractions).filter(extraction => chunkDay(extraction.chunkArrivalTime, timeZone.value) === day)
}

function corporationLabel(corporationId: number): string {
  const character = authStore.user?.characters.find(candidate => candidate.corporationId === corporationId)
  return character?.corporationName ?? t('ledger.mining.moonChunks.corporation', { id: corporationId })
}

function dayLabel(day: string): string {
  return new Intl.DateTimeFormat(locale.value, { weekday: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(`${day}T00:00:00Z`))
}

function statusOf(extraction: CorporationMiningExtraction): MoonChunkStatus {
  return moonChunkStatusAt(extraction, now.value)
}

function toggleDetail(extractionId: string): void {
  const next = new Set(openDetailIds.value)
  if (!next.delete(extractionId)) next.add(extractionId)
  openDetailIds.value = next
}

function toggleClasses(active: boolean): string[] {
  return [
    'px-3 py-1 rounded-sm border text-xs font-medium uppercase tracking-wider transition-colors',
    active ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 text-slate-400 hover:text-slate-200',
  ]
}

onMounted(() => {
  void load()
  clockTimer = setInterval(() => {
    now.value = new Date()
  }, CLOCK_TICK_MS)
})

onUnmounted(() => {
  if (clockTimer !== null) clearInterval(clockTimer)
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 class="text-lg font-semibold text-white">{{ t('ledger.mining.moonChunks.title') }}</h3>
        <p class="text-sm text-slate-400">{{ t('ledger.mining.moonChunks.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-3">
        <span v-if="isRefreshing" data-sync-progress role="status" class="text-xs uppercase tracking-wider text-cyan-300">
          {{ progressLabel }}
        </span>
        <button
          type="button"
          data-refresh
          :disabled="isRefreshing"
          class="px-3 py-1 rounded-sm border border-cyan-500/60 bg-cyan-500/10 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
          @click="refresh"
        >
          {{ isRefreshing ? t('ledger.mining.moonChunks.syncing') : t('ledger.mining.moonChunks.refresh') }}
        </button>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
      <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('ledger.mining.moonChunks.viewLabel')">
        <button
          v-for="candidate in views"
          :key="candidate"
          type="button"
          :data-view="candidate"
          :aria-pressed="view === candidate"
          :class="toggleClasses(view === candidate)"
          @click="view = candidate"
        >
          {{ t(`ledger.mining.moonChunks.views.${candidate}`) }}
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('ledger.mining.moonChunks.timeLabel')">
        <button
          v-for="candidate in timeModes"
          :key="candidate"
          type="button"
          :data-time-mode="candidate"
          :aria-pressed="timeMode === candidate"
          :class="toggleClasses(timeMode === candidate)"
          @click="timeMode = candidate"
        >
          {{ t(`ledger.mining.moonChunks.timeModes.${candidate}`) }}
        </button>
      </div>
    </div>

    <ErrorBanner v-if="hasError" :message="t('ledger.mining.moonChunks.loadError')" />

    <div v-if="isLoading && corporations.length === 0" class="flex justify-center py-12">
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>

    <section
      v-for="entry in corporations"
      :key="entry.corporationId"
      :data-corporation="entry.corporationId"
      class="space-y-3"
    >
      <h4 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ corporationLabel(entry.corporationId) }}</h4>

      <p
        v-if="entry.access !== 'ok'"
        :data-access-state="entry.access"
        role="status"
        class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-300"
      >
        <span aria-hidden="true">⚠ </span>{{ t(`ledger.mining.moonChunks.access.${entry.access}`) }}
        <template v-if="entry.lastSuccessAt !== null">
          · {{ t('ledger.mining.moonChunks.lastSuccess') }}
          <time data-last-success-at :datetime="entry.lastSuccessAt">{{ formatChunkTime(entry.lastSuccessAt, timeZone) }}</time>
        </template>
      </p>
      <p v-else-if="entry.extractions.length === 0" data-empty-extractions class="text-sm text-slate-500">
        {{ t('ledger.mining.moonChunks.empty') }}
      </p>

      <div v-if="entry.extractions.length > 0 && view === 'list'" class="bg-slate-900 rounded-xl border border-slate-800 overflow-x-auto">
        <table class="w-full">
          <thead class="bg-slate-900/50">
            <tr>
              <th
                v-for="column in LIST_COLUMNS"
                :key="column"
                scope="col"
                class="px-3 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider whitespace-nowrap"
              >
                {{ t(`ledger.mining.moonChunks.columns.${column}`) }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <template v-for="extraction in chunksByArrival(entry.extractions)" :key="extraction.id">
              <tr
                :data-extraction-id="extraction.id"
                class="hover:bg-slate-900/30 transition-colors"
              >
                <td data-cell="moon" class="px-3 py-2 text-sm text-white max-w-[16rem]">
                  <MoonChunkName :name="extraction.moonName" :id="extraction.moonId" />
                </td>
                <td data-cell="structure" class="px-3 py-2 text-sm text-slate-300 max-w-[20rem]">
                  <MoonChunkName :name="extraction.structureName" :id="extraction.structureId" />
                </td>
                <td data-cell="system" class="px-3 py-2 text-sm text-slate-300">
                  <MoonChunkName :name="extraction.solarSystemName" :id="extraction.solarSystemId" />
                </td>
                <td data-cell="arrival" class="px-3 py-2 text-sm text-slate-300 whitespace-nowrap">
                  {{ formatChunkTime(extraction.chunkArrivalTime, timeZone) }}
                </td>
                <td data-cell="decay" class="px-3 py-2 text-sm text-slate-400 whitespace-nowrap">
                  {{ formatChunkTime(extraction.naturalDecayTime, timeZone) }}
                </td>
                <td data-cell="countdown" class="px-3 py-2 text-sm text-slate-300 whitespace-nowrap">
                  {{ chunkCountdown(extraction, now) ?? '—' }}
                </td>
                <td data-cell="status" :data-status="statusOf(extraction)" class="px-3 py-2 whitespace-nowrap">
                  <span :class="['rounded-sm border px-2 py-0.5 text-xs font-medium uppercase tracking-wider', statusClasses[statusOf(extraction)]]">
                    <span aria-hidden="true">{{ statusGlyphs[statusOf(extraction)] }} </span>{{ t(`ledger.mining.moonChunks.status.${statusOf(extraction)}`) }}
                  </span>
                </td>
                <MoonChunkValueCell :value="extraction.chunkValue" :expanded="openDetailIds.has(extraction.id)" @toggle="toggleDetail(extraction.id)" />
              </tr>
              <MoonChunkValueDetail
                v-if="extraction.chunkValue !== null && openDetailIds.has(extraction.id)"
                :corporation-id="entry.corporationId"
                :extraction="extraction"
                :value="extraction.chunkValue"
                :time-zone="timeZone"
                :column-count="LIST_COLUMNS.length"
                @recorded="load"
              />
            </template>
          </tbody>
        </table>
      </div>

      <div v-else-if="entry.extractions.length > 0" class="overflow-x-auto">
        <div class="grid min-w-[56rem] grid-cols-7 gap-px rounded-xl border border-slate-800 bg-slate-800 overflow-hidden">
          <div
            v-for="day in days"
            :key="day"
            :data-calendar-day="day"
            :aria-current="day === today ? 'date' : undefined"
            :class="['min-h-24 space-y-1 bg-slate-900 p-2', day === today ? 'ring-1 ring-inset ring-cyan-500/60' : '']"
          >
            <div class="text-xs uppercase tracking-wider text-slate-500">{{ dayLabel(day) }}</div>
            <div
              v-for="extraction in chunksOfDay(entry, day)"
              :key="extraction.id"
              :data-chunk="extraction.id"
              :data-status="statusOf(extraction)"
              :class="['rounded-sm border px-1.5 py-1 text-xs', statusClasses[statusOf(extraction)]]"
            >
              <span data-chunk-time class="font-semibold">{{ chunkClock(extraction.chunkArrivalTime, timeZone) }}</span>
              <MoonChunkName :name="extraction.moonName" :id="extraction.moonId" class="block text-white" />
              <span class="block uppercase tracking-wider">
                <span aria-hidden="true">{{ statusGlyphs[statusOf(extraction)] }} </span>{{ t(`ledger.mining.moonChunks.status.${statusOf(extraction)}`) }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
