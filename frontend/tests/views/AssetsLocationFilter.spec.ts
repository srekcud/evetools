import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const HOME_STATION = '0UBC-R - Yoko\'s Perfect Alignment'
const MARKET_STATION = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const SHIP_ITEM_ID = 1050000000501
const CARGO_CONTAINER_ITEM_ID = 1050000000502

function stationAsset(overrides: Record<string, unknown>) {
  return {
    categoryId: 4,
    locationId: 1035466617946,
    locationName: HOME_STATION,
    locationType: 'other',
    locationFlag: 'Hangar',
    solarSystemId: 30004711,
    solarSystemName: '0UBC-R',
    itemName: null,
    cachedAt: '2026-11-12T10:00:00+00:00',
    ...overrides,
  }
}

function storedIn(containerItemId: number, containerName: string, overrides: Record<string, unknown>) {
  return stationAsset({
    locationId: containerItemId,
    locationName: `${containerName} — ${HOME_STATION}`,
    locationType: 'item',
    locationFlag: 'Cargo',
    ...overrides,
  })
}

const rawAssets = [
  stationAsset({ id: '00000000-0000-4000-8000-000000000001', itemId: SHIP_ITEM_ID, typeId: 587, typeName: 'Rifter', quantity: 1, categoryId: 6, itemName: 'Appa' }),
  storedIn(SHIP_ITEM_ID, 'Appa', { id: '00000000-0000-4000-8000-000000000002', itemId: CARGO_CONTAINER_ITEM_ID, typeId: 3465, typeName: 'Large Secure Container', quantity: 1, itemName: 'BPC' }),
  storedIn(CARGO_CONTAINER_ITEM_ID, 'BPC', { id: '00000000-0000-4000-8000-000000000003', itemId: 1050000000503, typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 120 }),
  stationAsset({ id: '00000000-0000-4000-8000-000000000004', itemId: 1050000000504, typeId: 34, typeName: 'Tritanium', quantity: 8800, locationId: 60003760, locationName: MARKET_STATION, locationType: 'station', solarSystemId: 30000142, solarSystemName: 'Jita' }),
]

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
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ items: rawAssets }), { status: 200 })))
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr } })
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function locationSelect(wrapper: Awaited<ReturnType<typeof mountAssets>>) {
  const select = wrapper.findAll('select').find(candidate => candidate.attributes('aria-label') === fr.assets.location)
  if (!select) {
    throw new Error('location filter not rendered')
  }
  return select
}

describe('Assets location filter', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers only the stations and structures, never a ship or a container', async () => {
    const wrapper = await mountAssets()

    const options = locationSelect(wrapper).findAll('option').slice(1).map(option => option.text())

    expect(options).toEqual([HOME_STATION, MARKET_STATION])
  })

  it('keeps the ships and their contents when their station is selected', async () => {
    const wrapper = await mountAssets()

    await locationSelect(wrapper).setValue(HOME_STATION)
    await flushPromises()
    const text = wrapper.text().replace(/\s+/g, ' ')

    expect(text).toContain('Appa (Rifter)')
    expect(text).not.toContain('Tritanium')
  })
})
