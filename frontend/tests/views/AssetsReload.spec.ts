import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore, type SyncProgress } from '@/stores/sync'
import type { User } from '@/types'
import { deferred, type Deferred } from '../deferred'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
}))

const CHARACTER_A_ID = '00000000-0000-4000-8000-0000000000c1'
const CHARACTER_B_ID = '00000000-0000-4000-8000-0000000000c2'
const ENDPOINT_A = `/api/me/characters/${CHARACTER_A_ID}/assets`
const ENDPOINT_B = `/api/me/characters/${CHARACTER_B_ID}/assets`
const STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const OTHER_STATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy'
const CONTAINER_ITEM_ID = 1050000000201
const LOADING_TEXT = en.assets.loading
const RELOADING_INDICATOR = '[data-testid="assets-reloading"]'

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
})

function assetAt(station: string, systemName: string, overrides: Record<string, unknown>) {
  return {
    categoryId: 4,
    locationId: station === STATION_NAME ? 60003760 : 60008494,
    locationName: station,
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: systemName,
    itemName: null,
    cachedAt: '2026-11-13T10:00:00+00:00',
    ...overrides,
  }
}

const characterAAssets = [
  assetAt(STATION_NAME, 'Jita', { id: '00000000-0000-4000-8000-000000000001', itemId: 1050000000101, typeId: 34, typeName: 'Tritanium', quantity: 8800 }),
  assetAt(STATION_NAME, 'Jita', { id: '00000000-0000-4000-8000-000000000002', itemId: CONTAINER_ITEM_ID, typeId: 3296, typeName: 'Station Container', quantity: 1, itemName: 'Project Box' }),
  assetAt(STATION_NAME, 'Jita', {
    id: '00000000-0000-4000-8000-000000000003',
    itemId: 1050000000301,
    typeId: 4246,
    typeName: 'Hydrogen Fuel Block',
    quantity: 120,
    locationId: CONTAINER_ITEM_ID,
    locationName: `Project Box — ${STATION_NAME}`,
    locationType: 'item',
    locationFlag: 'Unlocked',
  }),
  assetAt(OTHER_STATION_NAME, 'Amarr', { id: '00000000-0000-4000-8000-000000000004', itemId: 1050000000102, typeId: 35, typeName: 'Pyerite', quantity: 500 }),
]

const characterAAssetsAfterSync = [
  ...characterAAssets,
  assetAt(STATION_NAME, 'Jita', { id: '00000000-0000-4000-8000-000000000005', itemId: 1050000000103, typeId: 36, typeName: 'Mexallon', quantity: 77 }),
]

const characterAAssetsWithoutAmarr = characterAAssets.filter(asset => asset.solarSystemName !== 'Amarr')

const characterBAssets = [
  assetAt(OTHER_STATION_NAME, 'Amarr', { id: '00000000-0000-4000-8000-000000000011', itemId: 1050000000201, typeId: 37, typeName: 'Isogen', quantity: 4200 }),
]

type AssetsPayload = { items: unknown[] }
type PendingRequest = { deferredResponse: Deferred<Response>; signal: AbortSignal | undefined }

function jsonResponse(items: unknown[]): Response {
  return new Response(JSON.stringify({ items }), { status: 200 })
}

function characterIdentity(id: string, name: string, isMain: boolean) {
  return {
    id,
    eveCharacterId: isMain ? 2112000001 : 2112000002,
    name,
    corporationId: 98000001,
    corporationName: 'Test Corp',
    allianceId: null,
    allianceName: null,
    isMain,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

async function mountAssetsWithPendingRequests() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-0000000000u1',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [characterIdentity(CHARACTER_A_ID, 'Pilot A', true), characterIdentity(CHARACTER_B_ID, 'Pilot B', false)],
    corporationId: 98000001,
    corporationName: 'Test Corp',
  } as User
  const requests = new Map<string, PendingRequest[]>()
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const pending: PendingRequest = { deferredResponse: deferred<Response>(), signal: init?.signal ?? undefined }
    const endpoint = String(input)
    requests.set(endpoint, [...(requests.get(endpoint) ?? []), pending])
    return pending.deferredResponse.promise
  })
  vi.stubGlobal('fetch', fetchMock)
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
      },
    },
  })
  await flushPromises()
  const requestsTo = (endpoint: string) => requests.get(endpoint) ?? []
  return { wrapper, requestsTo, syncStore: useSyncStore() }
}

