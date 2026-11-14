import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const HOME_STATION = 'Home Station'
const MARKET_STATION = 'Jita IV - Moon 4'
const TRITANIUM = 34
const PYERITE = 35

function row(itemId: number, typeId: number, typeName: string, quantity: number, locationName: string) {
  return {
    id: `00000000-0000-4000-8000-${String(itemId).padStart(12, '0')}`,
    itemId,
    typeId,
    typeName,
    quantity,
    categoryId: 4,
    locationId: locationName === HOME_STATION ? 1035466617946 : 60003760,
    locationName,
    locationType: 'station',
    locationFlag: 'Hangar',
    divisionName: null,
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: false,
    canResolveItemName: false,
    cachedAt: '2026-11-12T10:00:00+00:00',
  }
}

const rawAssets = [
  row(1, TRITANIUM, 'Tritanium', 8800, HOME_STATION),
  row(2, TRITANIUM, 'Tritanium', 1200, MARKET_STATION),
  row(3, PYERITE, 'Pyerite', 500, HOME_STATION),
]

const volumesAnswer = {
  id: 'packaged-volumes',
  volumes: { [TRITANIUM]: 0.01, [PYERITE]: 0.5 },
  excludedTypeIds: [17366, 17367, 17368],
  refreshedAt: '2026-11-10T04:00:00+00:00',
}

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
      id: '00000000-0000-4000-8000-0000000000c1',
      eveCharacterId: 2112000001,
      name: 'Main Pilot',
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
  vi.stubGlobal('fetch', vi.fn(assetBrowseFetch(rawAssets, url => (url.includes('/me/assets/packaged-volumes') ? volumesResponse() : null))))
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

type Wrapper = Awaited<ReturnType<typeof mountAssets>>

async function openTypeView(wrapper: Wrapper) {
  const toggle = wrapper.findAll('button').find(button => button.text() === en.assets.groupBy.type)
  if (!toggle) {
    throw new Error('the By type toggle is not rendered')
  }
  await toggle.trigger('click')
  await flushPromises()
}

function typeRows(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="asset-type-row"]')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  volumesResponse = () => new Response(JSON.stringify(volumesAnswer), { status: 200 })
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Assets By type volume', () => {
  it('shows the packaged volume of each type from the full quantity', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    const volumes = typeRows(wrapper).map(typeRow => typeRow.get('[data-testid="type-volume"]').text())
    expect(volumes).toEqual(['100 m³', '250 m³'])
  })

  it('sorts the type rows by volume', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    const volumeSort = wrapper.findAll('button').find(button => button.text().startsWith('Volume'))
    await volumeSort?.trigger('click')

    expect(typeRows(wrapper).map(typeRow => typeRow.text().includes('Pyerite'))).toEqual([true, false])
  })

  it('shows no volume when the catalogue is unavailable', async () => {
    volumesResponse = () => new Response('{}', { status: 503 })
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    expect(typeRows(wrapper)).toHaveLength(2)
    expect(wrapper.find('[data-testid="type-volume"]').exists()).toBe(false)
  })
})
