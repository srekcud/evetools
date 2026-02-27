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
  typeName: string
  categoryId: number | null
  quantity: number
  locationId: number
  locationName: string
  locationType: string
  locationFlag: string | null
  divisionName?: string
  solarSystemId: number | null
  solarSystemName: string | null
  itemName: string | null
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
  allDivisions: Record<number, string>
  isDirector: boolean
  configuredByName: string | null
  updatedAt: string | null
}
