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

const USER_ID = '00000000-0000-4000-8000-0000000000u1'
const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const HOME_STATION = 'Home Station'
const MARKET_STATION = 'Jita IV - Moon 4'
const CACHED_AT = '2026-11-12T10:00:00+00:00'

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
    isSingleton: null,
    canResolveItemName: false,
    cachedAt: CACHED_AT,
  }
}

const rawAssets = [
  row(1, 34, 'Tritanium', 8800, HOME_STATION),
  row(2, 34, 'Tritanium', 1200, MARKET_STATION),
  row(3, 35, 'Pyerite', 500, HOME_STATION),
]

async function mountAssets() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: USER_ID,
    authStatus: 'valid',
    mainCharacter: null,
    characters: [{
      id: CHARACTER_ID,
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
  vi.stubGlobal('fetch', vi.fn(assetBrowseFetch(rawAssets, url => (
    url.endsWith('/visibility')
      ? new Response(JSON.stringify({ isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null }), { status: 200 })
      : null
  ))))
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

function groupingButton(wrapper: Wrapper, label: string) {
  return wrapper.findAll('button').find(button => button.text() === label)
}

async function openTypeView(wrapper: Wrapper) {
  const toggle = groupingButton(wrapper, en.assets.groupBy.type)
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
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Assets "By type" view', () => {
  it('offers the By location and By type toggle in the character view', async () => {
    const wrapper = await mountAssets()

    expect(groupingButton(wrapper, en.assets.groupBy.location)).toBeDefined()
    expect(groupingButton(wrapper, en.assets.groupBy.type)).toBeDefined()
  })

  it('shows the location groups until By type is chosen', async () => {
    const wrapper = await mountAssets()

    expect(typeRows(wrapper)).toHaveLength(0)
    expect(wrapper.text()).toContain(HOME_STATION)
  })

  it('shows one row per type with its total quantity and location count', async () => {
    const wrapper = await mountAssets()

    await openTypeView(wrapper)

    const rows = typeRows(wrapper)
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Tritanium')
    expect(rows[0].text()).toContain('10,000')
    expect(rows[0].text()).toContain('2 locations')
    expect(rows[1].text()).toContain('Pyerite')
    expect(rows[1].text()).toContain('1 location')
  })

  it('shows the per-location quantities when a row is expanded', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await typeRows(wrapper)[0].get('button').trigger('click')

    const breakdown = wrapper.get('[data-testid="asset-type-locations"]').text()
    expect(breakdown).toContain(HOME_STATION)
    expect(breakdown).toContain('8,800')
    expect(breakdown).toContain(MARKET_STATION)
    expect(breakdown).toContain('1,200')
  })

  it('applies the search to the type view', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await wrapper.get(`input[aria-label="${en.assets.searchPlaceholder}"]`).setValue('pyer')

    const rows = typeRows(wrapper)
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('Pyerite')
  })

  it('applies the location filter to the quantities of the type view', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    const locationSelect = wrapper.findAll('select').find(select => select.attributes('aria-label') === en.assets.location)
    await locationSelect?.setValue(MARKET_STATION)

    const rows = typeRows(wrapper)
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('Tritanium')
    expect(rows[0].text()).toContain('1,200')
    expect(rows[0].text()).toContain('1 location')
  })

  it('sorts the type view by name when the name header is chosen', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await groupingButton(wrapper, en.assets.byType.sortName)?.trigger('click')

    expect(typeRows(wrapper).map(rowWrapper => rowWrapper.text().includes('Pyerite'))).toEqual([true, false])
  })

  it('does not offer the toggle in the corporation view', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await groupingButton(wrapper, en.assets.corporationAssets)?.trigger('click')
    await flushPromises()

    expect(groupingButton(wrapper, en.assets.groupBy.type)).toBeUndefined()
    expect(typeRows(wrapper)).toHaveLength(0)
  })
})
