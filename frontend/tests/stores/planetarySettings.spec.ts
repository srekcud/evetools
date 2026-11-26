import { createPinia, setActivePinia } from 'pinia'
import { ApiError, ValidationError, apiRequest } from '@/services/api'
import { usePlanetarySettingsStore } from '@/stores/planetarySettings'
import {
  AMARR_CANDIDATE,
  AMARR_STATION_ID,
  AMARR_STATION_NAME,
  ASHAB_CANDIDATE,
  ASHAB_FORTIZAR_ID,
  ASHAB_FORTIZAR_NAME,
  FAVORITE_HUB_ID,
  FAVORITE_HUB_NAME,
  ME_WITHOUT_FAVORITE,
  ME_WITH_PREFERRED_ONLY,
  ME_WITH_TWO_FAVORITES,
  PREFERRED_HUB_ID,
  PREFERRED_HUB_NAME,
  SECOND_FAVORITE_HUB_ID,
  SECOND_FAVORITE_HUB_NAME,
  SETTINGS_OF_A_NEW_USER,
  SETTINGS_WITH_AMARR_HOME,
  apiCalls,
  patchCalls,
  serve,
} from '../planetarySettingsFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const UNKNOWN_LOCATION_MESSAGE = 'This location is not known to any of your characters.'

function freshStore() {
  setActivePinia(createPinia())
  return usePlanetarySettingsStore()
}

function rejectedLocation(): ValidationError {
  return new ValidationError({
    '@type': 'ConstraintViolationList',
    status: 422,
    violations: [{ propertyPath: 'homeLocationId', message: UNKNOWN_LOCATION_MESSAGE }],
    description: UNKNOWN_LOCATION_MESSAGE,
    detail: UNKNOWN_LOCATION_MESSAGE,
  })
}

async function loadedStore() {
  serve({
    'GET /planetary/settings': SETTINGS_WITH_AMARR_HOME,
    'GET /planetary/settings/locations': { id: 'locations', locations: [AMARR_CANDIDATE, ASHAB_CANDIDATE] },
    'GET /me/settings': ME_WITH_TWO_FAVORITES,
  })
  const store = freshStore()
  await store.load()
  vi.mocked(apiRequest).mockClear()
  return store
}

