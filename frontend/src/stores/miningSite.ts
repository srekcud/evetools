import { defineStore } from 'pinia'
import { ref } from 'vue'

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
  lastScanNumber?: number | null
  lastScanAuthorName?: string | null
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
}

export interface MiningSiteScanLine {
  typeId: number
  units: number
}

const NOT_IMPLEMENTED = 'not implemented'

export const useMiningSiteStore = defineStore('miningSite', () => {
  const sites = ref<MiningSite[]>([])
  const currentSite = ref<MiningSite | null>(null)
  const scans = ref<MiningSiteScan[]>([])
  const error = ref<string | null>(null)

  async function fetchSites(): Promise<void> {}

  async function fetchSite(_siteId: string): Promise<void> {}

  async function fetchScans(_siteId: string): Promise<void> {}

  async function create(_input: CreateMiningSiteInput): Promise<MiningSite> {
    throw new Error(NOT_IMPLEMENTED)
  }

  async function recordScan(_siteId: string, _lines: MiningSiteScanLine[]): Promise<MiningSiteScan> {
    throw new Error(NOT_IMPLEMENTED)
  }

  async function close(_siteId: string): Promise<MiningSite> {
    throw new Error(NOT_IMPLEMENTED)
  }

  return { sites, currentSite, scans, error, fetchSites, fetchSite, fetchScans, create, recordScan, close }
})
