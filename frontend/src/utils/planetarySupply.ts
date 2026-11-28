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
  characterId: number
  characterName: string
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
  rows: { key: string; colonyId: string | null; characterId: number; characterName: string; planetName: string | null; launchpadTypeName: string | null; consumedTypeIds: number[]; refill: LaunchpadRefill }[]
  totals: { typeId: number; quantity: number }[]
  totalM3: number
  excludedCount: number
}

export type SupplyHolderUnknownReason = 'missing_assets_scope' | 'assets_never_read'

export interface SupplyHolderCharacter {
  characterId: number
  characterName: string
  status: 'known' | 'unknown'
  unknownReason: SupplyHolderUnknownReason | null
  assetsReadAt: string | null
  stale: boolean
  quantities: { typeId: number; quantity: number }[]
}

export interface SupplyHolders {
  homeLocationId: number | null
  homeLocationName: string | null
  characters: SupplyHolderCharacter[]
}

export interface StockTransferLine {
  typeId: number
  typeName: string | null
  need: number
  transfer: number | null
  volumeM3: number | null
}

export interface StockTransferRecipient {
  characterId: number
  characterName: string
  lines: StockTransferLine[]
  totalM3: number | null
  excludedCount: number
  rowKeys: string[]
}

export interface StockHolder {
  characterId: number
  characterName: string
  available: number | null
  unknownReason: SupplyHolderUnknownReason | null
}

export interface StockTransferType {
  typeId: number
  typeName: string | null
  holders: StockHolder[]
  shortfall: number | null
}

export interface StockTransferPlan {
  recipients: StockTransferRecipient[]
  types: StockTransferType[]
}

const UNKNOWN_REFILL: LaunchpadRefill = {
  status: 'unknown',
  balancedDays: null,
  capacityDays: null,
  limited: false,
  lines: [],
  bringM3: null,
  autonomyDays: null,
}

export function isValidSupplyDays(days: number): boolean {
  return Number.isFinite(days) && days > 0 && days <= MAX_SUPPLY_DAYS
}

function feedVolumes(feeds: readonly SupplyFeed[]): number[] | null {
  const volumes = feeds.map(feed => feed.volumePerUnit)
  return volumes.every(volume => volume !== null) ? volumes : null
}

export function planLaunchpadRefill(launchpad: SupplyLaunchpad, days: number): LaunchpadRefill {
  const volumes = feedVolumes(launchpad.feeds)
  if (launchpad.capacityM3 === null || volumes === null) return { ...UNKNOWN_REFILL }

  const dailyVolume = launchpad.feeds.reduce((sum, feed, index) => sum + feed.dailyRate * volumes[index], 0)
  const capacityDays = launchpad.capacityM3 / dailyVolume
  const balancedDays = Math.min(days, capacityDays)

  const lines = launchpad.feeds.map(feed => ({
    typeId: feed.typeId,
    quantity: Math.max(0, Math.ceil(feed.dailyRate * balancedDays - feed.projectedStock)),
  }))
  const bringM3 = lines.reduce((sum, line, index) => sum + line.quantity * volumes[index], 0)
  const autonomies = launchpad.feeds
    .map((feed, index) => (feed.dailyRate > 0 ? (feed.projectedStock + lines[index].quantity) / feed.dailyRate : null))
    .filter(autonomy => autonomy !== null)

  return {
    status: 'known',
    balancedDays,
    capacityDays,
    limited: capacityDays < days,
    lines,
    bringM3,
    autonomyDays: autonomies.length === 0 ? null : Math.min(...autonomies),
  }
}

export function buildSupplyGrid(launchpads: readonly SupplyLaunchpad[], days: number): SupplyGrid {
  if (!isValidSupplyDays(days)) return { columns: [], rows: [], totals: [], totalM3: 0, excludedCount: 0 }

  const fed = launchpads.filter(launchpad => launchpad.feeds.length > 0)
  const rows = fed.map(launchpad => ({
    key: `${launchpad.colonyId}-${launchpad.pinId}`,
    colonyId: launchpad.colonyId,
    characterId: launchpad.characterId,
    characterName: launchpad.characterName,
    planetName: launchpad.planetName,
    launchpadTypeName: launchpad.typeName,
    consumedTypeIds: launchpad.feeds.map(feed => feed.typeId),
    refill: planLaunchpadRefill(launchpad, days),
  }))

  const typeNames = new Map<number, string | null>()
  fed.forEach(launchpad => launchpad.feeds.forEach(feed => {
    if (typeNames.get(feed.typeId) == null) typeNames.set(feed.typeId, feed.typeName)
  }))
  const columns = [...typeNames.entries()]
    .sort(([first], [second]) => first - second)
    .map(([typeId, typeName]) => ({ typeId, typeName }))

  const quantityByType = new Map<number, number>()
  let totalM3 = 0
  rows.forEach(row => {
    if (row.refill.status === 'unknown' || row.refill.bringM3 === null) return
    totalM3 += row.refill.bringM3
    row.refill.lines.forEach(line => {
      const known = quantityByType.get(line.typeId)
      quantityByType.set(line.typeId, known === undefined ? line.quantity : known + line.quantity)
    })
  })
  const totals = [...quantityByType.entries()]
    .sort(([first], [second]) => first - second)
    .map(([typeId, quantity]) => ({ typeId, quantity }))

  return {
    columns,
    rows,
    totals,
    totalM3,
    excludedCount: rows.filter(row => row.refill.status === 'unknown').length,
  }
}

