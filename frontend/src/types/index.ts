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
  cachedAt: string
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