describe('planetarySettings store', () => {
  it('loads the settings, the candidate locations and the favorite market structures as hub choices', async () => {
    serve({
      'GET /planetary/settings': SETTINGS_WITH_AMARR_HOME,
      'GET /planetary/settings/locations': { id: 'locations', locations: [AMARR_CANDIDATE, ASHAB_CANDIDATE] },
      'GET /me/settings': ME_WITH_TWO_FAVORITES,
    })
    const store = freshStore()

    await store.load()

    expect(apiCalls().map(call => `${call.method} ${call.endpoint}`).sort()).toEqual([
      'GET /me/settings',
      'GET /planetary/settings',
      'GET /planetary/settings/locations',
    ])
    expect(store.settings).toMatchObject({
      homeLocationId: AMARR_STATION_ID,
      homeLocationName: AMARR_STATION_NAME,
      coverageWeeks: 5,
      hubStructureId: FAVORITE_HUB_ID,
      hubStructureName: FAVORITE_HUB_NAME,
    })
    expect(store.locations).toEqual([AMARR_CANDIDATE, ASHAB_CANDIDATE])
    expect(store.hubChoices).toEqual([
      { id: FAVORITE_HUB_ID, name: FAVORITE_HUB_NAME },
      { id: SECOND_FAVORITE_HUB_ID, name: SECOND_FAVORITE_HUB_NAME },
    ])
    expect(store.isLoading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('offers the preferred market structure alone when there is no favorite and no hub choice when there is neither', async () => {
    serve({
      'GET /planetary/settings': SETTINGS_OF_A_NEW_USER,
      'GET /planetary/settings/locations': { id: 'locations', locations: [] },
      'GET /me/settings': ME_WITH_PREFERRED_ONLY,
    })
    const withPreferred = freshStore()
    await withPreferred.load()

    serve({
      'GET /planetary/settings': SETTINGS_OF_A_NEW_USER,
      'GET /planetary/settings/locations': { id: 'locations', locations: [] },
      'GET /me/settings': ME_WITHOUT_FAVORITE,
    })
    const withNothing = freshStore()
    await withNothing.load()

    expect(withPreferred.hubChoices).toEqual([{ id: PREFERRED_HUB_ID, name: PREFERRED_HUB_NAME }])
    expect(withPreferred.locations).toEqual([])
    expect(withNothing.hubChoices).toEqual([])
    expect(withNothing.locations).toEqual([])
    expect(withNothing.settings).toMatchObject({ homeLocationId: null, homeLocationName: null, coverageWeeks: 3 })
  })

  it('exposes an error and keeps no settings when a read fails', async () => {
    serve({
      'GET /planetary/settings': new ApiError('API error: 500', 500),
      'GET /planetary/settings/locations': { id: 'locations', locations: [AMARR_CANDIDATE] },
      'GET /me/settings': ME_WITH_TWO_FAVORITES,
    })
    const store = freshStore()

    await store.load()

    expect(store.settings).toBeNull()
    expect(store.error).toEqual(expect.any(String))
    expect(store.error).not.toBe('')
    expect(store.isLoading).toBe(false)
  })

  it('patches the coverage with the number alone and replaces the state with the answer, bounds of 1 and 52 included', async () => {
    const store = await loadedStore()
    serve({ 'PATCH /planetary/settings': (body) => ({ ...SETTINGS_WITH_AMARR_HOME, ...body }) })

    const results = [await store.saveCoverageWeeks(1), await store.saveCoverageWeeks(52), await store.saveCoverageWeeks(4)]

    expect(results).toEqual([true, true, true])
    expect(patchCalls()).toEqual([
      { method: 'PATCH', endpoint: '/planetary/settings', body: { coverageWeeks: 1 } },
      { method: 'PATCH', endpoint: '/planetary/settings', body: { coverageWeeks: 52 } },
      { method: 'PATCH', endpoint: '/planetary/settings', body: { coverageWeeks: 4 } },
    ])
    expect(store.settings).toMatchObject({ coverageWeeks: 4, homeLocationId: AMARR_STATION_ID, hubStructureId: FAVORITE_HUB_ID })
    expect(store.error).toBeNull()
  })

  it('refuses a coverage that is not a whole number from 1 to 52 without calling the API and keeps the previous value', async () => {
    const store = await loadedStore()

    const results = [
      await store.saveCoverageWeeks(0),
      await store.saveCoverageWeeks(53),
      await store.saveCoverageWeeks(-1),
      await store.saveCoverageWeeks(2.5),
      await store.saveCoverageWeeks(Number.NaN),
    ]

    expect(results).toEqual([false, false, false, false, false])
    expect(apiCalls()).toEqual([])
    expect(store.settings?.coverageWeeks).toBe(5)
  })

  it('patches the home location with its identifier alone, then clears it with null, replacing the state with each answer', async () => {
    const store = await loadedStore()
    serve({
      'PATCH /planetary/settings': (body) => body?.homeLocationId === null
        ? { ...SETTINGS_WITH_AMARR_HOME, homeLocationId: null, homeLocationName: null }
        : { ...SETTINGS_WITH_AMARR_HOME, homeLocationId: ASHAB_FORTIZAR_ID, homeLocationName: ASHAB_FORTIZAR_NAME },
    })

    const chosen = await store.saveHomeLocation(ASHAB_FORTIZAR_ID)
    const afterChoice = { ...store.settings }
    const cleared = await store.saveHomeLocation(null)

    expect([chosen, cleared]).toEqual([true, true])
    expect(patchCalls().map(call => call.body)).toEqual([{ homeLocationId: ASHAB_FORTIZAR_ID }, { homeLocationId: null }])
    expect(afterChoice).toMatchObject({ homeLocationId: ASHAB_FORTIZAR_ID, homeLocationName: ASHAB_FORTIZAR_NAME })
    expect(store.settings?.homeLocationId).toBeNull()
    expect(store.settings?.homeLocationName).toBeNull()
  })

  it('keeps the previous values and exposes an error when a save is refused or fails, then clears it on the next success', async () => {
    const store = await loadedStore()
    serve({ 'PATCH /planetary/settings': rejectedLocation() })

    const refused = await store.saveHomeLocation(ASHAB_FORTIZAR_ID)
    const refusedError = store.error
    serve({ 'PATCH /planetary/settings': new ApiError('API error: 500', 500) })
    const failed = await store.saveCoverageWeeks(9)
    const failedError = store.error
    const afterFailures = { ...store.settings }
    serve({ 'PATCH /planetary/settings': (body) => ({ ...SETTINGS_WITH_AMARR_HOME, ...body }) })
    const succeeded = await store.saveCoverageWeeks(9)

    expect([refused, failed, succeeded]).toEqual([false, false, true])
    expect(refusedError).toEqual(expect.any(String))
    expect(refusedError).not.toBe('')
    expect(failedError).toEqual(expect.any(String))
    expect(failedError).not.toBe('')
    expect(afterFailures).toMatchObject({ homeLocationId: AMARR_STATION_ID, homeLocationName: AMARR_STATION_NAME, coverageWeeks: 5 })
    expect(store.settings?.coverageWeeks).toBe(9)
    expect(store.error).toBeNull()
  })

  it('sends the identifier and the name of the chosen favorite to the market settings then reloads the planetary settings', async () => {
    const store = await loadedStore()
    serve({
      'PATCH /me/settings': { ...ME_WITH_TWO_FAVORITES, preferredMarketStructureId: SECOND_FAVORITE_HUB_ID, preferredMarketStructureName: SECOND_FAVORITE_HUB_NAME },
      'GET /planetary/settings': { ...SETTINGS_WITH_AMARR_HOME, hubStructureId: SECOND_FAVORITE_HUB_ID, hubStructureName: SECOND_FAVORITE_HUB_NAME },
    })

    const saved = await store.saveHub(SECOND_FAVORITE_HUB_ID)

    expect(saved).toBe(true)
    expect(apiCalls()).toEqual([
      {
        method: 'PATCH',
        endpoint: '/me/settings',
        body: { preferredMarketStructureId: SECOND_FAVORITE_HUB_ID, preferredMarketStructureName: SECOND_FAVORITE_HUB_NAME },
      },
      { method: 'GET', endpoint: '/planetary/settings', body: undefined },
    ])
    expect(store.settings).toMatchObject({ hubStructureId: SECOND_FAVORITE_HUB_ID, hubStructureName: SECOND_FAVORITE_HUB_NAME })

    serve({ 'PATCH /me/settings': rejectedLocation() })
    const refused = await store.saveHub(FAVORITE_HUB_ID)

    expect(refused).toBe(false)
    expect(apiCalls()).toHaveLength(1)
    expect(store.settings?.hubStructureId).toBe(SECOND_FAVORITE_HUB_ID)
    expect(store.error).toEqual(expect.any(String))
    expect(store.error).not.toBe('')
  })

  it('counts one more save revision per successful save, whatever the number of requests it takes, and none for a read, a refusal or an invalid entry', async () => {
    const store = await loadedStore()
    expect(store.saveRevision).toBe(0)
    serve({ 'PATCH /planetary/settings': { ...SETTINGS_WITH_AMARR_HOME, coverageWeeks: 7 } })

    await store.saveCoverageWeeks(7)
    expect(store.saveRevision).toBe(1)

    await store.saveHomeLocation(ASHAB_FORTIZAR_ID)
    expect(store.saveRevision).toBe(2)

    await store.saveCoverageWeeks(0)
    await store.saveCoverageWeeks(53)
    expect(store.saveRevision).toBe(2)

    serve({ 'PATCH /planetary/settings': rejectedLocation() })
    await store.saveHomeLocation(ASHAB_FORTIZAR_ID)
    expect(store.saveRevision).toBe(2)

    serve({
      'PATCH /me/settings': { ...ME_WITH_TWO_FAVORITES, preferredMarketStructureId: SECOND_FAVORITE_HUB_ID, preferredMarketStructureName: SECOND_FAVORITE_HUB_NAME },
      'GET /planetary/settings': { ...SETTINGS_WITH_AMARR_HOME, hubStructureId: SECOND_FAVORITE_HUB_ID, hubStructureName: SECOND_FAVORITE_HUB_NAME },
    })
    await store.saveHub(SECOND_FAVORITE_HUB_ID)
    expect(store.saveRevision).toBe(3)

    serve({ 'PATCH /me/settings': rejectedLocation() })
    await store.saveHub(FAVORITE_HUB_ID)
    expect(store.saveRevision).toBe(3)

    serve({
      'GET /planetary/settings': SETTINGS_WITH_AMARR_HOME,
      'GET /planetary/settings/locations': { id: 'locations', locations: [AMARR_CANDIDATE, ASHAB_CANDIDATE] },
      'GET /me/settings': ME_WITH_TWO_FAVORITES,
    })
    await store.load()
    expect(store.saveRevision).toBe(3)
  })
})
