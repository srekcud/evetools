export const MAX_SUPPLY_DAYS = 90
export const DEFAULT_SUPPLY_DAYS = 4

export interface SupplyFeed {
  typeId: number
  typeName: string | null
  dailyRate: number
  stock: number
  projectedStock: number
  emptyAt: string | null
  volumePerUnit: number | null
}

export interface SupplyLaunchpad {
  colonyId: string | null
  planetId: number
  planetName: string | null
  pinId: number
  typeId: number
  typeName: string | null
  capacityM3: number | null
  usedVolumeM3: number | null
  dailyVolumeM3: number | null
  lastViewAt: string
  dataAgeSeconds: number
  dataStale: boolean
  feeds: SupplyFeed[]
}

export interface LaunchpadRefill {
  status: 'known' | 'unknown'
  balancedDays: number | null
  capacityDays: number | null
  limited: boolean
  lines: { typeId: number; quantity: number }[]
  bringM3: number | null
  autonomyDays: number | null
}

export interface SupplyGrid {
  columns: { typeId: number; typeName: string | null }[]
  rows: { key: string; planetName: string | null; launchpadTypeName: string | null; refill: LaunchpadRefill }[]
  totals: { typeId: number; quantity: number }[]
  totalM3: number
  excludedCount: number
}

export function isValidSupplyDays(): boolean {
  return true
}

export function planLaunchpadRefill(): LaunchpadRefill {
  return { status: 'unknown', balancedDays: null, capacityDays: null, limited: false, lines: [], bringM3: null, autonomyDays: null }
}

export function buildSupplyGrid(): SupplyGrid {
  return { columns: [], rows: [], totals: [], totalM3: 0, excludedCount: 0 }
}
