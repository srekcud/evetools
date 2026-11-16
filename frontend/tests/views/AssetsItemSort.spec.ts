import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const ALPHA = 'Alpha Station'
const BRAVO = 'Bravo Station'
const CHARLIE = 'Charlie Station'
const DELTA = 'Delta Station'
const RIFTER = 587
const TRITANIUM = 34
const CARGO_CONTAINER = 3296
const STATION_WAREHOUSE_CONTAINER = 17368
const UNLISTED_TYPE = 999001
const BOX_ITEM_ID = 1050000000080
const WAREHOUSE_ITEM_ID = 1050000000090

function asset(id: number, overrides: Record<string, unknown>) {
  return {
    id: `00000000-0000-4000-8000-${String(id).padStart(12, '0')}`,
    itemId: 1050000000000 + id,
    typeId: TRITANIUM,
    typeName: 'Tritanium',
    categoryId: 4,
    quantity: 100,
    locationId: 60003760,
    locationType: 'station',
    locationFlag: 'Hangar',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

const rawAssets = [
  asset(1, { locationName: ALPHA, locationId: 60000001, typeId: RIFTER, typeName: 'Rifter', categoryId: 6, quantity: 3, isSingleton: false }),
  asset(2, { locationName: BRAVO, locationId: 60000002, quantity: 1000 }),
  asset(80, { itemId: BOX_ITEM_ID, locationName: BRAVO, locationId: 60000002, typeId: CARGO_CONTAINER, typeName: 'Cargo Container', quantity: 1, isSingleton: true }),
  asset(81, { locationName: BRAVO, locationId: BOX_ITEM_ID, locationType: 'item', typeId: RIFTER, typeName: 'Rifter', categoryId: 6, quantity: 2 }),
  asset(90, { itemId: WAREHOUSE_ITEM_ID, locationName: CHARLIE, locationId: 60000003, typeId: STATION_WAREHOUSE_CONTAINER, typeName: 'Station Warehouse Container', quantity: 1, isSingleton: true }),
  asset(91, { locationName: CHARLIE, locationId: WAREHOUSE_ITEM_ID, locationType: 'item', quantity: 100 }),
  asset(92, { locationName: DELTA, locationId: 60000004, typeId: UNLISTED_TYPE, typeName: 'Mystery Crate', quantity: 4 }),
]

const volumesAnswer = {
  id: 'packaged-volumes',
  volumes: { [RIFTER]: 2500, [TRITANIUM]: 0.01, [CARGO_CONTAINER]: 100, [STATION_WAREHOUSE_CONTAINER]: 10000000 },
  excludedTypeIds: [17366, 17367, 17368],
  refreshedAt: '2026-11-10T04:00:00+00:00',
}

const CATALOG_ENDPOINT = '/me/assets/packaged-volumes'
let volumesResponse: () => Response

async function mountAssets() {
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
      name: 'Test Pilot',
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
  vi.stubGlobal('fetch', vi.fn(assetBrowseFetch(rawAssets, url => (url.includes(CATALOG_ENDPOINT) ? volumesResponse() : null))))
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PatrimoineModuleTabs: true,
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

type MountedAssets = Awaited<ReturnType<typeof mountAssets>>

function itemSortValues(wrapper: MountedAssets): string[] {
  return wrapper.findAll('[data-testid="item-sort"] option').map(option => option.attributes('value') ?? '')
}

async function openBravo(wrapper: MountedAssets): Promise<void> {
  const group = wrapper.findAll('[data-testid="location-group"]').find(candidate => candidate.text().includes(BRAVO))
  await group?.get('[aria-expanded]').trigger('click')
  await flushPromises()
}

function bravoItemIds(wrapper: MountedAssets): number[] {
  const group = wrapper.findAll('[data-testid="location-group"]').find(candidate => candidate.text().includes(BRAVO))
  return (group?.findAll('[data-asset-item-id]') ?? []).map(row => Number(row.attributes('data-asset-item-id')))
}

describe('Assets item sort selector', () => {
  beforeEach(() => {
    volumesResponse = () => new Response(JSON.stringify(volumesAnswer), { status: 200 })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers the name, quantity and volume sorts once the volumes are loaded', async () => {
    const wrapper = await mountAssets()

    expect(itemSortValues(wrapper)).toEqual(['name', 'quantity', 'volume'])
    expect((wrapper.get('[data-testid="item-sort"]').element as HTMLSelectElement).value).toBe('name')
  })

  it('does not offer the volume sort when the volumes are not loaded', async () => {
    volumesResponse = () => new Response(JSON.stringify({ ...volumesAnswer, volumes: [], refreshedAt: null }), { status: 200 })

    const wrapper = await mountAssets()

    expect(itemSortValues(wrapper)).toEqual(['name', 'quantity'])
  })

  it('reorders the items of an open location when the sort changes', async () => {
    const wrapper = await mountAssets()
    await openBravo(wrapper)

    expect(bravoItemIds(wrapper)).toEqual([BOX_ITEM_ID, 1050000000002])

    await wrapper.get('[data-testid="item-sort"]').setValue('quantity')
    await flushPromises()

    expect(bravoItemIds(wrapper)).toEqual([1050000000002, BOX_ITEM_ID])
  })

  it('keeps the sort of the locations unchanged', async () => {
    const wrapper = await mountAssets()

    expect((wrapper.get('[data-testid="location-sort"]').element as HTMLSelectElement).value).toBe('items')
  })
})
