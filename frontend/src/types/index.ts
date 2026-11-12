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
  cachedAt: string
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
