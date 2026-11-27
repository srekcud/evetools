import { apiRequest } from '@/services/api'

export const AMARR_STATION_ID = 60008494
export const AMARR_STATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy'
export const ASHAB_FORTIZAR_ID = 1030000000123
export const ASHAB_FORTIZAR_NAME = 'Ashab - Market Fortizar'
export const JITA_STATION_ID = 60003760
export const JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
export const STORED_HOME_ID = 1030000000777
export const STORED_HOME_NAME = 'Old Home Raitaru'
export const PREFERRED_HUB_ID = 1028858195007
export const PREFERRED_HUB_NAME = 'Ashab - Preferred Fortizar'
export const FAVORITE_HUB_ID = 1022734985009
export const FAVORITE_HUB_NAME = 'Amarr - Favorite Raitaru'
export const SECOND_FAVORITE_HUB_ID = 1022734985011
export const SECOND_FAVORITE_HUB_NAME = 'Rens - Second Athanor'
export const DEFAULT_HUB_ID = 1049588174021
export const DEFAULT_HUB_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)'

export interface SettingsBody {
  id: string
  homeLocationId: number | null
  homeLocationName: string | null
  coverageWeeks: number
  excludedTypeIds: number[]
  hubStructureId: number
  hubStructureName: string
}

export interface MeSettingsBody {
  preferredMarketStructureId: number | null
  preferredMarketStructureName: string | null
  marketStructures: { id: number; name: string }[]
}

export const AMARR_CANDIDATE = { locationId: AMARR_STATION_ID, locationName: AMARR_STATION_NAME, solarSystemName: 'Amarr' }
export const ASHAB_CANDIDATE = { locationId: ASHAB_FORTIZAR_ID, locationName: ASHAB_FORTIZAR_NAME, solarSystemName: 'Ashab' }
export const JITA_CANDIDATE = { locationId: JITA_STATION_ID, locationName: JITA_STATION_NAME, solarSystemName: 'Jita' }

export const SETTINGS_WITH_AMARR_HOME: SettingsBody = {
  id: 'settings',
  homeLocationId: AMARR_STATION_ID,
  homeLocationName: AMARR_STATION_NAME,
  coverageWeeks: 5,
  excludedTypeIds: [],
  hubStructureId: FAVORITE_HUB_ID,
  hubStructureName: FAVORITE_HUB_NAME,
}

export const SETTINGS_OF_A_NEW_USER: SettingsBody = {
  id: 'settings',
  homeLocationId: null,
  homeLocationName: null,
  coverageWeeks: 3,
  excludedTypeIds: [],
  hubStructureId: DEFAULT_HUB_ID,
  hubStructureName: DEFAULT_HUB_NAME,
}

export const ME_WITH_TWO_FAVORITES: MeSettingsBody = {
  preferredMarketStructureId: null,
  preferredMarketStructureName: null,
  marketStructures: [
    { id: FAVORITE_HUB_ID, name: FAVORITE_HUB_NAME },
    { id: SECOND_FAVORITE_HUB_ID, name: SECOND_FAVORITE_HUB_NAME },
  ],
}

export const ME_WITH_PREFERRED_ONLY: MeSettingsBody = {
  preferredMarketStructureId: PREFERRED_HUB_ID,
  preferredMarketStructureName: PREFERRED_HUB_NAME,
  marketStructures: [],
}

export const ME_WITHOUT_FAVORITE: MeSettingsBody = {
  preferredMarketStructureId: null,
  preferredMarketStructureName: null,
  marketStructures: [],
}

export type RequestBody = Record<string, unknown> | undefined

export type Answer = Error | ((body: RequestBody) => unknown) | object

export interface ApiCall {
  method: string
  endpoint: string
  body: Record<string, unknown> | undefined
}

export function serve(answers: Record<string, Answer>): void {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options: RequestInit = {}) => {
    const method = options.method?.toUpperCase() ?? 'GET'
    const key = `${method} ${endpoint}`
    if (!(key in answers)) throw new Error(`Unexpected request ${key}`)
    const answer = answers[key]
    if (answer instanceof Error) throw answer
    const body = typeof options.body === 'string' ? JSON.parse(options.body) as Record<string, unknown> : undefined
    return typeof answer === 'function' ? (answer as (body: RequestBody) => unknown)(body) : answer
  }) as never)
}

export function apiCalls(): ApiCall[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => ({
    method: options?.method?.toUpperCase() ?? 'GET',
    endpoint,
    body: typeof options?.body === 'string' ? JSON.parse(options.body) as Record<string, unknown> : undefined,
  }))
}

export function patchCalls(): ApiCall[] {
  return apiCalls().filter(call => call.method === 'PATCH')
}
