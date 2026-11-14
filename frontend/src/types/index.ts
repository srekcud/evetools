export interface Character {
  id: string
  eveCharacterId: number
  name: string
  corporationId: number
  corporationName: string
  allianceId: number | null
  allianceName: string | null
  isMain: boolean
  hasValidToken: boolean
  lastSyncAt: string | null
}

export interface AssetBlueprint {
  status: 'original' | 'copy' | 'not_synced'
  runs: number | null
  materialEfficiency: number | null
  timeEfficiency: number | null
}

export interface Asset {
  id: string
  itemId: number
  typeId: number
  typeName: string | null
  categoryId: number | null
  quantity: number
  locationId: number
  locationName: string
  locationType: string
  locationFlag: string | null
  divisionName: string | null
  solarSystemId: number | null
  solarSystemName: string | null
  itemName: string | null
  isSingleton: boolean | null
  canResolveItemName: boolean
  blueprint?: AssetBlueprint | null
  childCount?: number
  cachedAt: string
}

export interface AssetLocationSummaryRow {
  locationId: number
  locationType: string
  locationName: string
  solarSystemName: string | null
  itemCount: number
  shipCount: number
  divisions: { name: string; count: number }[]
  cachedAt: string | null
}

export interface AssetSummary {
  itemCount: number
  typeCount: number
  systemCount: number
  locationCount: number
  lastSyncedAt: string | null
  nextRefreshAt?: string | null
  locations: AssetLocationSummaryRow[]
}

export interface AssetSearchHit extends Asset {
  rootLocationId: number
  containerPath: number[]
}

export interface AssetSearchAnswer {
  query: string
  truncated: boolean
  locationIds: number[]
  hits: AssetSearchHit[]
}

export interface PackagedVolumesAnswer {
  id: string
  volumes: Record<string, number>
  excludedTypeIds: number[]
  refreshedAt: string | null
}

export interface PinnedContainer {
  id: string
  itemId: number | null
  name: string
  locationName: string | null
  solarSystemName: string | null
  divisionName: string | null
  itemCount: number | null
  found: boolean
  projectId: string | null
  projectName: string | null
}

export interface AssetChange {
  typeId: number
  typeName: string | null
  locationId: number
  locationName: string
  divisionName: string | null
  quantityDelta: number
}

export interface AssetChanges {
  syncedAt: string | null
  previousSyncedAt: string | null
  total: number
  changes: AssetChange[]
}

export type ItemNameResolution =
  | { status: 'loading' }
  | { status: 'named'; itemName: string }
  | { status: 'unnamed' }
  | { status: 'error'; message: string }

export interface ItemNameAnswer {
  id: string
  status: 'named' | 'unnamed'
  itemName: string | null
}

export interface User {
  id: string
  authStatus: string
  mainCharacter: Character | null
  characters: Character[]
  corporationId: number
  corporationName: string
}

export interface AssetsResponse {
  total: number
  items: Asset[]
}

export interface LocationGroup {
  locationId: number
  locationName: string
  locationType: string
  solarSystemId: number | null
  solarSystemName: string | null
  items: Asset[]
  totalQuantity: number
}

export type CorpAssetVisibility = {
  visibleDivisions: number[]
  restrictedDivisions: Record<number, { id: string; name: string }[]>
  shareBlueprintsOfHiddenDivisions: boolean
  accessibleDivisions: number[]
  players: { id: string; name: string }[]
  directorConnected: boolean
  allDivisions: Record<number, string>
  isDirector: boolean
  configuredByName: string | null
  updatedAt: string | null
}
