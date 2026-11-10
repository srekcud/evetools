import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import type { MiningCompression } from '@/stores/miningOperation'

export type MiningSiteStatus = 'active' | 'closed'
export type MiningSiteScanTypeStatus = 'present' | 'depleted'
export type EtaUnknownReason = 'not_depleting' | 'single_scan'

export interface MiningSite {
  id: string
  operationId?: string | null
  label: string
  solarSystemId?: number | null
  solarSystemName?: string | null
  status: MiningSiteStatus
  createdAt: string
  firstScanAt?: string | null
  lastScanAt?: string | null
  observedSeconds?: number | null
  firstVolumeM3?: number | null
  firstValue?: number | null
  averageTrendRate?: number | null
  remainingValue?: number | null
  missingPriceTypeIds: number[]
  recentEta?: string | null
  trendEta?: string | null
  closedAt?: string | null
  scanCount?: number
  extractedValue?: number | null
  lastScanNumber?: number | null
  lastScanAuthorName?: string | null
  compression?: MiningCompression | null
}

export interface MiningSiteScanType {
  typeId: number
  status: MiningSiteScanTypeStatus
  units: number
  volumeM3?: number | null
  unitPrice?: number | null
  remainingValue?: number | null
  recentRate?: number | null
  recentEta?: string | null
  trendRate?: number | null
  trendEta?: string | null
  maxDeviationUnits?: number | null
  maxDeviationPercent?: number | null
  etaUnknownReason?: EtaUnknownReason | null
  depletedBetween?: { from: string; to: string } | null
}

export interface MiningSiteScan {
  id: string
  number: number
  authorName?: string | null
  typeCount: number
  scannedAt: string
  totalUnits: number
  totalVolumeM3?: number | null
  remainingValue?: number | null
  missingPriceTypeIds: number[]
  recentEta?: string | null
  trendEta?: string | null
  types: MiningSiteScanType[]
}

export interface CreateMiningSiteInput {
  label: string
  solarSystemId?: number | null
  operationId?: string | null
}

export interface NextMiningSiteInput {
  label: string
  solarSystemId?: number | null
}

export interface MiningSiteScanLine {
  typeId: number
  units: number
}

const SITES_PATH = '/mining-sites'
const OPERATIONS_PATH = '/mining-operations'

function messageOf(cause: unknown, fallback: string): string {
  return cause instanceof Error ? cause.message : fallback
}

export const useMiningSiteStore = defineStore('miningSite', () => {
  const sites = ref<MiningSite[]>([])
  const currentSite = ref<MiningSite | null>(null)
  const scans = ref<MiningSiteScan[]>([])
  const operationSites = ref<MiningSite[]>([])
  const companionSite = ref<MiningSite | null>(null)
  const companionScans = ref<MiningSiteScan[]>([])
  const companionError = ref<string | null>(null)
  const error = ref<string | null>(null)

  async function fetchSites(): Promise<void> {
    error.value = null
    try {
      sites.value = await apiRequest<MiningSite[]>(SITES_PATH)
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to fetch the mining sites')
    }
  }

  async function fetchOperationSites(operationId: string): Promise<void> {
    error.value = null
    try {
      operationSites.value = await apiRequest<MiningSite[]>(`${SITES_PATH}?operationId=${operationId}`)
    } catch (cause) {
      operationSites.value = []
      error.value = messageOf(cause, 'Failed to fetch the sites of the mining operation')
    }
  }

  async function fetchSite(siteId: string): Promise<void> {
    error.value = null
    try {
      currentSite.value = await apiRequest<MiningSite>(`${SITES_PATH}/${siteId}`)
    } catch (cause) {
      currentSite.value = null
      error.value = messageOf(cause, 'Failed to fetch the mining site')
    }
  }

  async function fetchScans(siteId: string): Promise<void> {
    error.value = null
    try {
      scans.value = await apiRequest<MiningSiteScan[]>(`${SITES_PATH}/${siteId}/scans`)
    } catch (cause) {
      scans.value = []
      error.value = messageOf(cause, 'Failed to fetch the scans of the mining site')
    }
  }

  async function reloadSiteAndScans(siteId: string): Promise<void> {
    error.value = null
    try {
      const [reloadedSite, reloadedScans] = await Promise.all([
        apiRequest<MiningSite>(`${SITES_PATH}/${siteId}`),
        apiRequest<MiningSiteScan[]>(`${SITES_PATH}/${siteId}/scans`),
      ])
      currentSite.value = reloadedSite
      scans.value = reloadedScans
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to reload the mining site')
    }
  }

  async function fetchCompanion(siteId: string): Promise<void> {
    companionError.value = null
    try {
      const [loadedSite, loadedScans] = await Promise.all([
        apiRequest<MiningSite>(`${SITES_PATH}/${siteId}`),
        apiRequest<MiningSiteScan[]>(`${SITES_PATH}/${siteId}/scans`),
      ])
      companionSite.value = loadedSite
      companionScans.value = loadedScans
    } catch (cause) {
      companionSite.value = null
      companionScans.value = []
      companionError.value = messageOf(cause, 'Failed to load the site of the companion')
    }
  }

  function clearCompanion(): void {
    companionSite.value = null
    companionScans.value = []
    companionError.value = null
  }

  async function create(input: CreateMiningSiteInput): Promise<MiningSite> {
    error.value = null
    try {
      const created = await apiRequest<MiningSite>(SITES_PATH, { method: 'POST', body: JSON.stringify(input) })
      sites.value = [created, ...sites.value]
      return created
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to create the mining site')
      throw cause
    }
  }

  async function startNextSite(operationId: string, input: NextMiningSiteInput): Promise<MiningSite> {
    error.value = null
    let started: MiningSite
    try {
      started = await apiRequest<MiningSite>(`${OPERATIONS_PATH}/${operationId}/next-site`, { method: 'POST', body: JSON.stringify(input) })
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to start the next mining site')
      throw cause
    }
    await fetchOperationSites(operationId)
    return started
  }

  async function recordScan(siteId: string, lines: MiningSiteScanLine[]): Promise<MiningSiteScan> {
    error.value = null
    try {
      const recorded = await apiRequest<MiningSiteScan>(`${SITES_PATH}/${siteId}/scans`, { method: 'POST', body: JSON.stringify({ lines }) })
      if (currentSite.value === null || currentSite.value.id === siteId) scans.value = [...scans.value, recorded]
      return recorded
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to record the scan')
      throw cause
    }
  }

  async function close(siteId: string): Promise<MiningSite> {
    error.value = null
    try {
      const closed = await apiRequest<MiningSite>(`${SITES_PATH}/${siteId}/close`, { method: 'POST', body: JSON.stringify({}) })
      sites.value = sites.value.map(site => (site.id === closed.id ? closed : site))
      if (currentSite.value?.id === closed.id) currentSite.value = closed
      return closed
    } catch (cause) {
      error.value = messageOf(cause, 'Failed to close the mining site')
      throw cause
    }
  }

  return {
    sites,
    currentSite,
    scans,
    operationSites,
    companionSite,
    companionScans,
    companionError,
    error,
    fetchSites,
    fetchOperationSites,
    fetchSite,
    fetchScans,
    reloadSiteAndScans,
    fetchCompanion,
    clearCompanion,
    create,
    startNextSite,
    recordScan,
    close,
  }
})
