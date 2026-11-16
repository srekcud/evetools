import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const USER_ID = '00000000-0000-4000-8000-0000000000u1'
const CHARACTER_ID = '00000000-0000-4000-8000-0000000000c1'
const HOME_STATION = 'Home Station'
const MARKET_STATION = 'Jita IV - Moon 4'
const PRODUCTION_BOX_ITEM_ID = 3

function corpAsset(overrides: Record<string, unknown>) {
  return {
    categoryId: 4,
    locationId: 1035466617946,
    locationName: HOME_STATION,
    locationType: 'other',
    locationFlag: 'CorpSAG1',
    divisionName: 'Production',
    solarSystemId: 30004711,
    solarSystemName: 'Home System',
    itemName: null,
    cachedAt: '2026-11-12T10:00:00+00:00',
    ...overrides,
  }
}

const rawCorporationAssets = [
  corpAsset({ id: '00000000-0000-4000-8000-000000000001', itemId: 1, typeId: 34, typeName: 'Tritanium', quantity: 8800 }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000002', itemId: 2, typeId: 34, typeName: 'Tritanium', quantity: 200, locationFlag: 'CorpSAG2', divisionName: 'Stock' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000003', itemId: PRODUCTION_BOX_ITEM_ID, typeId: 3465, typeName: 'Large Secure Container', quantity: 1, itemName: 'Project Box' }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000004', itemId: 4, typeId: 4246, typeName: 'Hydrogen Fuel Block', quantity: 120, locationId: PRODUCTION_BOX_ITEM_ID, locationName: `Project Box - ${HOME_STATION}`, locationType: 'item', locationFlag: 'Unlocked', divisionName: null }),
  corpAsset({ id: '00000000-0000-4000-8000-000000000005', itemId: 5, typeId: 35, typeName: 'Pyerite', quantity: 50, locationId: 60003760, locationName: MARKET_STATION, locationType: 'station', solarSystemId: 30000142, solarSystemName: 'Jita', locationFlag: 'CorpSAG2', divisionName: 'Stock' }),
]

const rawCharacterAssets = [
  { id: '00000000-0000-4000-8000-000000000011', itemId: 11, typeId: 587, typeName: 'Rifter', quantity: 1, categoryId: 6, locationId: 1035466617946, locationName: HOME_STATION, locationType: 'other', locationFlag: 'Hangar', divisionName: null, solarSystemId: 30004711, solarSystemName: 'Home System', itemName: null, cachedAt: '2026-11-12T10:00:00+00:00' },
]

let fetchMock: ReturnType<typeof vi.fn>

async function mountAssets() {
  route.query = { view: 'corporation' }
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
  const corporationBrowse = assetBrowseFetch(rawCorporationAssets)
  const characterBrowse = assetBrowseFetch(rawCharacterAssets)
  fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/visibility')) {
      return new Response(JSON.stringify({ isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null }), { status: 200 })
    }
    return url.includes('/me/corporation/assets') ? corporationBrowse(input, init) : characterBrowse(input, init)
  })
  vi.stubGlobal('fetch', fetchMock)
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

function button(wrapper: Wrapper, label: string) {
  return wrapper.findAll('button').find(candidate => candidate.text().replace(/\s+/g, ' ').includes(label))
}

async function openTypeView(wrapper: Wrapper) {
  const toggle = button(wrapper, en.assets.groupBy.type)
  if (!toggle) {
    throw new Error('the By type toggle is not rendered in the corporation view')
  }
  await toggle.trigger('click')
  await flushPromises()
}

async function chooseDivision(wrapper: Wrapper, name: string) {
  const chip = wrapper.findAll('[data-testid="division-chip"]').find(candidate => candidate.text().includes(name))
  if (!chip) {
    throw new Error(`division chip "${name}" not rendered`)
  }
  await chip.trigger('click')
  await flushPromises()
}

function typeRows(wrapper: Wrapper) {
  return wrapper.findAll('[data-testid="asset-type-row"]')
}

function typeRowTexts(wrapper: Wrapper) {
  return typeRows(wrapper).map(row => row.text().replace(/\s+/g, ' '))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Assets corporation "By type" view', () => {
  it('offers the By location and By type toggle in the corporation view', async () => {
    const wrapper = await mountAssets()

    expect(button(wrapper, en.assets.groupBy.location)).toBeDefined()
    expect(button(wrapper, en.assets.groupBy.type)).toBeDefined()
  })

  it('groups the corporation items by type across divisions, containers included', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    const rows = typeRowTexts(wrapper)
    expect(rows).toHaveLength(4)
    expect(rows[0]).toContain('Tritanium')
    expect(rows[0]).toContain('9,000')
    expect(rows.some(row => row.includes('Hydrogen Fuel Block') && row.includes('120'))).toBe(true)
  })

  it('loads the corporation list and never the character list', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    const urls = fetchMock.mock.calls.map(call => String(call[0]))
    expect(urls.some(url => url.endsWith('/me/corporation/assets'))).toBe(true)
    expect(urls.some(url => url.includes('/characters/'))).toBe(false)
  })

  it('counts only the selected division, with the content of its containers', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await chooseDivision(wrapper, 'Stock')

    const rows = typeRowTexts(wrapper)
    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('Tritanium')
    expect(rows[0]).toContain('200')
    expect(rows[0]).not.toContain('9,000')
    expect(rows.some(row => row.includes('Pyerite') && row.includes('50'))).toBe(true)
    expect(rows.some(row => row.includes('Hydrogen Fuel Block'))).toBe(false)
  })

  it('counts the content of a container of the selected division', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await chooseDivision(wrapper, 'Production')

    const rows = typeRowTexts(wrapper)
    expect(rows.some(row => row.includes('Hydrogen Fuel Block') && row.includes('120'))).toBe(true)
    expect(rows.some(row => row.includes('Tritanium') && row.includes('8,800'))).toBe(true)
    expect(rows.some(row => row.includes('Pyerite'))).toBe(false)
  })

  it('sorts by name like the character view', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await button(wrapper, en.assets.byType.sortName)?.trigger('click')

    const names = typeRowTexts(wrapper).map(row => ['Hydrogen Fuel Block', 'Large Secure Container', 'Pyerite', 'Tritanium'].find(name => row.includes(name)))
    expect(names).toEqual(['Hydrogen Fuel Block', 'Large Secure Container', 'Pyerite', 'Tritanium'])
  })

  it('keeps the character view free of the corporation items when coming back', async () => {
    const wrapper = await mountAssets()
    await openTypeView(wrapper)

    await button(wrapper, en.assets.characterAssets)?.trigger('click')
    await flushPromises()
    await openTypeView(wrapper)

    const rows = typeRowTexts(wrapper)
    expect(rows.some(row => row.includes('Rifter'))).toBe(true)
    expect(rows.some(row => row.includes('Tritanium'))).toBe(false)
  })
})