function compareByName(first: { characterName: string }, second: { characterName: string }): number {
  return first.characterName.localeCompare(second.characterName)
}

function heldQuantity(holder: SupplyHolderCharacter, typeId: number): number {
  const entry = holder.quantities.find(quantity => quantity.typeId === typeId)
  return entry === undefined ? 0 : entry.quantity
}

function quantityIn(quantities: ReadonlyMap<number, number> | undefined, typeId: number): number {
  const quantity = quantities?.get(typeId)
  return quantity === undefined ? 0 : quantity
}

function sumOrNull(values: readonly (number | null)[]): number | null {
  let sum = 0
  for (const value of values) {
    if (value === null) return null
    sum += value
  }
  return sum
}

export function planStockTransfers(
  launchpads: readonly SupplyLaunchpad[],
  days: number,
  holders: SupplyHolders,
): StockTransferPlan {
  const grid = buildSupplyGrid(launchpads, days)
  if (grid.rows.length === 0) return { recipients: [], types: [] }

  const knownKeys = new Set(grid.rows.filter(row => row.refill.status === 'known').map(row => row.key))
  const volumeByType = new Map<number, number>()
  launchpads
    .filter(launchpad => knownKeys.has(`${launchpad.colonyId}-${launchpad.pinId}`))
    .forEach(launchpad => launchpad.feeds.forEach(feed => {
      if (feed.volumePerUnit !== null && !volumeByType.has(feed.typeId)) volumeByType.set(feed.typeId, feed.volumePerUnit)
    }))

  const holderById = new Map(holders.characters.map(character => [character.characterId, character]))
  const typeNameById = new Map(grid.columns.map(column => [column.typeId, column.typeName]))
  const homeIsSet = holders.homeLocationId !== null

  const needByCharacter = new Map<number, Map<number, number>>()

  function needOf(characterId: number, typeId: number): number {
    return quantityIn(needByCharacter.get(characterId), typeId)
  }

  const rowsByCharacter = new Map<number, typeof grid.rows>()
  grid.rows.forEach(row => {
    rowsByCharacter.set(row.characterId, [...(rowsByCharacter.get(row.characterId) ?? []), row])
    if (row.refill.status === 'unknown') return
    const needs = needByCharacter.get(row.characterId) ?? new Map<number, number>()
    row.refill.lines.forEach(line => needs.set(line.typeId, quantityIn(needs, line.typeId) + line.quantity))
    needByCharacter.set(row.characterId, needs)
  })

  function volumeOf(typeId: number): number {
    const volumePerUnit = volumeByType.get(typeId)
    if (volumePerUnit === undefined) throw new Error(`Missing volume for type ${typeId} on a known launchpad`)
    return volumePerUnit
  }

  function transferOf(characterId: number, need: number, typeId: number): number | null {
    const holder = holderById.get(characterId)
    if (!homeIsSet || holder === undefined || holder.status === 'unknown') return null
    return Math.max(0, need - heldQuantity(holder, typeId))
  }

  const recipients: StockTransferRecipient[] = [...rowsByCharacter.entries()]
    .map(([characterId, rows]) => {
      const lines: StockTransferLine[] = [...(needByCharacter.get(characterId) ?? new Map<number, number>()).entries()]
        .sort(([first], [second]) => first - second)
        .map(([typeId, need]) => {
          const transfer = transferOf(characterId, need, typeId)
          return {
            typeId,
            typeName: typeNameById.get(typeId) ?? null,
            need,
            transfer,
            volumeM3: transfer === null ? null : transfer * volumeOf(typeId),
          }
        })
      return {
        characterId,
        characterName: rows[0].characterName,
        lines,
        totalM3: lines.length === 0 ? null : sumOrNull(lines.map(line => line.volumeM3)),
        excludedCount: rows.filter(row => row.refill.status === 'unknown').length,
        rowKeys: rows.map(row => row.key),
      }
    })
    .sort(compareByName)

  const anyUnknownCharacter = holders.characters.some(character => character.status === 'unknown')

  const types: StockTransferType[] = grid.columns.map(column => {
    const known: (StockHolder & { available: number })[] = []
    const unknown: StockHolder[] = []
    if (homeIsSet) {
      holders.characters.forEach(character => {
        const identity = { characterId: character.characterId, characterName: character.characterName }
        if (character.status === 'unknown') {
          unknown.push({ ...identity, available: null, unknownReason: character.unknownReason })
          return
        }
        const available = Math.max(0, heldQuantity(character, column.typeId) - needOf(character.characterId, column.typeId))
        if (available > 0) known.push({ ...identity, available, unknownReason: null })
      })
    }
    known.sort((first, second) => second.available - first.available || compareByName(first, second))
    unknown.sort(compareByName)

    const transfers = recipients.flatMap(recipient => recipient.lines.filter(line => line.typeId === column.typeId).map(line => line.transfer))
    const totalTransfer = sumOrNull(transfers)
    const totalAvailable = known.reduce((sum, holder) => sum + holder.available, 0)
    const shortfall = !homeIsSet || anyUnknownCharacter || totalTransfer === null
      ? null
      : Math.max(0, totalTransfer - totalAvailable)

    return { typeId: column.typeId, typeName: column.typeName, holders: [...known, ...unknown], shortfall }
  })

  return { recipients, types }
}
