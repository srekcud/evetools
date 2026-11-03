import { computed, ref, watch } from 'vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type SyncProgress } from '@/stores/sync'

export type MoonChunkStatus = 'extracting' | 'ready' | 'exploded'

export type CorporationMiningAccess = 'never_synced' | 'ok' | 'denied' | 'error'

export type ChunkTimeMode = 'eve' | 'local'

export type ChunkView = 'list' | 'week' | 'month'

export interface CorporationMiningExtraction {
  id: string
  structureId: number
  structureName: string | null
  solarSystemId: number | null
  solarSystemName: string | null
  moonId: number
  moonName: string | null
  extractionStartTime: string
  chunkArrivalTime: string
  naturalDecayTime: string
  status: MoonChunkStatus
}

export interface CorporationMiningExtractions {
  corporationId: number
  access: CorporationMiningAccess
  lastSuccessAt: string | null
  extractions: CorporationMiningExtraction[]
}

export interface CorporationMiningExtractionsResponse {
  corporations: CorporationMiningExtractions[]
}

const EXTRACTIONS_ENDPOINT = '/mining/corporation-extractions'
const EVE_TIME_ZONE = 'UTC'
const MS_PER_MINUTE = 60_000
const MINUTES_PER_HOUR = 60
const MINUTES_PER_DAY = 1_440
const DAYS_PER_WEEK = 7
const DAY_LENGTH = 'YYYY-MM-DD'.length
const MONTH_LENGTH = 'YYYY-MM'.length
const CLOCK_START = DAY_LENGTH + 1

export function moonChunkStatusAt(extraction: CorporationMiningExtraction, now: Date): MoonChunkStatus {
  const instant = now.getTime()
  if (instant < new Date(extraction.chunkArrivalTime).getTime()) return 'extracting'
  if (instant < new Date(extraction.naturalDecayTime).getTime()) return 'ready'
  return 'exploded'
}

export function chunkCountdown(extraction: CorporationMiningExtraction, now: Date): string | null {
  const status = moonChunkStatusAt(extraction, now)
  if (status === 'exploded') return null

  const target = status === 'extracting' ? extraction.chunkArrivalTime : extraction.naturalDecayTime
  const totalMinutes = Math.floor((new Date(target).getTime() - now.getTime()) / MS_PER_MINUTE)
  const days = Math.floor(totalMinutes / MINUTES_PER_DAY)
  const hours = Math.floor((totalMinutes % MINUTES_PER_DAY) / MINUTES_PER_HOUR)
  const minutes = String(totalMinutes % MINUTES_PER_HOUR).padStart(2, '0')

  return days > 0 ? `${days}d ${hours}h ${minutes}m` : `${hours}h ${minutes}m`
}

export function formatChunkTime(iso: string, timeZone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(iso))
  const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find(candidate => candidate.type === type)?.value ?? ''

  return `${part('year')}-${part('month')}-${part('day')} ${part('hour')}:${part('minute')}`
}

export function chunkTimeZone(mode: ChunkTimeMode): string {
  return mode === 'eve' ? EVE_TIME_ZONE : Intl.DateTimeFormat().resolvedOptions().timeZone
}

export function chunkDay(iso: string, timeZone: string): string {
  return formatChunkTime(iso, timeZone).slice(0, DAY_LENGTH)
}

export function chunkClock(iso: string, timeZone: string): string {
  return formatChunkTime(iso, timeZone).slice(CLOCK_START)
}

function shiftDay(day: string, offset: number): string {
  const shifted = new Date(`${day}T00:00:00Z`)
  shifted.setUTCDate(shifted.getUTCDate() + offset)
  return shifted.toISOString().slice(0, DAY_LENGTH)
}

function mondayOf(day: string): string {
  const daysSinceMonday = (new Date(`${day}T00:00:00Z`).getUTCDay() + DAYS_PER_WEEK - 1) % DAYS_PER_WEEK
  return shiftDay(day, -daysSinceMonday)
}

export function calendarDays(view: 'week' | 'month', today: string): string[] {
  const firstDay = view === 'week' ? mondayOf(today) : mondayOf(`${today.slice(0, MONTH_LENGTH)}-01`)
  const lastDay = view === 'week' ? shiftDay(firstDay, DAYS_PER_WEEK - 1) : shiftDay(mondayOf(lastDayOfMonth(today)), DAYS_PER_WEEK - 1)

  const days: string[] = []
  for (let day = firstDay; day <= lastDay; day = shiftDay(day, 1)) {
    days.push(day)
  }
  return days
}

function lastDayOfMonth(day: string): string {
  const firstOfMonth = new Date(`${day.slice(0, MONTH_LENGTH)}-01T00:00:00Z`)
  firstOfMonth.setUTCMonth(firstOfMonth.getUTCMonth() + 1)
  firstOfMonth.setUTCDate(0)
  return firstOfMonth.toISOString().slice(0, DAY_LENGTH)
}

export function useCorporationMiningExtractions() {
  const syncStore = useSyncStore()
  const corporations = ref<CorporationMiningExtractions[]>([])
  const isLoading = ref(false)
  const hasError = ref(false)
  const isRefreshing = ref(false)
  const timeMode = ref<ChunkTimeMode>('eve')

  const syncProgress = computed<SyncProgress | null>(() => {
    const progress = syncStore.corporationMiningExtractionsProgress
    return progress?.status === 'started' || progress?.status === 'in_progress' ? progress : null
  })

  async function load(): Promise<void> {
    isLoading.value = true
    try {
      const response = await apiRequest<CorporationMiningExtractionsResponse>(EXTRACTIONS_ENDPOINT)
      if (!Array.isArray(response.corporations)) throw new Error('Unexpected corporation moon chunks payload')
      corporations.value = response.corporations
      hasError.value = false
    } catch (e) {
      console.error('Failed to load the corporation moon chunks:', e)
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  async function refresh(): Promise<void> {
    isRefreshing.value = true
    try {
      await apiRequest(`${EXTRACTIONS_ENDPOINT}/sync`, { method: 'POST', body: JSON.stringify({}) })
    } catch (e) {
      console.error('Failed to request a corporation moon chunk sync:', e)
      hasError.value = true
      isRefreshing.value = false
    }
  }

  watch(() => syncStore.corporationMiningExtractionsProgress, progress => {
    if (progress?.status === 'completed') {
      isRefreshing.value = false
      void load()
    } else if (progress?.status === 'error') {
      isRefreshing.value = false
    }
  })

  return { corporations, isLoading, hasError, isRefreshing, syncProgress, timeMode, load, refresh }
}