async function answer(request: PendingRequest | undefined, payload: AssetsPayload['items']) {
  if (!request) {
    throw new Error('request was not sent')
  }
  request.deferredResponse.resolve(jsonResponse(payload))
  await flushPromises()
}

async function loadCharacterA() {
  const context = await mountAssetsWithPendingRequests()
  await answer(context.requestsTo(ENDPOINT_A)[0], characterAAssets)
  return context
}

function completedSync(): SyncProgress {
  return { syncType: 'character-assets', status: 'completed', progress: null, message: null, data: null, timestamp: '2026-11-13T10:05:00+00:00' }
}

async function finishSync(context: Awaited<ReturnType<typeof loadCharacterA>>) {
  context.syncStore.syncStatus['character-assets'] = completedSync()
  await flushPromises()
}

async function switchToCharacterB(wrapper: Awaited<ReturnType<typeof loadCharacterA>>['wrapper']) {
  wrapper.findComponent(AssetsFilterBar).vm.$emit('update:selected-character-id', CHARACTER_B_ID)
  await flushPromises()
}

function visibleText(wrapper: Awaited<ReturnType<typeof loadCharacterA>>['wrapper']): string {
  return wrapper.text().replace(/\s+/g, ' ')
}

async function typeSearch(wrapper: Awaited<ReturnType<typeof loadCharacterA>>['wrapper'], query: string) {
  await wrapper.find('input[type="text"]').setValue(query)
  await flushPromises()
}

describe('Assets first load', () => {
  it('shows the spinner while nothing is on screen yet', async () => {
    const { wrapper } = await mountAssetsWithPendingRequests()

    expect(visibleText(wrapper)).toContain(LOADING_TEXT)
    expect(wrapper.find(RELOADING_INDICATOR).exists()).toBe(false)
  })
})

describe('Assets reload after a finished sync', () => {
  it('keeps the list on screen with a thin indicator instead of the spinner', async () => {
    const context = await loadCharacterA()

    await finishSync(context)

    expect(context.requestsTo(ENDPOINT_A)).toHaveLength(2)
    expect(visibleText(context.wrapper)).toContain('Tritanium')
    expect(visibleText(context.wrapper)).not.toContain(LOADING_TEXT)
    expect(context.wrapper.find(RELOADING_INDICATOR).exists()).toBe(true)
  })

  it('keeps the search text while the reload is pending', async () => {
    const context = await loadCharacterA()
    await typeSearch(context.wrapper, 'tritanium')

    await finishSync(context)

    expect((context.wrapper.find('input[type="text"]').element as HTMLInputElement).value).toBe('tritanium')
    expect(visibleText(context.wrapper)).toContain('Tritanium')
    expect(visibleText(context.wrapper)).not.toContain('Pyerite')
  })

  it('keeps the expanded container while the reload is pending and after the swap', async () => {
    const context = await loadCharacterA()
    const containerRow = context.wrapper.findAll('[role="button"]').find(row => row.text().includes('Station Container'))
    await containerRow?.trigger('click')
    expect(visibleText(context.wrapper)).toContain('Hydrogen Fuel Block')

    await finishSync(context)
    expect(visibleText(context.wrapper)).toContain('Hydrogen Fuel Block')

    await answer(context.requestsTo(ENDPOINT_A)[1], characterAAssetsAfterSync)
    expect(visibleText(context.wrapper)).toContain('Hydrogen Fuel Block')
    expect(visibleText(context.wrapper)).toContain('Mexallon')
  })

  it('swaps the new data in one go and removes the indicator', async () => {
    const context = await loadCharacterA()
    await finishSync(context)
    expect(visibleText(context.wrapper)).not.toContain('Mexallon')

    await answer(context.requestsTo(ENDPOINT_A)[1], characterAAssetsAfterSync)

    expect(visibleText(context.wrapper)).toContain('Mexallon')
    expect(context.wrapper.find(RELOADING_INDICATOR).exists()).toBe(false)
  })

  it('keeps the selected solar system when it still exists after the reload', async () => {
    const context = await loadCharacterA()
    context.wrapper.findComponent(AssetsFilterBar).vm.$emit('update:selected-solar-system', 'Jita')
    await flushPromises()

    await finishSync(context)
    await answer(context.requestsTo(ENDPOINT_A)[1], characterAAssetsAfterSync)

    expect(context.wrapper.findComponent(AssetsFilterBar).props('selectedSolarSystem')).toBe('Jita')
    expect(visibleText(context.wrapper)).toContain('Mexallon')
    expect(visibleText(context.wrapper)).not.toContain('Pyerite')
  })

  it('drops the selected solar system when it no longer exists after the reload', async () => {
    const context = await loadCharacterA()
    context.wrapper.findComponent(AssetsFilterBar).vm.$emit('update:selected-solar-system', 'Amarr')
    await flushPromises()

    await finishSync(context)
    await answer(context.requestsTo(ENDPOINT_A)[1], characterAAssetsWithoutAmarr)

    expect(context.wrapper.findComponent(AssetsFilterBar).props('selectedSolarSystem')).toBe('')
    expect(visibleText(context.wrapper)).toContain('Tritanium')
  })
})

