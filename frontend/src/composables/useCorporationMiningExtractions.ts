import { computed, ref } from 'vue'
import type { SyncProgress } from '@/stores/sync'

export type MoonChunkStatus = 'extracting' | 'ready' | 'exploded'

export type CorporationMiningAccess = 'never_synced' | 'ok' | 'denied' | 'error'

export type ChunkTimeMode = 'eve' | 'local'

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

export const CORPORATION_MINING_SYNC_TYPE = 'corporation-mining-extractions'

export function moonChunkStatusAt(_extraction: CorporationMiningExtraction, _now: Date): MoonChunkStatus {
  return 'extracting'
}

export function chunkCountdown(_extraction: CorporationMiningExtraction, _now: Date): string | null {
  return null
}

export function formatChunkTime(_iso: string, _timeZone: string): string {
  return ''
}

export function chunkTimeZone(_mode: ChunkTimeMode): string {
  return ''
}

export function useCorporationMiningExtractions() {
  const corporations = ref<CorporationMiningExtractions[]>([])
  const isLoading = ref(false)
  const hasError = ref(false)
  const isRefreshing = ref(false)
  const syncProgress = computed<SyncProgress | null>(() => null)
  const timeMode = ref<ChunkTimeMode>('local')

  async function load(): Promise<void> {}

  async function refresh(): Promise<void> {}

  return { corporations, isLoading, hasError, isRefreshing, syncProgress, timeMode, load, refresh }
}
