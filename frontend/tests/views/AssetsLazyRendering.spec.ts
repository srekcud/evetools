import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { isReactive } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore, type SyncProgress } from '@/stores/sync'
import type { User } from '@/types'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const ENDPOINT = `/api/me/characters/${CHARACTER_ID}/assets`
const COLLAPSE_THRESHOLD = 200
const STATIONS = [
  { locationId: 60003760, name: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', system: 'Jita' },
  { locationId: 60008494, name: 'Amarr VIII (Oris) - Emperor Family Academy', system: 'Amarr' },
  { locationId: 60011866, name: 'Dodixie IX - Moon 20 - Federation Navy Assembly Plant', system: 'Dodixie' },
]

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
})

function rowAt(stationIndex: number, rowIndex: number, overrides: Record<string, unknown> = {}) {
  const station = STATIONS[stationIndex]
  const serial = stationIndex * 1000 + rowIndex
  return {
    id: `00000000-0000-4000-8000-${String(serial).padStart(12, '0')}`,
    itemId: 1050000000000 + serial,
    typeId: 34,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 10,
    locationId: station.locationId,
    locationName: station.name,
    locationType: 'station',
    locationFlag: 'Hangar',
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: station.system,
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    cachedAt: '2026-11-13T10:00:00+00:00',
    ...overrides,
  }
}

function inventoryOf(rowsPerStation: number[]) {
  return rowsPerStation.flatMap((count, stationIndex) =>
    Array.from({ length: count }, (_, rowIndex) => rowAt(stationIndex, rowIndex)),
  )
}

const inventoryAboveThreshold = inventoryOf([100, 60, COLLAPSE_THRESHOLD - 160 + 1])
const inventoryAtThreshold = inventoryOf([100, 60, COLLAPSE_THRESHOLD - 160])

function completedSync(): SyncProgress {
  return { syncType: 'character-assets', status: 'completed', progress: null, message: null, data: null, timestamp: '2026-11-13T10:05:00+00:00' }
}

async function mountAssets(responses: unknown[][]) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-0000000000u1',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [{
      id: CHARACTER_ID,
      eveCharacterId: 2112000001,
      name: 'Pilot A',
      corporationId: 98000001,
      corporationName: 'Test Corp',
      allianceId: null,
      allianceName: null,
      isMain: true,
      hasValidToken: true,
      lastSyncAt: null,
    }],
    corporationId: 98000001,
    corporationName: 'Test Corp',
  } as User
  let call = 0
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    if (String(input) !== ENDPOINT) {
      return new Response('{}', { status: 404 })
    }
    const items = responses[Math.min(call, responses.length - 1)]
    call++
    return new Response(JSON.stringify({ items }), { status: 200 })
  }))
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
  return { wrapper, syncStore: useSyncStore() }
}

type Mounted = Awaited<ReturnType<typeof mountAssets>>

function locationHeaders(wrapper: Mounted['wrapper']) {
  return wrapper.findAll('[aria-expanded][role="button"]').filter(node => node.find('h3').exists())
}

function headerOfStation(wrapper: Mounted['wrapper'], stationIndex: number) {
  const header = locationHeaders(wrapper).find(candidate => candidate.text().includes(STATIONS[stationIndex].name))
  if (!header) {
    throw new Error('station header not rendered')
  }
  return header
}

function expandedStates(wrapper: Mounted['wrapper']): Record<string, string | undefined> {
  return Object.fromEntries(STATIONS.map((station, index) => [station.system, headerOfStation(wrapper, index).attributes('aria-expanded')]))
}

function jitaGroup(wrapper: Mounted['wrapper']) {
  const group = wrapper.findAllComponents(AssetLocationGroup).find(candidate => candidate.props('location').solarSystemName === 'Jita')
  if (!group) {
    throw new Error('Jita group not rendered')
  }
  return group
}

describe('Assets lazy rendering of large inventories', () => {
  it('starts every location collapsed and mounts no row when the inventory holds more than the threshold', async () => {
    const { wrapper } = await mountAssets([inventoryAboveThreshold])

    expect(locationHeaders(wrapper)).toHaveLength(STATIONS.length)
    expect(expandedStates(wrapper)).toEqual({ Jita: 'false', Amarr: 'false', Dodixie: 'false' })
    expect(wrapper.findAll('img')).toHaveLength(0)
  })

  it('renders only the rows of the location the user expands', async () => {
    const { wrapper } = await mountAssets([inventoryAboveThreshold])

    await headerOfStation(wrapper, 0).trigger('click')

    expect(expandedStates(wrapper)).toEqual({ Jita: 'true', Amarr: 'false', Dodixie: 'false' })
    expect(wrapper.findAll('img')).toHaveLength(100)
  })

  it('keeps every location expanded when the inventory holds exactly the threshold', async () => {
    const { wrapper } = await mountAssets([inventoryAtThreshold])

    expect(expandedStates(wrapper)).toEqual({ Jita: 'true', Amarr: 'true', Dodixie: 'true' })
    expect(wrapper.findAll('img')).toHaveLength(COLLAPSE_THRESHOLD)
  })

  it('keeps the location the user expanded when a reload brings new data', async () => {
    const { wrapper, syncStore } = await mountAssets([inventoryAboveThreshold])
    await headerOfStation(wrapper, 1).trigger('click')

    syncStore.syncStatus['character-assets'] = completedSync()
    await flushPromises()

    expect(expandedStates(wrapper)).toEqual({ Jita: 'false', Amarr: 'true', Dodixie: 'false' })
    expect(wrapper.findAll('img')).toHaveLength(60)
  })
})

describe('Assets row data', () => {
  it('hands plain, non-reactive rows to the location groups', async () => {
    const { wrapper } = await mountAssets([inventoryAtThreshold])

    const [firstRow] = wrapper.findComponent(AssetLocationGroup).props('location').assets

    expect(isReactive(firstRow)).toBe(false)
  })

  it('renames the resolved item and leaves the other rows as they were', async () => {
    const namedCandidate = rowAt(0, 0, { typeId: 587, typeName: 'Rifter', canResolveItemName: true })
    const { wrapper } = await mountAssets([[namedCandidate, rowAt(0, 1), rowAt(1, 0)]])
    const group = jitaGroup(wrapper)
    const untouchedRowBefore = group.props('location').assets[1]

    group.vm.$emit('item-name-resolved', namedCandidate.itemId, 'Old Faithful')
    await flushPromises()

    const rows = jitaGroup(wrapper).props('location').assets
    expect(rows[0]).toMatchObject({ itemName: 'Old Faithful', canResolveItemName: false })
    expect(rows[1]).toBe(untouchedRowBefore)
    expect(wrapper.text()).toContain('Old Faithful (Rifter)')
  })
})