describe('Assets character switch', () => {
  it('clears the list and the stats at once and shows the spinner', async () => {
    const context = await loadCharacterA()

    await switchToCharacterB(context.wrapper)

    expect(visibleText(context.wrapper)).not.toContain('Tritanium')
    expect(visibleText(context.wrapper)).toContain(LOADING_TEXT)
    expect(context.wrapper.findComponent({ name: 'AssetsStatsCards' }).props('totalItems')).toBe(0)
  })

  it('aborts the request of the previous character', async () => {
    const context = await mountAssetsWithPendingRequests()
    const [firstRequestOfA] = context.requestsTo(ENDPOINT_A)
    expect(firstRequestOfA?.signal?.aborted).toBe(false)

    await switchToCharacterB(context.wrapper)

    expect(firstRequestOfA?.signal?.aborted).toBe(true)
    expect(context.requestsTo(ENDPOINT_B)[0]?.signal?.aborted).toBe(false)
  })

  it('shows character B when the older answer of A arrives first', async () => {
    const context = await mountAssetsWithPendingRequests()
    const [requestOfA] = context.requestsTo(ENDPOINT_A)
    await switchToCharacterB(context.wrapper)
    const [requestOfB] = context.requestsTo(ENDPOINT_B)

    await answer(requestOfA, characterAAssets)
    await answer(requestOfB, characterBAssets)

    expect(visibleText(context.wrapper)).toContain('Isogen')
    expect(visibleText(context.wrapper)).not.toContain('Tritanium')
  })

  it('keeps character B when the older answer of A arrives last', async () => {
    const context = await mountAssetsWithPendingRequests()
    const [requestOfA] = context.requestsTo(ENDPOINT_A)
    await switchToCharacterB(context.wrapper)
    const [requestOfB] = context.requestsTo(ENDPOINT_B)

    await answer(requestOfB, characterBAssets)
    await answer(requestOfA, characterAAssets)

    expect(visibleText(context.wrapper)).toContain('Isogen')
    expect(visibleText(context.wrapper)).not.toContain('Tritanium')
  })

  it('does not show an error when the aborted request of A is rejected', async () => {
    const context = await mountAssetsWithPendingRequests()
    const [requestOfA] = context.requestsTo(ENDPOINT_A)
    await switchToCharacterB(context.wrapper)
    const [requestOfB] = context.requestsTo(ENDPOINT_B)

    requestOfA?.deferredResponse.reject(new DOMException('The operation was aborted.', 'AbortError'))
    await answer(requestOfB, characterBAssets)

    expect(visibleText(context.wrapper)).toContain('Isogen')
    expect(visibleText(context.wrapper)).not.toContain('Failed to load assets')
  })
})
