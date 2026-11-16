import type { LocationQuery, LocationQueryRaw } from 'vue-router'
import { ASSET_ITEM_SORTS, DEFAULT_ASSET_ITEM_SORT, type AssetItemSort } from '@/utils/assetItemSort'

export type AssetViewMode = 'character' | 'corporation'

export type AssetLocationSort = 'items' | 'name' | 'volume'

export interface AssetFilterState {
  viewMode: AssetViewMode
  characterId: string | null
  search: string
  solarSystem: string
  location: string
  division: string
  locationSort: AssetLocationSort
  itemSort: AssetItemSort
}

const OWNED_QUERY_KEYS = ['view', 'character', 'q', 'system', 'location', 'division', 'sort', 'itemSort'] as const
const CORPORATION_VIEW = 'corporation'
const DEFAULT_LOCATION_SORT: AssetLocationSort = 'items'
const LOCATION_SORTS: readonly AssetLocationSort[] = ['items', 'name', 'volume']
const LAST_CHARACTER_STORAGE_PREFIX = 'assetsLastCharacterId:'

function textOf(value: LocationQuery[string] | undefined): string {
  return typeof value === 'string' ? value : ''
}

export function readAssetFilterQuery(query: LocationQuery): AssetFilterState {
  return {
    viewMode: query.view === CORPORATION_VIEW ? 'corporation' : 'character',
    characterId: textOf(query.character) || null,
    search: textOf(query.q),
    solarSystem: textOf(query.system),
    location: textOf(query.location),
    division: textOf(query.division),
    locationSort: LOCATION_SORTS.find(sort => sort === query.sort) ?? DEFAULT_LOCATION_SORT,
    itemSort: ASSET_ITEM_SORTS.find(sort => sort === query.itemSort) ?? DEFAULT_ASSET_ITEM_SORT,
  }
}

export function writeAssetFilterQuery(current: LocationQuery, filters: AssetFilterState): LocationQueryRaw {
  const query: LocationQueryRaw = { ...current }
  for (const key of OWNED_QUERY_KEYS) {
    delete query[key]
  }
  if (filters.viewMode === 'corporation') {
    query.view = CORPORATION_VIEW
  } else if (filters.characterId) {
    query.character = filters.characterId
  }
  if (filters.search) query.q = filters.search
  if (filters.solarSystem) query.system = filters.solarSystem
  if (filters.location) query.location = filters.location
  if (filters.division) query.division = filters.division
  if (filters.locationSort !== DEFAULT_LOCATION_SORT) query.sort = filters.locationSort
  if (filters.itemSort !== DEFAULT_ASSET_ITEM_SORT) query.itemSort = filters.itemSort
  return query
}

export function readLastCharacterId(userId: string): string | null {
  return localStorage.getItem(LAST_CHARACTER_STORAGE_PREFIX + userId)
}

export function rememberLastCharacterId(userId: string, characterId: string): void {
  localStorage.setItem(LAST_CHARACTER_STORAGE_PREFIX + userId, characterId)
}
